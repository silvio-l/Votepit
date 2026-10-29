<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Verifies the identify JWT a customer's backend signs for the embeddable
 * widget (ADR 0005 §3). Deliberately tiny and strict instead of a generic
 * JWT library:
 *
 *  - the algorithm is FIXED to HS256 — the header's `alg` must be exactly
 *    "HS256" (no `none`, no RS/ES confusion, no `alg` negotiation at all);
 *    a `kid`/`jku`/`x5u` header is ignored, never followed;
 *  - signature compared in constant time (hash_equals) over the exact
 *    received `header.payload` bytes, with strict base64url decoding;
 *  - `aud` must equal the board's audience string (string or array form);
 *  - `iat` and `exp` are mandatory integers: iat ≤ now + skew,
 *    exp > now − skew, exp − iat ≤ MAX_LIFETIME (10 min) — a long-lived
 *    token is rejected even if correctly signed;
 *  - `email` is mandatory (valid address, ≤ 254), `name`/`sub` optional
 *    plain strings with length caps.
 *
 * Returns the verified claims or null — never a reason (the caller answers
 * every failure identically, 401 `invalid_token`, no oracle). The plaintext
 * email only lives for the duration of the request: the caller hashes it
 * (IdentityHasher, ADR 0002) and discards it.
 */
final class WidgetJwtVerifier
{
    /** Maximum `exp - iat` the customer may issue (ADR 0005: ≤ 10 min). */
    public const MAX_LIFETIME = 600;

    /** Tolerated clock skew between the customer's server and ours. */
    public const CLOCK_SKEW = 60;

    private const MAX_TOKEN_LENGTH = 4096;

    private const MAX_NAME_LENGTH = 100;

    private const MAX_SUB_LENGTH = 255;

    private const MAX_EMAIL_LENGTH = 254;

    /** Audience string a board's identify JWT must carry. */
    public static function audienceFor(int $boardId): string
    {
        return 'votepit:board:' . $boardId;
    }

    /**
     * @return array{email: string, name: string|null, sub: string|null}|null
     */
    public function verify(string $jwt, string $secret, string $expectedAudience, int $now): ?array
    {
        if ($secret === '' || $jwt === '' || strlen($jwt) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header    = $this->decodeJson($headerB64);
        $payload   = $this->decodeJson($payloadB64);
        $signature = $this->base64UrlDecode($signatureB64);
        if ($header === null || $payload === null || $signature === null) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return $this->validateClaims($payload, $expectedAudience, $now);
    }

    /**
     * @param array<mixed> $payload
     * @return array{email: string, name: string|null, sub: string|null}|null
     */
    private function validateClaims(array $payload, string $expectedAudience, int $now): ?array
    {
        $aud = $payload['aud'] ?? null;
        $audOk = is_string($aud)
            ? hash_equals($expectedAudience, $aud)
            : is_array($aud) && in_array($expectedAudience, $aud, true);
        if (!$audOk) {
            return null;
        }

        $iat = $payload['iat'] ?? null;
        $exp = $payload['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) {
            return null;
        }
        if ($iat > $now + self::CLOCK_SKEW || $exp <= $now - self::CLOCK_SKEW || $exp <= $iat || $exp - $iat > self::MAX_LIFETIME) {
            return null;
        }

        $email = $payload['email'] ?? null;
        if (!is_string($email) || strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = $this->optionalString($payload['name'] ?? null, self::MAX_NAME_LENGTH);
        $sub  = $this->optionalString($payload['sub'] ?? null, self::MAX_SUB_LENGTH);
        if ($name === false || $sub === false) {
            return null;
        }

        return ['email' => $email, 'name' => $name, 'sub' => $sub];
    }

    /** null = absent, false = present but invalid. */
    private function optionalString(mixed $value, int $maxLength): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        // Plaintext only: strip control characters, cap the length.
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
        if ($clean === '' || mb_strlen($clean) > $maxLength) {
            return $clean === '' ? null : false;
        }

        return $clean;
    }

    /** @return array<mixed>|null */
    private function decodeJson(string $segment): ?array
    {
        $raw = $this->base64UrlDecode($segment);
        if ($raw === null) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $segment): ?string
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $segment) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
