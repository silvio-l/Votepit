<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\TokenVault;

/**
 * GET /login/verify?token=<plaintext> — verifies the magic link and
 * issues a fresh session (AuthZ: anon, GET → CSRF-exempt:
 * the one-time token itself is the capability). On failure NO
 * side effect, uniform 4xx JSON error response.
 *
 * Self-host bootstrap continuity: when a user is promoted to platform admin
 * via admin_emails (is_admin), they ADDITIONALLY get the owner role
 * in the current account (account_members) — otherwise an existing
 * operator would lose access to /admin/boards/* after the upgrade (AuthZMiddleware::
 * accountAdmin() only checks account_members, not is_admin).
 */
final readonly class LoginVerifyAction
{
    /**
     * Grace window for mail security gateway prescanning (see LoginTokenRepository::
     * findRecentlyUsedByHash) — deliberately short, only to catch a gateway prescan a
     * few seconds before the real click, not to make "one-time" tokens actually
     * reusable.
     */
    private const REPLAY_GRACE_SECONDS = 120;

    /** TTL of the pending-2FA token (login_tokens, purpose='2fa_pending') — see LoginPasswordAction. */
    private const PENDING_2FA_TTL_SECONDS = 300;

    public function __construct(
        private UserRepository $userRepo,
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private AuditLogger $audit,
        private Config $config,
        private LoginSessionIssuer $sessionIssuer,
        private Connection $conn,
        private AccountMemberRepository $accountMembers,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params   = $request->getQueryParams();
        $token    = is_string($params['token'] ?? null) ? $params['token'] : '';
        $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
        $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '/';

        $row = $token !== '' ? $this->tokenRepo->findActiveByHash($this->vault->hash($token)) : null;

        // No active token left? Before the final failure, check a short
        // grace window (mail security gateway prescanning compensation,
        // see class constant + LoginTokenRepository::findRecentlyUsedByHash).
        // Everything below stays idempotent, so a replay here is harmless.
        if (!is_array($row) && $token !== '') {
            $row = $this->tokenRepo->findRecentlyUsedByHash($this->vault->hash($token), self::REPLAY_GRACE_SECONDS);
        }

        // Constant-time confirmation; failure → no mutation.
        if (!is_array($row) || !$this->vault->verify($token, (string) $row['token_hash'])) {
            $this->audit->log('magic_link.verify_failed', []);
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'invalid_token',
                    'message' => 'The link is invalid or has expired.',
                ],
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $userId    = (int) $row['user_id'];
        $tokenId   = (int) $row['id'];
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $isAdminML = false;

        // Atomic: consume token + verified_at + admin promotion.
        /** @var array<string, mixed> $user */
        $user = $this->conn->transactional(
            function () use ($tokenId, $userId, $accountId, &$isAdminML): array {
                $this->tokenRepo->markUsed($tokenId);
                $this->userRepo->markVerified($userId);

                $loaded = $this->userRepo->findByIdWithCredentials($userId);
                if (!is_array($loaded)) {
                    // Should not happen inside a transaction → abort fail-secure.
                    throw new \RuntimeException('verify: user not found after markVerified');
                }

                if ($this->config->isAdminEmailHmac((string) $loaded['email_hmac'])) {
                    $this->userRepo->promoteAdmin($userId);
                    $loaded['is_admin'] = 1;
                    $isAdminML          = true;

                    // Self-host bootstrap continuity: the platform admin
                    // additionally becomes owner of the current account, otherwise
                    // they lose access to /admin/boards/* after the upgrade (accountAdmin()).
                    $this->accountMembers->addMember($accountId, $userId, 'owner');
                }

                return $loaded;
            },
        );

        $this->audit->log('magic_link.verified', ['email_hmac' => $user['email_hmac']]);
        if ($isAdminML) {
            $this->audit->log('admin.promoted', ['email_hmac' => $user['email_hmac']]);
        }

        // 2FA gate (security review 2026-09): a compromised mailbox alone must
        // NOT be enough to obtain a session once TOTP is enabled — otherwise
        // 2FA would be security theater. Same pending-2FA handoff as
        // LoginPasswordAction (login_tokens, purpose='2fa_pending'); the real
        // session is issued only after POST /login/2fa succeeds.
        if (is_string($user['totp_enabled_at'] ?? null)) {
            $this->tokenRepo->deleteOpenForUser($userId);
            $pair      = $this->vault->generate();
            $expiresAt = (new \DateTimeImmutable('+' . self::PENDING_2FA_TTL_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
            $this->tokenRepo->insertPending($userId, $pair['hash'], $expiresAt);

            $this->audit->log('magic_link.pending_2fa', ['uid' => $userId]);

            $response->getBody()->write((string) json_encode([
                'requires_2fa'  => true,
                'pending_token' => $pair['token'],
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // Fresh session — any pre-login cookie is ignored/replaced
        // (session-fixation protection). Returning user, no (or invalid) explicit
        // `r` param — bare '/' is a cloud-mode 404 (scoped routes are all
        // /{account}-prefixed, see App.tsx); LoginSessionIssuer sends them to
        // their own account's admin dashboard instead (same fallback used by
        // LoginPasswordAction/Login2faAction — "one account per signup" means
        // at most one membership exists here in practice).
        return $this->sessionIssuer->issue($response, $userId, (int) ($user['token_version'] ?? 0), $returnTo);
    }
}
