<?php

declare(strict_types=1);

namespace Votepit\Security;

use Votepit\Nudge\OperatorNudgeKind;

/**
 * Stateless one-click unsubscribe token for ONE operator nudge kind of ONE
 * user (POST /operator-nudges/unsubscribe). The body is "<userId>:<kind>",
 * authenticated with HMAC-SHA256(app_key) under its own domain-separation
 * prefix, so a MAC from DigestUnsubscribeToken or any other signed blob can
 * never verify here.
 *
 * Like the digest token: no expiry, no DB row (an old mail link must keep
 * working). The only thing a valid token can do is switch exactly that kind
 * OFF for exactly that user, so a leaked link has no impact beyond muting one
 * mail type the recipient can re-enable in their profile.
 */
final readonly class OperatorNudgeUnsubscribeToken
{
    private const MAC_PREFIX = 'operator_nudge_unsubscribe:';

    public function __construct(private string $appKey) {}

    public function sign(int $userId, OperatorNudgeKind $kind): string
    {
        $body = $this->encode($userId . ':' . $kind->value);

        return $body . '.' . $this->encode($this->mac($body));
    }

    /**
     * The (user, kind) an authentic token names, null otherwise (fail-secure,
     * constant-time MAC check, strict body grammar).
     *
     * @return array{user_id: int, kind: OperatorNudgeKind}|null
     */
    public function verify(string $token): ?array
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

        $payload = base64_decode(strtr($body, '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $fields = explode(':', $payload);
        if (count($fields) !== 2 || !ctype_digit($fields[0]) || (int) $fields[0] <= 0) {
            return null;
        }

        $kind = OperatorNudgeKind::tryFrom($fields[1]);
        if ($kind === null) {
            return null;
        }

        return ['user_id' => (int) $fields[0], 'kind' => $kind];
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
