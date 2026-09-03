<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
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

        $row = $pendingToken !== '' ? $this->tokenRepo->findActiveByHash($this->vault->hash($pendingToken), '2fa_pending') : null;

        if (!is_array($row) || !$this->vault->verify($pendingToken, (string) $row['token_hash'])) {
            $this->audit->log('login_2fa.failed', []);
            return $this->error($response, 400, 'invalid_pending_token', 'The session is invalid or has expired.');
        }

        $userId = (int) $row['user_id'];
        $user   = $this->userRepo->findByIdWithCredentials($userId);

        $encryptedSecret = is_array($user) && is_string($user['totp_secret_encrypted'] ?? null)
            ? $user['totp_secret_encrypted']
            : null;

        if (!is_array($user) || $encryptedSecret === null) {
            $this->audit->log('login_2fa.failed', ['uid' => $userId]);
            return $this->error($response, 400, 'invalid_pending_token', 'The session is invalid or has expired.');
        }

        $verified = false;
        if ($code !== '') {
            $secret   = $this->encryption->decrypt($encryptedSecret);
            $verified = $secret !== null && $this->totp->verify($secret, $code);
        } elseif ($backupCode !== '') {
            $verified = $this->backupCodes->verifyAndConsume($userId, $backupCode);
        }

        if (!$verified) {
            $this->audit->log('login_2fa.failed', ['uid' => $userId]);
            return $this->error($response, 400, 'invalid_code', 'The code is invalid.');
        }

        $this->tokenRepo->markUsed((int) $row['id']);
        $this->audit->log('login_2fa.succeeded', ['uid' => $userId]);

        // Clear the IP's rate-limit bucket on success — otherwise a correct code
        // that lands shortly after a handful of typos/retries still eats the
        // remaining budget and gets 429'd for no reason (same bucket as the
        // RateLimitMiddleware on this route, see AppFactory).
        $ip = ClientIp::resolve($request, $this->trustCloudflareIp);
        if ($ip !== null) {
            $this->rateLimiter->reset('ip:' . $ip);
        }

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
