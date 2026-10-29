<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * Replaces the code of an installation with a release tree, one managed entry
 * at a time, and rolls back when any step fails.
 *
 * Only MANAGED entries are touched. config/, storage/ (uploads, avatars,
 * maintenance flag, update workspace), logs/ and anything the operator added
 * stay where they are. Both the staging tree and the backup live below
 * storage/update/, so every move is a rename on the same filesystem, which is
 * the closest to atomic a shared host offers.
 */
final readonly class CodeSwap
{
    /** @var list<string> */
    public const MANAGED = [
        'bin',
        'migrations',
        'public',
        'resources',
        'db',
        'src',
        'vendor',
        'composer.json',
        'composer.lock',
        'README.md',
        'LICENSE',
        '.htaccess',
    ];

    public function __construct(private string $root) {}

    /**
     * Moves the current managed entries into $backupDir and the release tree's
     * managed entries into the root. On any failure the previous state is
     * restored before the exception leaves this method.
     *
     * @throws UpdateException
     * @throws RollbackFailedException
     */
    public function apply(string $releaseTree, string $backupDir): void
    {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0o700, true) && !is_dir($backupDir)) {
            throw new UpdateException('The backup folder cannot be created.');
        }

        /** @var list<string> $movedAside entries moved from the root into the backup */
        $movedAside = [];
        /** @var list<string> $movedIn entries moved from the release tree into the root */
        $movedIn = [];

        try {
            foreach (self::MANAGED as $entry) {
                $current = $this->root . '/' . $entry;
                if (file_exists($current) || is_link($current)) {
                    $this->move($current, $backupDir . '/' . $entry);
                    $movedAside[] = $entry;
                }
            }

            foreach (self::MANAGED as $entry) {
                $source = $releaseTree . '/' . $entry;
                if (file_exists($source) || is_link($source)) {
                    $this->move($source, $this->root . '/' . $entry);
                    $movedIn[] = $entry;
                }
            }
        } catch (UpdateException $e) {
            $this->restore($movedIn, $movedAside, $backupDir);

            throw $e;
        }
    }

    /**
     * Puts back the previous code from a backup made by apply(). Used when
     * something after the swap (the migration run) fails.
     *
     * @throws UpdateException
     * @throws RollbackFailedException
     */
    public function rollback(string $backupDir): void
    {
        $movedAside = [];
        foreach (self::MANAGED as $entry) {
            if (file_exists($backupDir . '/' . $entry) || is_link($backupDir . '/' . $entry)) {
                $movedAside[] = $entry;
            }
        }

        $movedIn = [];
        foreach (self::MANAGED as $entry) {
            if (file_exists($this->root . '/' . $entry) || is_link($this->root . '/' . $entry)) {
                $movedIn[] = $entry;
            }
        }

        $this->restore($movedIn, $movedAside, $backupDir);
    }

    /**
     * @param list<string> $movedIn   entries that are currently in the root from the new tree
     * @param list<string> $movedAside entries that currently sit in the backup
     */
    private function restore(array $movedIn, array $movedAside, string $backupDir): void
    {
        // Remove what the new release put in, in reverse order. The new tree is
        // discarded by the caller, so it is renamed aside into the backup folder's
        // sibling "rejected" directory instead of being deleted here.
        $rejected = dirname($backupDir) . '/rejected-' . basename($backupDir);
        foreach (array_reverse($movedIn) as $entry) {
            $target = $this->root . '/' . $entry;
            if (file_exists($target) || is_link($target)) {
                if (!is_dir($rejected) && !mkdir($rejected, 0o700, true) && !is_dir($rejected)) {
                    throw new RollbackFailedException("Rollback failed: the folder {$rejected} cannot be created. Restore the backup from {$backupDir} manually.");
                }
                if (!@rename($target, $rejected . '/' . $entry)) {
                    throw new RollbackFailedException("Rollback failed while removing {$entry}. Restore the backup from {$backupDir} manually.");
                }
            }
        }

        foreach ($movedAside as $entry) {
            if (!@rename($backupDir . '/' . $entry, $this->root . '/' . $entry)) {
                throw new RollbackFailedException("Rollback failed while restoring {$entry}. Restore the backup from {$backupDir} manually.");
            }
        }
    }

    /** @throws UpdateException */
    private function move(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new UpdateException('Moving ' . basename($from) . ' failed; check that the installation and storage/ are on the same filesystem and writable.');
        }
    }
}
