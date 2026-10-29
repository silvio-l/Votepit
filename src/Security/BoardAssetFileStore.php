<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Disk storage for re-encoded board images. The directory lives outside the
 * webroot; file names are server-generated `<32 hex>.<jpg|png>` and are
 * re-validated here before any filesystem access, so a stored name can never
 * escape the directory.
 */
final readonly class BoardAssetFileStore
{
    private const FILE_NAME_PATTERN = '/^[0-9a-f]{32}\.(?:jpg|png)$/';

    public function __construct(
        private string $dir,
    ) {}

    public function dir(): string
    {
        return $this->dir;
    }

    /** Returns the stored file name, or null when the write failed. */
    public function write(string $publicId, string $extension, string $bytes): ?string
    {
        $fileName = $publicId . '.' . $extension;
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            return null;
        }

        if (!is_dir($this->dir) && !mkdir($this->dir, 0o750, true) && !is_dir($this->dir)) {
            return null;
        }

        $written = file_put_contents($this->dir . '/' . $fileName, $bytes, LOCK_EX);

        return $written === strlen($bytes) ? $fileName : null;
    }

    public function read(string $fileName): ?string
    {
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            return null;
        }

        $bytes = @file_get_contents($this->dir . '/' . $fileName);

        return is_string($bytes) ? $bytes : null;
    }

    /** Best-effort: a missing file is not an error (the row is the source of truth). */
    public function delete(string $fileName): void
    {
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            return;
        }

        $path = $this->dir . '/' . $fileName;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
