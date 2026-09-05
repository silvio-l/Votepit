<?php

declare(strict_types=1);

namespace Votepit\Cleanup;

use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AccountRepository;

/**
 * Per-account purge loop for bin/cleanup-expired-accounts.php, extracted
 * into a testable unit (the bin script itself stays a thin CLI wrapper —
 * config loading/argument plumbing only, same convention as
 * MigrationRunner/bin/migrate.php).
 *
 * review-2026-09-04-fixes item 8: a failure purging one account must not
 * abort the whole run — every other expired account still gets processed,
 * the failure is captured via ErrorReporter (Sentry in prod,
 * NullErrorReporter in self-host) and logged as a distinct
 * `account.purge_failed` audit event. `account.purged` is written only
 * AFTER purgeExpired() actually returns successfully, so the audit trail
 * never claims a deletion that didn't happen.
 */
final readonly class ExpiredAccountCleaner
{
    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
        private ErrorReporter $reporter,
    ) {}

    /**
     * @param list<array{id: int, slug: string, deletion_scheduled_at: string}> $expired
     * @return array{purged: int, failed: int}
     */
    public function run(array $expired): array
    {
        $purged = 0;
        $failed = 0;

        foreach ($expired as $account) {
            try {
                $this->accounts->purgeExpired($account['id']);
            } catch (\Throwable $e) {
                $failed++;
                $this->reporter->report($e);
                $this->audit->log('account.purge_failed', [
                    'account_id' => $account['id'],
                    'slug'       => $account['slug'],
                    'deadline'   => $account['deletion_scheduled_at'],
                    'error'      => $e->getMessage(),
                ]);
                continue;
            }

            $purged++;
            $this->audit->log('account.purged', [
                'account_id' => $account['id'],
                'slug'       => $account['slug'],
                'deadline'   => $account['deletion_scheduled_at'],
            ]);
        }

        return ['purged' => $purged, 'failed' => $failed];
    }
}
