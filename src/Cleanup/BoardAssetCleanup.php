<?php

declare(strict_types=1);

namespace Votepit\Cleanup;

use Votepit\Persistence\BoardAssetRepository;
use Votepit\Security\BoardAssetFileStore;

/**
 * Removes the image files of a board or account BEFORE the owning row is
 * deleted. The board_assets rows themselves go away with the FK cascade; the
 * files are not in the database and would otherwise be orphaned on disk
 * (storage growth, personal data left behind after a GDPR purge).
 *
 * File removal is best-effort and never blocks the deletion itself: the row
 * that referenced a file is gone with its owner, so a failed unlink leaves
 * only an unreferenced file behind, never a live reference to a missing one.
 */
final readonly class BoardAssetCleanup
{
    public function __construct(
        private BoardAssetRepository $assets,
        private BoardAssetFileStore $files,
    ) {}

    public function purgeForBoard(int $boardId): void
    {
        foreach ($this->assets->fileNamesForBoard($boardId) as $fileName) {
            $this->files->delete($fileName);
        }
    }

    public function purgeForAccount(int $accountId): void
    {
        foreach ($this->assets->fileNamesForAccount($accountId) as $fileName) {
            $this->files->delete($fileName);
        }
    }
}
