<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Stateless one-click unsubscribe token for participant mails (close-the-loop
 * / re-engagement B). Same signing scheme as DigestUnsubscribeToken (HMAC-
 * SHA256 over app_key, its own domain-separation prefix), extended with the
 * scope: the token binds ONE recipient and ONE category.
 *
 * Recipient is either a registered user id (`u:<id>`) or a guest, identified
 * by the keyed HMAC of the normalised e-mail address (`a:<64 hex>`), so no
 * address appears in the link. Categories: my_ideas, participated,
 * new_ideas, or `all` (every participant mail for that recipient).
 *
 * The only thing the token can do is switch OFF mails for that recipient and
 * category — never on, never anything else — so a leaked link has no
 * confidentiality or integrity impact beyond muting mail the recipient can
 * re-enable in their profile. No expiry, deliberately: an unsubscribe link in
 * an old mail must keep working.
 */
final readonly class ParticipantUnsubscribeToken
{
    private const MAC_PREFIX = 'participant_unsubscribe:';

    /** @var list<string> */
    public const CATEGORIES = ['my_ideas', 'participated', 'new_ideas', 'all'];

    /** Body grammar after base64url-decoding: `u:<id>|<category>` or `a:<hash>|<category>`. */
    private const BODY_PATTERN = '/^(?:u:([1-9]\d{0,18})|a:([0-9a-f]{64}))\|(my_ideas|participated|new_ideas|all)$/';

    public function __construct(private string $appKey) {}

    public function signForUser(int $userId, string $category): string
    {
        return $this->sign('u:' . $userId . '|' . $category);
    }

    public function signForGuest(string $guestKey, string $category): string
    {
        return $this->sign('a:' . $guestKey . '|' . $category);
    }

    /**
     * The scope of an authentic token, null otherwise (fail-secure, constant-
     * time MAC check, strict grammar check on the decoded body).
     *
     * @return array{user_id: int|null, guest_key: string|null, category: string}|null
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

        $decodedBody = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decodedBody === false || preg_match(self::BODY_PATTERN, $decodedBody, $m) !== 1) {
            return null;
        }

        $isUser = $m[1] !== '';
        return [
            'user_id'   => $isUser ? (int) $m[1] : null,
            'guest_key' => $isUser ? null : $m[2],
            'category'  => $m[3],
        ];
    }

    private function sign(string $plain): string
    {
        $body = $this->encode($plain);

        return $body . '.' . $this->encode($this->mac($body));
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
