<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Extracts a Community release archive after checking its entries. The archive
 * must contain exactly one top-level folder "votepit/", no absolute or
 * traversing paths, no symlinks, and the files a working tree needs.
 */
final class ReleaseArchive
{
    /** Files and folders every release must contain to be usable. */
    private const REQUIRED = [
        'composer.json',
        'vendor/autoload.php',
        'public/index.php',
        'public/index.html',
        'src',
        'bin/migrate.php',
        'migrations',
    ];

    /**
     * @return string the extracted tree (the "votepit/" folder inside $destination)
     * @throws UpdateException
     */
    public static function extract(string $zipPath, string $destination): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('The release archive cannot be opened.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !self::isSafeEntry($name)) {
                    throw new UpdateException('The release archive contains an unsafe entry path.');
                }

                $attributes = 0;
                $opsys      = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0xF000) === 0xA000) {
                    throw new UpdateException('The release archive contains a symbolic link, which is refused.');
                }
            }

            if (!$zip->extractTo($destination)) {
                throw new UpdateException('The release archive could not be extracted.');
            }
        } finally {
            $zip->close();
        }

        $tree = $destination . '/votepit';
        foreach (self::REQUIRED as $required) {
            if (!file_exists($tree . '/' . $required)) {
                throw new UpdateException("The release archive is incomplete: {$required} is missing.");
            }
        }

        return $tree;
    }

    private static function isSafeEntry(string $name): bool
    {
        if (!str_starts_with($name, 'votepit/') || str_contains($name, '\\') || preg_match('/[\x00-\x1f]/', $name) === 1) {
            return false;
        }

        // Traversal segments are refused everywhere, the trailing "/" of folder entries is fine.
        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '..' || $segment === '') {
                return false;
            }
        }

        // An installed config is never part of a release; refuse one outright.
        return $name !== 'votepit/config/config.php' && !str_starts_with($name, 'votepit/config/config.php/');
    }
}
