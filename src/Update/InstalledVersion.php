<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The version this code tree was built as, read from composer.json (the
 * release build copies composer.json unchanged, and build-release.sh takes the
 * archive name from the same field).
 */
final class InstalledVersion
{
    public static function fromRoot(string $root): string
    {
        $raw = @file_get_contents($root . '/composer.json');
        if ($raw === false) {
            throw new UpdateException('composer.json is not readable in ' . $root . '.');
        }

        $data    = json_decode($raw, true);
        $version = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
        if (!SemanticVersion::isValid($version)) {
            throw new UpdateException('composer.json has no valid "version" (MAJOR.MINOR.PATCH).');
        }

        return $version;
    }
}
