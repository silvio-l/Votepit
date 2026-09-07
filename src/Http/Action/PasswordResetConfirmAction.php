<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\TokenVault;

/**
 * POST /password/reset/confirm — step B of "forgot password" (AuthZ: anon,
 * the single-use token IS the capability — same model as /login/verify and
 * /invite/accept). Body: { token, new_password, new_password_confirmation }.
 *
 * Deliberately NO email-gateway-prescan grace window (unlike
 * LoginVerifyAction/findRecentlyUsedByHash): that compensation exists
 * because a magic link is consumed by a bare GET a scanner can prefetch.
 * The reset token here is only ever consumed by this POST, submitted from
 * the SPA's reset-confirm form after the user has typed a new password — a
 * mail-security gateway that merely GETs the link (loading the SPA shell)
 * never submits this POST, so the token is never silently burned before the
 * real user gets to it.
 *
 * On success: hashes + stores the new password (same PASSWORD_ARGON2ID
 * pattern as SetPasswordAction), marks the token used (single-use), and
 * bumps token_version — invalidating every other active session for this
 * user, exactly like POST /logout. This is the intended cross-session
 * invalidation: a password reset is a strong signal the previous
 * credential/session set may be compromised.
 */
final readonly class PasswordResetConfirmAction
{
    private const MIN_LENGTH = 10;

    public function __construct(
        private UserRepository $userRepo,
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed       = $request->getParsedBody();
        $token        = is_array($parsed) ? (string) ($parsed['token'] ?? '') : '';
        $newPassword  = is_array($parsed) ? (string) ($parsed['new_password'] ?? '') : '';
        $confirmation = is_array($parsed) ? (string) ($parsed['new_password_confirmation'] ?? '') : '';

        $row = $token !== '' ? $this->tokenRepo->findActiveByHash($this->vault->hash($token), 'password_reset') : null;

        // Constant-shape failure: invalid/expired/wrong-purpose/malformed
        // token all collapse into the same generic error, no side effect —
        // same granularity as LoginVerifyAction's invalid_token response.
        if (!is_array($row) || !$this->vault->verify($token, (string) $row['token_hash'])) {
            $this->audit->log('password.reset_failed', ['reason' => 'invalid_token']);
            return $this->error($response, 400, 'invalid_token', 'The link is invalid or has expired.');
        }

        $userId = (int) $row['user_id'];

        if ($newPassword !== $confirmation) {
            $this->audit->log('password.reset_failed', ['uid' => $userId, 'reason' => 'password_mismatch']);
            return $this->error($response, 400, 'password_mismatch', 'The passwords do not match.');
        }

        if (mb_strlen($newPassword) < self::MIN_LENGTH) {
            $this->audit->log('password.reset_failed', ['uid' => $userId, 'reason' => 'weak_password']);
            return $this->error(
                $response,
                400,
                'weak_password',
                sprintf('The password must be at least %d characters long.', self::MIN_LENGTH),
            );
        }

        $tokenId = (int) $row['id'];
        $hash    = password_hash($newPassword, PASSWORD_ARGON2ID);

        $this->userRepo->setPasswordHash($userId, $hash);
        $this->tokenRepo->markUsed($tokenId);
        // Invalidates every other active session (same mechanism as
        // POST /logout) — a fresh password reset must not leave a
        // possibly-compromised session alive elsewhere.
        $this->userRepo->bumpTokenVersion($userId);

        $this->audit->log('password.reset_completed', ['uid' => $userId]);

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
