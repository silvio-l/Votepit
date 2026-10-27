<?php

declare(strict_types=1);

namespace Votepit\Cleanup;

use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\UploadTermsConsentRepository;
use Votepit\Security\IdentityHasher;

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
        // GDPR purge: image files of every board of the account go first (the
        // rows cascade with the account, the files would otherwise remain).
        private ?BoardAssetCleanup $assetCleanup = null,
        // Upload-clause consent proof (migration 0101): pseudonymised before the
        // account row goes, so the proof outlives the account without a link to it.
        private ?UploadTermsConsentRepository $consents = null,
        private ?IdentityHasher $identityHasher = null,
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
                $this->assetCleanup?->purgeForAccount($account['id']);
                if ($this->consents instanceof \Votepit\Persistence\UploadTermsConsentRepository && $this->identityHasher instanceof \Votepit\Security\IdentityHasher) {
                    $this->consents->pseudonymiseForAccount($account['id'], $this->identityHasher, new \DateTimeImmutable());
                }
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
