<?php

declare(strict_types=1);

namespace Votepit\Backup;

use Doctrine\DBAL\Connection;
use Votepit\Domain\AccountExportService;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\VoteRepository;

/**
 * Per-tenant restore capability: given a full restored database COPY (never the live
 * configured DB — that distinction is the caller's job, same as
 * RestoreRehearsal/bin/verify-backup-restore.php) and one account, extracts
 * that single account's full data as a re-importable document, with zero
 * rows belonging to any other account.
 *
 * Deliberately reuses AccountExportService (customer self-export)
 * instead of re-deriving the account-scoped table graph a third time —
 * bin/cleanup-expired-accounts.php enumerates it for cascading DELETE,
 * AccountExportService enumerates the identical set for GDPR export, and
 * this class is the same enumeration once more for "extract just this one
 * account out of a full restored copy". One canonical list, three
 * consumers.
 *
 * The cross-tenant-leak discipline this repo applies everywhere
 * (BoardRepository/IdeaRepository/AccountExportService docblocks) is
 * enforced a second time here, defensively: verify() re-checks that every
 * extracted board actually belongs to the requested account before the
 * document is considered good. This is belt-and-suspenders on top of
 * AccountExportService's own account_id-scoped queries, not a replacement
 * for them — a per-tenant RESTORE tool is exactly the kind of place where a
 * silent regression in the underlying scoping would be catastrophic
 * (an operator would otherwise import a stranger's data into their own
 * account without ever noticing).
 */
final readonly class TenantExtractor
{
    private AccountExportService $export;

    public function __construct(private Connection $conn, private AccountRepository $accounts)
    {
        $this->export = new AccountExportService(
            $this->accounts,
            new AccountMemberRepository($this->conn),
            new BoardRepository($this->conn),
            new IdeaRepository($this->conn),
            new VoteRepository($this->conn),
            new CommentRepository($this->conn),
            new InviteRepository($this->conn),
            new ApiTokenRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            new ModerationConfigRepository($this->conn),
            new BlockRepository($this->conn),
        );
    }

    /**
     * Resolves an account by slug, then extracts it. Null if the slug is
     * unknown.
     *
     * @return array<string, mixed>|null
     * @throws BackupException if the extracted document fails the
     *                          cross-tenant verification below.
     */
    public function extractBySlug(string $slug): ?array
    {
        $account = $this->accounts->findBySlug($slug);
        if ($account === null) {
            return null;
        }

        return $this->extractById((int) $account['id']);
    }

    /**
     * @return array<string, mixed>
     * @throws BackupException if the extracted document contains any row
     *                          attributable to a different account (should
     *                          be structurally impossible — see class doc).
     */
    public function extractById(int $accountId): array
    {
        $document = $this->export->build($accountId);
        $this->verify($accountId, $document);

        return $document;
    }

    /**
     * Defensive re-check, independent of AccountExportService's own
     * scoping: for every board id the export produced, re-read
     * `boards.account_id` DIRECTLY from source (not from the export
     * document — listFullForAccount() doesn't even select account_id, by
     * design, since callers already know it) and assert it is $accountId.
     * A regression anywhere in AccountExportService's WHERE-clause scoping
     * would surface here as a board whose true account_id disagrees.
     *
     * @param array<string, mixed> $document
     * @throws BackupException
     */
    private function verify(int $accountId, array $document): void
    {
        $account = $document['account'] ?? [];
        if ($account !== [] && (int) ($account['id'] ?? -1) !== $accountId) {
            throw new BackupException(
                "TenantExtractor: extracted account record belongs to account #{$account['id']}, expected #{$accountId}.",
            );
        }

        /** @var list<array<string, mixed>> $boards */
        $boards = $document['boards'] ?? [];
        foreach ($boards as $board) {
            $boardId        = (int) $board['id'];
            $trueAccountId  = (int) $this->conn->fetchOne('SELECT account_id FROM boards WHERE id = :id', ['id' => $boardId]);

            if ($trueAccountId !== $accountId) {
                throw new BackupException(
                    "TenantExtractor: cross-tenant leak — board #{$boardId} belongs to account "
                    . "#{$trueAccountId} according to the DB, expected exclusively #{$accountId}.",
                );
            }
        }
    }
}
