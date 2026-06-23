<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Stateless Time-Trap: embeds a signed form-load timestamp in a hidden field.
 *
 * On GET /…/new the action calls stamp() to get a signed value to put in the form.
 * On POST the action calls verify() to check that the form was loaded at least
 * MIN_SECONDS ago — bots that submit immediately fail this check.
 *
 * Format: "<unix-timestamp>.<hmac>" — HMAC-SHA256 over the timestamp with the app key.
 * Verification is constant-time (hash_equals). No server-side state required.
 */
final readonly class TimeTrapService
{
    /** Minimum elapsed seconds between form load and submit (conservative). */
    public const MIN_SECONDS = 3;

    public function __construct(private string $appKey) {}

    /**
     * Generate a signed timestamp token to embed in the form as a hidden field.
     */
    public function stamp(): string
    {
        $ts  = (string) time();
        $mac = $this->mac($ts);
        return $ts . '.' . $mac;
    }

    /**
     * Verify the hidden-field value from the POST request.
     *
     * Returns true if the stamp is valid AND sufficient time has elapsed.
     * Returns false if the stamp is missing, tampered, or too recent.
     */
    public function verify(string $value, int $minSeconds = self::MIN_SECONDS): bool
    {
        if (!str_contains($value, '.')) {
            return false;
        }

        [$ts, $mac] = explode('.', $value, 2);

        if ($ts === '' || $mac === '') {
            return false;
        }

        // Reject non-numeric timestamps.
        if (!ctype_digit($ts)) {
            return false;
        }

        // Constant-time MAC verification.
        if (!hash_equals($this->mac($ts), $mac)) {
            return false;
        }

        $elapsed = time() - (int) $ts;
        return $elapsed >= $minSeconds;
    }

    private function mac(string $ts): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $this->appKey, true)), '+/', '-_'), '=');
    }
}
