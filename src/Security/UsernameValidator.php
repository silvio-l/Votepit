<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates the optional public display name (profile-visibility feature,
 * migrations/0022_add_user_username.sql) BEFORE storage. Mirrors
 * SocialLinkValidator's contract: returns the normalized value to store
 * (original casing preserved — only whitespace is trimmed), or null if the
 * raw input fails validation. Never rejects on uniqueness — that's a DB
 * constraint (idx_users_username_lower) checked by the caller.
 *
 * Charset deliberately stays simple and ASCII-only: it's never used in a
 * routable URL path (the public profile route is /members/{userId}/profile,
 * not /members/{username}), so there's no SlugValidator-style reserved-word
 * concern — just something safe to render as plain text next to an idea or
 * comment (still always output-escaped like any other user content, per the
 * plaintext-only invariant in the top-level CLAUDE.md §🔒).
 */
final class UsernameValidator
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 30;

    /** Must start with a letter — keeps a username visually distinct from a bare number. */
    private const PATTERN = '/^[A-Za-z]\w{2,29}$/';

    /** Returns the normalized (trimmed) username, or null if invalid. */
    public static function validate(string $raw): ?string
    {
        $trimmed = trim($raw);

        if (mb_strlen($trimmed) < self::MIN_LENGTH || mb_strlen($trimmed) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match(self::PATTERN, $trimmed) === 1 ? $trimmed : null;
    }
}
