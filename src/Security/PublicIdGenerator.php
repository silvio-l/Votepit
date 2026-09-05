<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Random opaque public identifier (users.public_id) — safe to display
 * instead of the internal auto-increment `id` (which would otherwise leak
 * total registered-user count/growth and let members compare signup order).
 *
 * Not derived from `id` in any way (no HMAC/reversible encoding of it):
 * purely random, stored 1:1 on the same row, correlated only by that row —
 * nothing to reverse-engineer even with the generation algorithm in hand.
 *
 * Crockford-ish 32-symbol alphabet (excludes 0/O/1/I/L) so a displayed ID
 * is unambiguous when read aloud or typed. 10 symbols * 5 bits = 50 bits of
 * entropy — negligible collision risk at any realistic user count; callers
 * still enforce a DB UNIQUE constraint (users.public_id) and retry on the
 * rare collision rather than relying on entropy alone.
 */
final class PublicIdGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LENGTH   = 10;

    public static function generate(): string
    {
        $id = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $id .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $id;
    }
}
