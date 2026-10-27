<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Stateless one-click unsubscribe token for the weekly owner digest
 * (POST /digest/unsubscribe). Binds a user id via HMAC-SHA256(app_key) —
 * same signing scheme as TotpSetupToken/SessionService, with its own
 * domain-separation prefix so a MAC from any other signed blob can never
 * verify here.
 *
 * Deliberately no expiry and no DB row: an unsubscribe link in an old mail
 * must keep working, and the only thing the token can ever do is switch one
 * user's digest OFF (never on, never anything else) — a leaked link
 * therefore has no confidentiality or integrity impact beyond muting a
 * mail the recipient could re-enable from their profile.
 */
final readonly class DigestUnsubscribeToken
{
    private const MAC_PREFIX = 'digest_unsubscribe:';

    public function __construct(private string $appKey) {}

    public function sign(int $userId): string
    {
        $body = $this->encode((string) $userId);

        return $body . '.' . $this->encode($this->mac($body));
    }

    /** The user id for an authentic token, null otherwise (fail-secure, constant-time MAC check). */
    public function verify(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$body, $mac] = $parts;

        $decodedMac = base64_decode(strtr($mac, '-_', '+/'), true);
        if ($decodedMac === false || !hash_equals($this->mac($body), $decodedMac)) {
            return null;
        }

        $userId = base64_decode(strtr($body, '-_', '+/'), true);
        if ($userId === false || !ctype_digit($userId) || (int) $userId <= 0) {
            return null;
        }

        return (int) $userId;
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', self::MAC_PREFIX . $body, $this->appKey, true);
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
