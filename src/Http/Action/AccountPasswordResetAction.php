<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PasswordResetMailer;

/**
 * POST /account/password-reset — logged-in self-service "send me a reset
 * link" (AuthZ: user). Body: { email }.
 *
 * ADR 0002 stores email only as a one-way HMAC — even the logged-in user's
 * own plaintext address isn't retrievable from storage, so the mail-link
 * flow needs it re-typed as a confirmation step (mirrors the convention
 * InviteAction already uses for existing-account invites). This also
 * doubles as a lightweight "prove you still know your own address" check
 * before a credential-replacement link goes out.
 *
 * Not anti-enumeration (unlike PasswordResetRequestAction): the caller is
 * already authenticated as a specific account, so a mismatch is reported
 * directly as 422 rather than masked behind a generic {ok: true}.
 */
final readonly class AccountPasswordResetAction
{
    public function __construct(
        private UserRepository $userRepo,
        private IdentityHasher $hasher,
        private PasswordResetMailer $resetMailer,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        /** @var array<string, mixed>|null $authUser */
        $authUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId   = is_array($authUser) ? (int) ($authUser['id'] ?? 0) : 0;

        $user = $userId > 0 ? $this->userRepo->findById($userId) : null;
        if (!is_array($user)) {
            return $this->error($response, 400, 'invalid_request', 'Invalid request.');
        }

        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        if ($email === '' || $this->hasher->hash($email) !== $user['email_hmac']) {
            return $this->error($response, 422, 'email_mismatch', 'This does not match your account email.');
        }

        $this->resetMailer->send($userId, $email);
        $this->audit->log('password.reset_requested_self_service', ['uid' => $userId]);

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
