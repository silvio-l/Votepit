<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\AuditIpHasher;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\RateLimiter;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\TokenVault;
use Votepit\Security\Totp;

/**
 * POST /login/2fa — second step after LoginVerifyAction (magic link) or
 * LoginPasswordAction returned {requires_2fa: true, pending_token: "..."}.
 *
 * Body: pending_token (required) + EITHER code (6-digit TOTP) OR
 * backup_code. On success: consumes the pending token, issues a real session
 * (same codepath as the other two login flows, via LoginSessionIssuer). On
 * any failure: generic error, NO side effect (pending token stays valid
 * until its own TTL/other attempts).
 */
final readonly class Login2faAction
{
    public function __construct(
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private UserRepository $userRepo,
        private TotpBackupCodeRepository $backupCodes,
        private Totp $totp,
        private EncryptionService $encryption,
        private LoginSessionIssuer $sessionIssuer,
        private AuditLogger $audit,
        private AuditIpHasher $ipHasher,
        private RateLimiter $rateLimiter,
        private bool $trustCloudflareIp,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed       = $request->getParsedBody();
        $pendingToken = is_array($parsed) ? (string) ($parsed['pending_token'] ?? '') : '';
        $code         = is_array($parsed) ? (string) ($parsed['code'] ?? '') : '';
        $backupCode   = is_array($parsed) ? (string) ($parsed['backup_code'] ?? '') : '';
        $rawR         = is_array($parsed) ? (string) ($parsed['r'] ?? '') : '';
        $returnTo     = ReturnToValidator::isValid($rawR) ? $rawR : '/';
        $ip           = ClientIp::resolve($request, $this->trustCloudflareIp);
        $ipHmac       = $ip !== null ? $this->ipHasher->hash($ip) : null;

        $row = $pendingToken !== '' ? $this->tokenRepo->findActiveByHash($this->vault->hash($pendingToken), '2fa_pending') : null;

        if (!is_array($row) || !$this->vault->verify($pendingToken, (string) $row['token_hash'])) {
            $this->audit->log('login_2fa.failed', ['ip_hmac' => $ipHmac]);
            return $this->error($response, 400, 'invalid_pending_token', 'The session is invalid or has expired.');
        }

        $userId = (int) $row['user_id'];
        $user   = $this->userRepo->findByIdWithCredentials($userId);

        $encryptedSecret = is_array($user) && is_string($user['totp_secret_encrypted'] ?? null)
            ? $user['totp_secret_encrypted']
            : null;

        if (!is_array($user) || $encryptedSecret === null) {
            $this->audit->log('login_2fa.failed', ['uid' => $userId, 'ip_hmac' => $ipHmac]);
            return $this->error($response, 400, 'invalid_pending_token', 'The session is invalid or has expired.');
        }

        $verified = false;
        if ($code !== '') {
            $secret = $this->encryption->decrypt($encryptedSecret);
            $step   = $secret !== null ? $this->totp->verifyStep($secret, $code) : null;
            $lastStep = is_int($user['totp_last_step'] ?? null) ? $user['totp_last_step'] : null;

            if ($step !== null && ($lastStep === null || $step > $lastStep)) {
                $this->userRepo->updateTotpLastStep($userId, $step);
                $verified = true;
            }
        } elseif ($backupCode !== '') {
            $verified = $this->backupCodes->verifyAndConsume($userId, $backupCode);
        }

        if (!$verified) {
            $this->audit->log('login_2fa.failed', ['uid' => $userId, 'ip_hmac' => $ipHmac]);
            return $this->error($response, 400, 'invalid_code', 'The code is invalid.');
        }

        $this->tokenRepo->markUsed((int) $row['id']);
        $this->audit->log('login_2fa.succeeded', ['uid' => $userId, 'ip_hmac' => $ipHmac]);

        // Refund this request from the IP's rate-limit bucket on success, so
        // correct logins never use up the shared budget (same bucket as the
        // RateLimitMiddleware on this route, see AppFactory; own namespace
        // "login:2fa:ip:<addr>", independent of LoginPasswordAction's
        // "login:password:ip:<addr>" — review 2026-09-04, item 1). Only a
        // refund, never a reset: wrong codes sent earlier from this IP stay
        // counted, otherwise one valid account could keep refreshing the
        // IP's budget (Sentinel finding 2026-09-14).
        if ($ip !== null) {
            $this->rateLimiter->refund('login:2fa:ip:' . $ip);
        }

        // The second, per-pending-token rate-limit dimension (OWASP/BSI audit
        // remediation issue 05, see RateLimitMiddleware::perAction() on this
        // route in AppFactory) is scoped to this one pending login, so a full
        // reset is safe here — it stops IP rotation from brute-forcing one
        // pending login, but must not still throttle the SAME pending login
        // after its correct code finally lands.
        $this->rateLimiter->reset('login:2fa:token:' . $this->vault->hash($pendingToken));

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
