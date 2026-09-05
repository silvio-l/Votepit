<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Signed, short-lived blob for the TOTP setup intermediate step
 * (POST /account/totp/setup → POST /account/totp/confirm).
 *
 * The freshly generated secret is deliberately NOT persisted unconfirmed
 * (no half-activated 2FA in the DB) — instead, this blob binds
 * secret + user ID + expiry via HMAC-SHA256(app_key), analogous to the
 * cookie-signing scheme of SessionService/CsrfService. The client sends the
 * blob back unchanged on confirm; verify() rejects a tampered,
 * expired, or payload bound to a different user (fail-secure,
 * constant-time MAC comparison via hash_equals).
 */
final readonly class TotpSetupToken
{
    private const TTL_SECONDS = 600; // 10 minutes — enough for scan + code entry

    public function __construct(private string $appKey) {}

    public function sign(int $userId, string $secretBase32): string
    {
        $expiresAt = time() + self::TTL_SECONDS;
        $payload   = $userId . '|' . $secretBase32 . '|' . $expiresAt;
        $body      = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $mac       = rtrim(strtr(base64_encode($this->mac($body)), '+/', '-_'), '=');

        return $body . '.' . $mac;
    }

    /** Returns the secret for a valid, unexpired blob matching the user — null otherwise. */
    public function verify(string $blob, int $userId): ?string
    {
        if (!str_contains($blob, '.')) {
            return null;
        }
        [$body, $mac] = explode('.', $blob, 2);
        if ($body === '' || $mac === '') {
            return null;
        }
        if (!hash_equals($this->mac($body), $this->decodeMac($mac))) {
            return null;
        }

        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$payloadUserId, $secret, $expiresAt] = $parts;

        if ((int) $payloadUserId !== $userId) {
            return null;
        }
        if (!ctype_digit($expiresAt) || (int) $expiresAt <= time()) {
            return null;
        }

        return $secret;
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', 'totp_setup:' . $body, $this->appKey, true);
    }

    private function decodeMac(string $mac): string
    {
        $decoded = base64_decode(strtr($mac, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
