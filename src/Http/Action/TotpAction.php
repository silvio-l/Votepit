<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\Totp;
use Votepit\Security\TotpSetupToken;

/**
 * POST /account/totp/setup                    — begin TOTP enrollment.
 * POST /account/totp/confirm                   — confirm + activate TOTP.
 * POST /account/totp/disable                   — turn TOTP off.
 * POST /account/totp/backup-codes/regenerate   — invalidate + reissue backup codes.
 *
 * AuthZ: AuthZMiddleware::user() on every route (see AppFactory wiring) — any
 * logged-in user may self-configure 2FA, no special role required (CLAUDE.md
 * scope note: this is deliberately not role-gated).
 */
final readonly class TotpAction
{
    public function __construct(
        private UserRepository $userRepo,
        private TotpBackupCodeRepository $backupCodes,
        private Totp $totp,
        private TotpSetupToken $setupToken,
        private EncryptionService $encryption,
        private AuditLogger $audit,
    ) {}

    /**
     * Generates a fresh secret and hands it back to the client as a signed
     * blob (TotpSetupToken) — the secret is NEVER persisted at this point
     * (no half-activated 2FA sitting in the DB). The client's confirm call
     * must echo the blob back unchanged.
     */
    public function setup(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->requireUserId($request);
        $secret = $this->totp->generateSecret();
        $blob   = $this->setupToken->sign($userId, $secret);

        $response->getBody()->write((string) json_encode([
            'ok'                => true,
            'secret'            => $secret,
            'setup_token'       => $blob,
            'provisioning_uri'  => $this->totp->provisioningUri($secret, 'Account #' . $userId),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Verifies the 6-digit code against the setup-token's secret; on success
     * persists the encrypted secret, sets totp_enabled_at, and issues 10
     * fresh backup codes — returned in plaintext ONLY in this response.
     */
    public function confirm(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->requireUserId($request);

        $parsed     = $request->getParsedBody();
        $setupBlob  = is_array($parsed) ? (string) ($parsed['setup_token'] ?? '') : '';
        $code       = is_array($parsed) ? (string) ($parsed['code'] ?? '') : '';

        $secret = $setupBlob !== '' ? $this->setupToken->verify($setupBlob, $userId) : null;
        if ($secret === null || !$this->totp->verify($secret, $code)) {
            $this->audit->log('totp.confirm_failed', ['uid' => $userId]);
            return $this->error($response, 400, 'invalid_code', 'The code is invalid or has expired.');
        }

        $this->userRepo->enableTotp($userId, $this->encryption->encrypt($secret));
        $plaintextCodes = $this->backupCodes->regenerate($userId);
        $this->audit->log('totp.enabled', ['uid' => $userId]);

        $response->getBody()->write((string) json_encode([
            'ok'           => true,
            'backup_codes' => $plaintextCodes,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Requires the current password (if one is set) OR a valid TOTP code as
     * confirmation before turning 2FA off — mirrors LoginPasswordAction's
     * timing-safe "no password set" handling.
     */
    public function disable(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->requireUserId($request);
        $user   = $this->userRepo->findByIdWithCredentials($userId);
        if (!is_array($user) || !is_string($user['totp_enabled_at'] ?? null)) {
            return $this->error($response, 400, 'totp_not_enabled', '2FA is not enabled.');
        }

        if (!$this->confirmedByPasswordOrCode($request, $user)) {
            $this->audit->log('totp.disable_failed', ['uid' => $userId]);
            return $this->error($response, 400, 'confirmation_failed', 'Confirmation failed.');
        }

        $this->userRepo->disableTotp($userId);
        $this->backupCodes->deleteAllForUser($userId);
        $this->audit->log('totp.disabled', ['uid' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** Invalidates all existing backup codes and issues 10 fresh ones. */
    public function regenerateBackupCodes(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $userId = $this->requireUserId($request);
        $user   = $this->userRepo->findByIdWithCredentials($userId);
        if (!is_array($user) || !is_string($user['totp_enabled_at'] ?? null)) {
            return $this->error($response, 400, 'totp_not_enabled', '2FA is not enabled.');
        }

        if (!$this->confirmedByPasswordOrCode($request, $user)) {
            $this->audit->log('totp.backup_codes_regenerate_failed', ['uid' => $userId]);
            return $this->error($response, 400, 'confirmation_failed', 'Confirmation failed.');
        }

        $plaintextCodes = $this->backupCodes->regenerate($userId);
        $this->audit->log('totp.backup_codes_regenerated', ['uid' => $userId]);

        $response->getBody()->write((string) json_encode([
            'ok'           => true,
            'backup_codes' => $plaintextCodes,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $user
     */
    private function confirmedByPasswordOrCode(ServerRequestInterface $request, array $user): bool
    {
        $parsed  = $request->getParsedBody();
        $password = is_array($parsed) ? (string) ($parsed['current_password'] ?? '') : '';
        $code     = is_array($parsed) ? (string) ($parsed['code'] ?? '') : '';

        $passwordHash = is_string($user['password_hash'] ?? null) ? $user['password_hash'] : null;
        if ($passwordHash !== null && $password !== '' && password_verify($password, $passwordHash)) {
            return true;
        }

        $encryptedSecret = is_string($user['totp_secret_encrypted'] ?? null) ? $user['totp_secret_encrypted'] : null;
        if ($encryptedSecret !== null && $code !== '') {
            $secret = $this->encryption->decrypt($encryptedSecret);
            if ($secret !== null && $this->totp->verify($secret, $code)) {
                return true;
            }
        }

        return false;
    }

    private function requireUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $authUser */
        $authUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($authUser) ? (int) ($authUser['id'] ?? 0) : 0;
    }

    private function error(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
