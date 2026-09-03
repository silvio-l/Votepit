<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\TokenVault;

/**
 * POST /login/password — email + password login (AuthZ: anon). Additive
 * alternative to the magic-link flow, never a replacement — magic link stays
 * always available (CLAUDE.md scope note).
 *
 * Every failure path (unknown user, no password set, wrong password, blocked
 * user) returns the SAME generic error — no enumeration leak. A user with no
 * password set is checked against a fixed dummy Argon2id hash instead of
 * short-circuiting, so "does this user have a password?" isn't a timing
 * side-channel.
 *
 * On success WITHOUT TOTP enabled: issues a real session immediately (same
 * codepath as LoginVerifyAction, via LoginSessionIssuer). On success WITH
 * TOTP enabled: issues NO session — instead a short-lived pending-2FA token
 * (login_tokens, purpose='2fa_pending') that POST /login/2fa must redeem.
 */
final readonly class LoginPasswordAction
{
    /**
     * Fixed Argon2id hash of an arbitrary string — never a real password,
     * exists solely so password_verify() runs the same cost function whether
     * or not the looked-up user actually has a password_hash set.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QkJRUTdBOXVuVjJmMnBDbA$aNdLdem+ie+RQO2tWp9PtUqSOBlpzVqGBdL8IeowLn4';

    private const PENDING_TTL_SECONDS = 300; // 5 minutes

    public function __construct(
        private UserRepository $userRepo,
        private IdentityHasher $hasher,
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private LoginSessionIssuer $sessionIssuer,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed   = $request->getParsedBody();
        $email    = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';
        $password = is_array($parsed) ? (string) ($parsed['password'] ?? '') : '';
        $rawR     = is_array($parsed) ? (string) ($parsed['r'] ?? '') : '';
        $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '/';

        $emailHmac = $email !== '' ? $this->hasher->hash($email) : '';
        $user      = $emailHmac !== '' ? $this->userRepo->findByEmailHmacWithCredentials($emailHmac) : null;

        $storedHash = is_array($user) && is_string($user['password_hash'] ?? null) ? $user['password_hash'] : self::DUMMY_HASH;
        $verified   = password_verify($password, $storedHash);

        $isValid = is_array($user)
            && is_string($user['password_hash'] ?? null)
            && !(bool) ($user['is_blocked'] ?? false)
            && $verified;

        if (!$isValid) {
            $this->audit->log('password_login.failed', ['email_hmac' => $emailHmac !== '' ? $emailHmac : null]);
            return $this->error($response, 401, 'invalid_credentials', 'Email or password is incorrect.');
        }

        /** @var array<string, mixed> $user */
        $userId = (int) $user['id'];

        if (is_string($user['totp_enabled_at'] ?? null)) {
            $this->tokenRepo->deleteOpenForUser($userId);
            $pair      = $this->vault->generate();
            $expiresAt = (new \DateTimeImmutable('+' . self::PENDING_TTL_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
            $this->tokenRepo->insertPending($userId, $pair['hash'], $expiresAt);

            $this->audit->log('password_login.pending_2fa', ['uid' => $userId]);

            $response->getBody()->write((string) json_encode([
                'requires_2fa'  => true,
                'pending_token' => $pair['token'],
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $this->audit->log('password_login.succeeded', ['uid' => $userId]);

        return $this->sessionIssuer->issue($response, $userId, (int) $user['token_version'], $returnTo);
    }

    private function error(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
