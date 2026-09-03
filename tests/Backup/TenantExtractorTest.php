<?php

declare(strict_types=1);

namespace Votepit\Tests\Backup;

use Votepit\Backup\BackupException;
use Votepit\Backup\TenantExtractor;
use Votepit\Persistence\AccountRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * TenantExtractor tests, run against
 * the SQLite test harness (per the task: per-tenant restore is verified via
 * extraction correctness + cross-tenant isolation, not via an actual second
 * live database import). Mirrors this repo's established cross-tenant-leak
 * test discipline (AccountExportActionTest, BoardRepository tests, ...).
 */
final class TenantExtractorTest extends IntegrationTestCase
{
    private function extractor(): TenantExtractor
    {
        return new TenantExtractor($this->conn, new AccountRepository($this->conn));
    }

    /** @return array{account_id: int, board_id: int, idea_id: int} */
    private function seedAccount(string $tag): array
    {
        $accountId = $this->insertAccount(['slug' => 'acct-' . $tag, 'name' => 'Account ' . $tag]);
        $ownerId   = $this->insertUser('owner-' . $tag . '@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $boardId = $this->insertBoard('board-' . $tag, ['account_id' => $accountId]);
        $ideaId  = $this->seedIdea($boardId, $ownerId, 'Idea-' . $tag);
        $this->seedVote($ideaId, $ownerId, 1);
        $this->seedComment($ideaId, $ownerId, 'Comment-' . $tag);

        return ['account_id' => $accountId, 'board_id' => $boardId, 'idea_id' => $ideaId];
    }

    public function test_extract_by_id_returns_only_this_accounts_rows(): void
    {
        $a = $this->seedAccount('alpha');
        $this->seedAccount('beta');

        $document = $this->extractor()->extractById($a['account_id']);

        self::assertSame($a['account_id'], (int) $document['account']['id']);

        self::assertCount(1, $document['boards']);
        self::assertSame('board-alpha', $document['boards'][0]['slug']);

        self::assertCount(1, $document['ideas']);
        self::assertSame('Idea-alpha', $document['ideas'][0]['title']);

        // Nothing from account "beta" leaked into "alpha"'s extraction.
        foreach ($document['boards'] as $board) {
            self::assertNotSame('board-beta', $board['slug']);
        }
    }

    public function test_extract_by_slug_resolves_the_account_and_extracts_the_same_document(): void
    {
        $a = $this->seedAccount('gamma');

        $bySlug = $this->extractor()->extractBySlug('acct-gamma');
        $byId   = $this->extractor()->extractById($a['account_id']);

        self::assertNotNull($bySlug);
        // Compare everything except exported_at (a fresh timestamp per call).
        unset($bySlug['exported_at'], $byId['exported_at']);
        self::assertSame($byId, $bySlug);
    }

    public function test_extract_by_slug_returns_null_for_unknown_slug(): void
    {
        self::assertNull($this->extractor()->extractBySlug('does-not-exist'));
    }

    public function test_extraction_across_many_tenants_never_cross_contaminates(): void
    {
        $seeded = [
            'one'   => $this->seedAccount('one'),
            'two'   => $this->seedAccount('two'),
            'three' => $this->seedAccount('three'),
        ];

        foreach ($seeded as $tag => $info) {
            $document = $this->extractor()->extractById($info['account_id']);

            self::assertSame($info['account_id'], (int) $document['account']['id']);

            foreach ($document['boards'] as $board) {
                self::assertSame('board-' . $tag, $board['slug']);
            }

            foreach ($document['ideas'] as $idea) {
                self::assertSame('Idea-' . $tag, $idea['title']);
            }
        }
    }

    public function test_extract_by_id_throws_backup_exception_style_error_for_a_leaked_board(): void
    {
        // Simulates a scoping regression: a board whose account_id was
        // mutated out from under the account after the export document was
        // read but before verify() re-checks it directly against the DB.
        // (In practice AccountExportService's own query already prevents
        // this row from appearing at all — this test exercises verify()'s
        // defensive re-check path in isolation.)
        $a = $this->seedAccount('delta');
        $b = $this->seedAccount('epsilon');

        $extractor = $this->extractor();
        $document  = $extractor->extractById($a['account_id']);

        // Re-point the extracted board's true account_id at a different
        // account directly in the DB, then re-verify the (now stale)
        // document — this is exactly the failure mode verify() exists to
        // catch, exercised by forcing the mismatch rather than waiting for
        // a real regression.
        $this->conn->executeStatement(
            'UPDATE boards SET account_id = :other WHERE id = :id',
            ['other' => $b['account_id'], 'id' => $document['boards'][0]['id']],
        );

        $this->expectException(BackupException::class);
        $this->expectExceptionMessageMatches('/cross-tenant leak/');

        // Calling the private verify() indirectly by re-extracting would no
        // longer include the mutated board for account "delta" (the WHERE
        // account_id scoping in AccountExportService already excludes it) —
        // so instead directly assert TenantExtractor's own defensive check
        // via reflection on the already-built document, proving verify()
        // itself (not just the upstream scoping) is the thing catching this.
        $reflection = new \ReflectionMethod(TenantExtractor::class, 'verify');
        $reflection->invoke($extractor, $a['account_id'], $document);
    }
}
