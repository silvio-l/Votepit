<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates account/board slugs BEFORE they are stored AND BEFORE they are
 * used to resolve a URL path segment (ADR 0001 §2c/§5b).
 *
 * Slugs are user-chosen but land directly in the routable path
 * (`/{account-slug}/{board-slug}`). Protection against
 * collisions with system routes must come from explicit rules, never from
 * unguessability (§2c item 7). This validator is the single source of truth
 * for the reserved-word list — reused directly for account slugs (no separate
 * list) by cloud route resolution (cloud path routing) and, once
 * account creation exists, by whatever validates a chosen account
 * slug before it is persisted.
 */
final class SlugValidator
{
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MIN_LENGTH = 1;

    private const MAX_LENGTH = 64;

    /**
     * Reserved words: today's system routes plus headroom for planned
     * cloud/marketing paths. Extend this single list — never duplicate it
     * elsewhere. Also the single source of truth for account-slug reserved
     * words (cloud path routing) — an account slug goes through
     * this exact same validate()/reservedWords(), no separate account list.
     *
     * @var list<string>
     */
    private const RESERVED_WORDS = [
        'admin',
        'api',
        'login',
        'logout',
        'app',
        'pricing', // export-ok: pricing (reserved slug, not a price reference)
        'docs',
        'signup',
        'download',
        'invite',
        'de',
        'en',
        'public', // unauthenticated feeds for the marketing site (e.g. /public/promotions in the cloud layer)
    ];

    /**
     * Returns true if $slug satisfies every slug rule (charset, length,
     * hyphen placement, not reserved).
     */
    public static function isValid(string $slug): bool
    {
        return !self::validate($slug) instanceof SlugInvalidReason;
    }

    /**
     * Returns null when $slug is valid, otherwise the specific reason it was
     * rejected — so callers/tests can distinguish each invalidity class
     * instead of a single opaque "invalid".
     */
    public static function validate(string $slug): ?SlugInvalidReason
    {
        $length = strlen($slug);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return SlugInvalidReason::InvalidLength;
        }

        if (preg_match(self::PATTERN, $slug) !== 1) {
            if (str_starts_with($slug, '-')) {
                return SlugInvalidReason::LeadingHyphen;
            }

            if (str_ends_with($slug, '-')) {
                return SlugInvalidReason::TrailingHyphen;
            }

            if (str_contains($slug, '--')) {
                return SlugInvalidReason::DoubleHyphen;
            }

            return SlugInvalidReason::InvalidCharacters;
        }

        if (in_array($slug, self::RESERVED_WORDS, true)) {
            return SlugInvalidReason::ReservedWord;
        }

        return null;
    }

    /**
     * Exposes the reserved-word list for reuse (e.g. route resolution order
     * in cloud path routing) without duplicating it.
     *
     * @return list<string>
     */
    public static function reservedWords(): array
    {
        return self::RESERVED_WORDS;
    }
}
