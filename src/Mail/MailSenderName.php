<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Sender DISPLAY NAME for branded board mails (P-32, Pro).
 *
 * The envelope address stays Votepit's (SPF/DKIM/DMARC unchanged); only the
 * name shown in the From header changes, to "{Board name} via Votepit", so a
 * recipient always sees that the mail comes through Votepit. Impersonation
 * mitigation (documented in the ledger):
 *   - the suffix " via Votepit" is fixed and always appended;
 *   - only letters, digits, spaces and & . ' - survive; quotes, angle brackets,
 *     @, commas, semicolons, backslashes and every control character (CR/LF)
 *     are stripped, so no header injection or address smuggling is possible;
 *   - the name part is length-limited to 40 characters;
 *   - a name containing "votepit" is refused (the account falls back to the
 *     installation's sender name), so no one can present as Votepit itself.
 *
 * Owner decision 2026-10-25: the board name, not the account name, because
 * branding is a per-board setting only.
 */
final class MailSenderName
{
    public const SUFFIX = ' via Votepit';
    public const MAX_NAME_LENGTH = 40;

    private const SAFE_PATTERN = "/^[\\p{L}\\p{N} &.'\\-]{1,40} via Votepit$/u";

    /** @return string|null the display name, or null when the board name cannot be used */
    public static function forName(string $boardName): ?string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $boardName);
        $name = (string) preg_replace('/[^\p{L}\p{N} &.\'\-]+/u', ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .-'");

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = trim(mb_substr($name, 0, self::MAX_NAME_LENGTH), " .-'");
        }

        if (stripos($name, 'votepit') !== false) {
            return null;
        }

        return $name . self::SUFFIX;
    }

    /** True only for a display name this class could have produced. */
    public static function isSafe(string $displayName): bool
    {
        if (preg_match(self::SAFE_PATTERN, $displayName) !== 1) {
            return false;
        }

        $name = substr($displayName, 0, -strlen(self::SUFFIX));

        return stripos($name, 'votepit') === false;
    }
}
