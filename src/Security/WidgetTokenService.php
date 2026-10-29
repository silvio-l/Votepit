<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Short-lived, stateless widget token (ADR 0005 §3/§5): issued by
 * POST …/widget/identify after a verified identify JWT, sent back by the
 * widget iframe as `Authorization: Bearer <token>` — never as a cookie, and
 * the iframe keeps it in memory only.
 *
 * Layout: `vpw1.` base64url(json payload) `.` base64url(HMAC-SHA256).
 * The MAC key is derived from app_key with its own domain separator, so a
 * widget token can never be confused with a session/CSRF/time-trap MAC.
 *
 * Payload: board id + account id (scope: exactly ONE board), user id, exp,
 * and `sv` — a fingerprint of the board's current widget secret. Rotating
 * the secret therefore revokes every outstanding widget token, without a
 * token table.
 */
final readonly class WidgetTokenService
{
    public const TTL = 3600;

    private const PREFIX = 'vpw1';

    private string $key;

    public function __construct(string $appKey)
    {
        $this->key = hash_hmac('sha256', 'votepit-widget-token-v1', $appKey, true);
    }

    /** Fingerprint of a board's widget secret version (see class doc). */
    public static function secretFingerprint(string $secretEncrypted, ?string $rotatedAt): string
    {
        return substr(hash('sha256', $secretEncrypted . '|' . ($rotatedAt ?? '')), 0, 16);
    }

    public function issue(int $accountId, int $boardId, int $userId, string $secretFingerprint, int $now): string
    {
        $body = $this->b64((string) json_encode([
            'a'  => $accountId,
            'b'  => $boardId,
            'u'  => $userId,
            'sv' => $secretFingerprint,
            'exp' => $now + self::TTL,
        ]));

        return self::PREFIX . '.' . $body . '.' . $this->b64($this->mac($body));
    }

    /**
     * @return array{account_id: int, board_id: int, user_id: int, sv: string}|null
     */
    public function verify(string $token, int $now): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || strlen($token) > 1024) {
            return null;
        }

        $mac = base64_decode(strtr($parts[2], '-_', '+/'), true);
        if ($mac === false || !hash_equals($this->mac($parts[1]), $mac)) {
            return null;
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)
            || !is_int($data['a'] ?? null)
            || !is_int($data['b'] ?? null)
            || !is_int($data['u'] ?? null)
            || !is_string($data['sv'] ?? null)
            || !is_int($data['exp'] ?? null)
            || $data['exp'] <= $now
        ) {
            return null;
        }

        return ['account_id' => $data['a'], 'board_id' => $data['b'], 'user_id' => $data['u'], 'sv' => $data['sv']];
    }

    private function mac(string $body): string
    {
        return hash_hmac('sha256', self::PREFIX . '.' . $body, $this->key, true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
