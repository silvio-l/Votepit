<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Maintenance flag honoured by public/index.php (answers 503) and by
 * bin/votepit-cron.php (skips its tick). The flag is a plain file, so it works
 * even when the database is not reachable and needs no code to read it.
 */
final readonly class MaintenanceMode
{
    /** Relative to the installation root; the front controller repeats this path. */
    public const FLAG_PATH = 'storage/maintenance.flag';

    public function __construct(private string $root) {}

    public function path(): string
    {
        return $this->root . '/' . self::FLAG_PATH;
    }

    public function isEnabled(): bool
    {
        return is_file($this->path());
    }

    /** @throws UpdateException */
    public function enable(string $reason): void
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new UpdateException('The storage folder cannot be created.');
        }
        if (file_put_contents($this->path(), $reason . "\n") === false) {
            throw new UpdateException('The maintenance flag cannot be written.');
        }
    }

    public function disable(): void
    {
        if ($this->isEnabled()) {
            @unlink($this->path());
        }
    }
}
