<?php

declare(strict_types=1);

namespace Votepit\Cleanup;

use Votepit\Domain\UserDeletionGuard;
use Votepit\Extension\DeletionBlocked;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Persistence\UserRepository;

/**
 * Per-user purge loop for users whose self-deletion grace period has passed
 * (competitor-parity issue 12) — run by bin/cleanup-expired-accounts.php
 * right after the account purge, same failure isolation as
 * ExpiredAccountCleaner: one failing user never aborts the run, the failure
 * goes to ErrorReporter + a `user.purge_failed` audit event, and
 * `user.purged` is only written after the erasure actually committed.
 *
 * UserDeletionGuard is re-checked here: a user who became an account owner
 * or operator during the grace period is skipped (`user.purge_skipped`),
 * never left behind as an ownerless account. The schedule stays in place,
 * so the purge is retried on the next run once the blocker is gone.
 *
 * The avatar image lives on disk, not in the DB — it is removed after the
 * row is gone (a leftover file without its row would be unreachable PII).
 */
final readonly class ExpiredUserCleaner
{
    public function __construct(
        private UserRepository $users,
        private UserErasureRepository $erasure,
        private UserDeletionGuard $guard,
        private AuditLogger $audit,
        private ErrorReporter $reporter,
        private string $avatarDir,
    ) {}

    /**
     * @param list<array{id: int, deletion_scheduled_at: string}> $expired
     * @return array{purged: int, failed: int, skipped: int}
     */
    public function run(array $expired): array
    {
        $purged  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ($expired as $row) {
            $userId = $row['id'];

            try {
                $blocked = $this->guard->check($userId);
                if ($blocked instanceof DeletionBlocked) {
                    $skipped++;
                    $this->audit->log('user.purge_skipped', ['user_id' => $userId, 'key' => $blocked->key]);
                    continue;
                }

                $user           = $this->users->findById($userId);
                $avatarFilename = is_array($user) && is_string($user['avatar_filename'] ?? null) ? $user['avatar_filename'] : null;

                $this->erasure->erase($userId, $this->users->findOrCreateDeletedPlaceholder());
            } catch (\Throwable $e) {
                $failed++;
                $this->reporter->report($e);
                $this->audit->log('user.purge_failed', [
                    'user_id'  => $userId,
                    'deadline' => $row['deletion_scheduled_at'],
                    'error'    => $e->getMessage(),
                ]);
                continue;
            }

            if ($avatarFilename !== null) {
                $path = $this->avatarDir . '/' . basename($avatarFilename);
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $purged++;
            $this->audit->log('user.purged', ['user_id' => $userId, 'deadline' => $row['deletion_scheduled_at']]);
        }

        return ['purged' => $purged, 'failed' => $failed, 'skipped' => $skipped];
    }
}
