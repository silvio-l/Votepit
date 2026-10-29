<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * TOTP (RFC 6238) — pure PHP, no Composer dependency. HMAC-SHA1,
 * 30-second step, 6 digits, ±1 step tolerance (clock drift).
 *
 * HMAC-SHA1 is deliberate here: it's the RFC 6238 default and the only
 * algorithm virtually every authenticator app (Google/Microsoft/
 * Authy/1Password …) supports without an explicit algorithm choice — SHA1 in
 * this context is a MAC (HMAC), not a collision-resistance requirement,
 * SHA1 collision attacks are irrelevant here.
 *
 * The secret is NEVER persisted here — callers encrypt it via
 * EncryptionService (context 'totp') before storing it.
 */
final class Totp
{
    private const SECRET_BYTES = 20; // 160 bits of entropy
    private const STEP_SECONDS = 30;
    private const DIGITS       = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generates a new Base32 secret (160 bits of entropy). */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * otpauth://totp/... provisioning URI for QR code rendering (client-side,
     * never an external service — see ADR §5b shared-origin invariant).
     */
    public function provisioningUri(string $secretBase32, string $accountLabel, string $issuer = 'Votepit'): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            rawurlencode($secretBase32),
            rawurlencode($issuer),
            self::DIGITS,
            self::STEP_SECONDS,
        );
    }

    /**
     * Verifies a 6-digit code against the secret, with ±$window steps
     * of tolerance (default 1 = ±30s). Constant-time comparison per step.
     *
     * Stateless — does not know or care about replay. Callers that can
     * persist per-user state (login 2FA, TOTP setup/disable) should use
     * verifyStep() instead and reject a re-used step (CWE-294).
     */
    public function verify(string $secretBase32, string $code, int $window = 1): bool
    {
        return $this->verifyStep($secretBase32, $code, $window) !== null;
    }

    /**
     * Same matching as verify(), but returns the matched RFC 4226 counter
     * step instead of a bool (or null on no match) — the caller compares it
     * against the user's last-accepted step to reject a replayed code
     * (CWE-294: a code intercepted/observed once must not be usable twice,
     * even within its own ±$window validity).
     *
     * If multiple offsets in the window match (only possible in degenerate
     * test setups — a real 6-digit HOTP collision across distinct counters
     * is astronomically unlikely), the highest step is returned: accepting
     * the most "forward" match is the same choice TOTP implementations make
     * when resynchronizing to counter drift.
     */
    public function verifyStep(string $secretBase32, string $code, int $window = 1): ?int
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $secret      = $this->base32Decode($secretBase32);
        $currentStep = intdiv(time(), self::STEP_SECONDS);
        $matchedStep = null;

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step      = $currentStep + $offset;
            $candidate = $this->hotp($secret, $step);
            if (hash_equals($candidate, $code)) {
                // No early return — constant number of iterations for every call.
                $matchedStep = $matchedStep === null ? $step : max($matchedStep, $step);
            }
        }

        return $matchedStep;
    }

    /** HOTP (RFC 4226) for a specific counter value. */
    private function hotp(string $secret, int $counter): string
    {
        $counterBytes = pack('N*', 0, $counter); // 64-bit big-endian counter
        $hash         = hash_hmac('sha1', $counterBytes, $secret, true);
        $offset       = ord($hash[19]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits   = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk  = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[(int) bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits   = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue; // discard incomplete trailing byte (padding remainder)
            }
            $bytes .= chr((int) bindec($byte));
        }

        return $bytes;
    }
}
