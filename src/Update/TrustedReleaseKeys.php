<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The public keys that may sign Community releases: the owner's primary key and
 * an offline emergency key. A release signed by either one is accepted.
 *
 * The values are the base64 key line of the minisign .pub files (the second
 * line), generated with tools/generate-release-keys.sh. If a value is ever not a
 * well-formed key, bin/update.php refuses to run (fail-secure). Public keys are
 * not secrets; the matching secret keys never enter the repository.
 */
final class TrustedReleaseKeys
{
    /** Owner's primary key, generated 2026-10-29 (secret key on the owner's Mac, backed up in a password manager). */
    public const PRIMARY = 'RWQsaiCVDRZOadLcM2iNQN1YATwk9hMbe/HyE1VqjokzaGAfjyJrgtU9';

    /** Emergency key, generated 2026-10-29 (secret key kept apart from the primary, never on a server). */
    public const EMERGENCY = 'RWQDrVX41M+OHZMrm7eEZXckCAxXKanUzwWGRebv3myprPFEdud2MVz6';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRIMARY, self::EMERGENCY];
    }

    /** @return list<string> the names of constants that are not usable public keys yet */
    public static function unusable(): array
    {
        $unusable = [];
        foreach (['PRIMARY' => self::PRIMARY, 'EMERGENCY' => self::EMERGENCY] as $name => $key) {
            if (!Minisign::isWellFormedPublicKey($key)) {
                $unusable[] = $name;
            }
        }

        return $unusable;
    }
}
