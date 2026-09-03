<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UserRepository;

/**
 * POST /account/password — sets or changes the current user's password
 * (AuthZ: user). Password login is additive to magic-link, never a
 * replacement — see CLAUDE.md scope note.
 *
 * First-time set: the active session already proves the user clicked a
 * magic-link, so no extra confirmation (of identity) is required. Changing an
 * EXISTING password requires the current one — otherwise a hijacked session
 * (e.g. an XSS or a shared device left logged in) could silently plant a
 * password backdoor the legitimate owner never chose.
 *
 * Both cases additionally require new_password_confirmation to match
 * new_password (typo protection) — server-side authoritative, mirrored by a
 * client-side check in SecuritySettings.tsx for immediate UX feedback.
 */
final readonly class SetPasswordAction
{
    private const MIN_LENGTH = 10;

    public function __construct(
        private UserRepository $userRepo,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        /** @var array<string, mixed>|null $authUser */
        $authUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId   = is_array($authUser) ? (int) ($authUser['id'] ?? 0) : 0;

        $user = $this->userRepo->findByIdWithCredentials($userId);
        if (!is_array($user)) {
            return $this->error($response, 400, 'invalid_request', 'Invalid request.');
        }

        $parsed              = $request->getParsedBody();
        $currentPassword     = is_array($parsed) ? (string) ($parsed['current_password'] ?? '') : '';
        $newPassword         = is_array($parsed) ? (string) ($parsed['new_password'] ?? '') : '';
        $newPasswordConfirm  = is_array($parsed) ? (string) ($parsed['new_password_confirmation'] ?? '') : '';

        $existingHash = is_string($user['password_hash'] ?? null) ? $user['password_hash'] : null;

        if ($existingHash !== null && ($currentPassword === '' || !password_verify($currentPassword, $existingHash))) {
            $this->audit->log('password.change_failed', ['uid' => $userId]);
            return $this->error($response, 400, 'invalid_current_password', 'The current password is incorrect.');
        }

        if ($newPassword !== $newPasswordConfirm) {
            return $this->error($response, 400, 'password_mismatch', 'The passwords do not match.');
        }

        if (mb_strlen($newPassword) < self::MIN_LENGTH) {
            return $this->error(
                $response,
                400,
                'weak_password',
                sprintf('The password must be at least %d characters long.', self::MIN_LENGTH),
            );
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $this->userRepo->setPasswordHash($userId, $hash);
        $this->audit->log($existingHash === null ? 'password.set' : 'password.changed', ['uid' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
