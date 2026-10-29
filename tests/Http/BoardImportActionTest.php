<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Import\BoardImportService;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * POST /admin/boards/{slug}/import (issue cp-16): AuthZ, account scoping
 * (self-host and cloud path routing), dry-run default, apply semantics
 * (vote baseline without votes/users, tags, provenance), idempotency and the
 * file-level rejection path.
 */
final class BoardImportActionTest extends IntegrationTestCase
{
    private const CSV = "title,body,status,created_at,votes,tags\n"
        . "Dark mode,Please add a dark theme,planned,2024-03-05 10:20:30,42,\"UI, Theme\"\n"
        . "CSV export,Export ideas as CSV,done,2023-01-01,7,UI\n"
        . ",missing title row,open,,1,\n";

    /** @param array<string, mixed> $body */
    private function post(string $path, ?int $userId, array $body): ServerRequestInterface
    {
        $csrf    = new CsrfService(str_repeat('a', 64), 3600, false);
        $token   = $csrf->generate();
        $cookies = ['votepit_csrf' => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody($body);
    }

    private function admin(string $email = 'import-admin@example.com', string $role = 'admin', ?int $accountId = null): int
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($accountId ?? $this->defaultAccountId(), $userId, $role);

        return $userId;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function ideaCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas');
    }

    // ── AuthZ / scoping ──────────────────────────────────────────────────

    public function test_anon_is_rejected(): void
    {
        $this->insertBoard('demo');
        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', null, ['format' => 'csv', 'content' => self::CSV]));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_moderator_is_forbidden(): void
    {
        $this->insertBoard('demo');
        $moderator = $this->admin('import-mod@example.com', 'moderator');

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $moderator, [
            'format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-moderator-1',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_admin_cannot_import_into_a_foreign_accounts_board(): void
    {
        $foreign = $this->insertAccount(['slug' => 'acct-import-foreign']);
        $this->insertBoard('foreign-board', ['account_id' => $foreign]);
        $admin = $this->admin();

        $response = $this->createApp()->handle($this->post('/admin/boards/foreign-board/import', $admin, [
            'format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-foreign-01', // gitleaks:allow
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_cloud_mode_admin_of_account_b_cannot_import_into_account_a(): void
    {
        $accountA = $this->insertAccount(['slug' => 'tenant-a']);
        $accountB = $this->insertAccount(['slug' => 'tenant-b']);
        $this->insertBoard('roadmap', ['account_id' => $accountA, 'is_default' => 0]);
        $this->insertBoard('roadmap', ['account_id' => $accountB, 'is_default' => 0]);
        $adminB = $this->admin('admin-b@example.com', 'admin', $accountB);

        $app     = $this->createApp(config: Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));
        $payload = ['format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-tenant-xx'];

        $denied = $app->handle($this->post('/tenant-a/admin/boards/roadmap/import', $adminB, $payload));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame(0, $this->ideaCount());

        // Same slug in the admin's own account: lands there and only there.
        $own = $app->handle($this->post('/tenant-b/admin/boards/roadmap/import', $adminB, $payload));
        self::assertSame(200, $own->getStatusCode());
        $boardIds = $this->conn->fetchFirstColumn('SELECT DISTINCT i.board_id FROM ideas i');
        $boardB   = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE account_id = :a', ['a' => $accountB]);
        self::assertSame([$boardB], array_map(intval(...), $boardIds));
    }

    // ── Dry run ──────────────────────────────────────────────────────────

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $this->insertBoard('demo');
        $admin = $this->admin();

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $admin, ['format' => 'csv', 'content' => self::CSV]));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertTrue($data['dry_run']);
        self::assertSame(3, $data['report']['total_rows']);
        self::assertSame(2, $data['report']['importable']);
        self::assertSame(1, $data['report']['skipped']);
        self::assertSame(['UI', 'Theme'], $data['report']['new_tags']);
        self::assertSame([['line' => 4, 'field' => 'title', 'code' => 'title_missing', 'severity' => 'error']], $data['report']['issues']);
        self::assertSame('Dark mode', $data['report']['preview'][0]['title']);
        self::assertSame(42, $data['report']['preview'][0]['votes']);

        self::assertSame(0, $this->ideaCount());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_imports'));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->insertBoard('demo');
        $admin = $this->admin();
        $app   = $this->createApp();

        $badFormat = $app->handle($this->post('/admin/boards/demo/import', $admin, ['format' => 'html', 'content' => self::CSV]));
        self::assertSame(422, $badFormat->getStatusCode());
        self::assertArrayHasKey('format', $this->json($badFormat)['error']['fields']);

        $noKey = $app->handle($this->post('/admin/boards/demo/import', $admin, ['format' => 'csv', 'content' => self::CSV, 'dry_run' => false]));
        self::assertSame(422, $noKey->getStatusCode());
        self::assertArrayHasKey('idempotency_key', $this->json($noKey)['error']['fields']);

        $badFile = $app->handle($this->post('/admin/boards/demo/import', $admin, ['format' => 'csv', 'content' => "name\nx\n"]));
        self::assertSame(422, $badFile->getStatusCode());
        self::assertSame(['key' => 'import_invalid', 'reason' => 'missing_title_column'], array_intersect_key($this->json($badFile)['error'], ['key' => 1, 'reason' => 1]));

        self::assertSame(0, $this->ideaCount());
    }

    // ── Apply ────────────────────────────────────────────────────────────

    /**
     * Board deadlines (broaden-use-cases issue 04): the import is an admin
     * bulk path, not a submission — it stays exempt from closed submission
     * and voting windows (the imported vote counts are a baseline, not votes).
     */
    public function test_apply_is_not_bound_by_closed_submission_or_voting_windows(): void
    {
        $this->insertBoard('demo', [
            'submissions_closes_at' => '2000-01-01 00:00:00',
            'voting_opens_at'       => '2099-01-01 00:00:00',
        ]);

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $this->admin(), [
            'format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-window-001',
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(2, $this->ideaCount());
    }

    public function test_apply_creates_ideas_with_vote_baseline_but_no_votes_or_users(): void
    {
        $boardId   = $this->insertBoard('demo');
        $admin     = $this->admin();
        $usersBefore = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users');

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-apply-0001',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $report = $this->json($response)['report'];
        self::assertSame('ok', $report['result']);
        self::assertIsInt($report['import_id']);

        $ideas = $this->conn->fetchAllAssociative('SELECT * FROM ideas ORDER BY id');
        self::assertCount(2, $ideas);
        self::assertSame('Dark mode', $ideas[0]['title']);
        self::assertSame('planned', $ideas[0]['status']);
        self::assertSame('2024-03-05 10:20:30', $ideas[0]['created_at']);
        self::assertSame(42, (int) $ideas[0]['imported_vote_count']);
        self::assertSame(42, (int) $ideas[0]['score_cache']);
        self::assertSame(0, (int) $ideas[0]['up_count']);
        self::assertSame($admin, (int) $ideas[0]['author_id']);
        self::assertSame($boardId, (int) $ideas[0]['board_id']);
        self::assertSame($report['import_id'], (int) $ideas[0]['import_id']);
        self::assertSame('darkmode', $ideas[0]['title_normalized']);

        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        self::assertSame($usersBefore, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));

        $tags = $this->conn->fetchFirstColumn(
            'SELECT t.name FROM idea_tag_assignments a JOIN idea_tags t ON t.id = a.tag_id WHERE a.idea_id = :id ORDER BY t.name',
            ['id' => $ideas[0]['id']],
        );
        self::assertSame(['Theme', 'UI'], $tags);
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags WHERE board_id = :b', ['b' => $boardId]));

        self::assertStringContainsString('board.import', $this->readAuditLog());
    }

    public function test_imported_idea_is_listed_and_a_real_vote_adds_to_the_baseline(): void
    {
        $this->insertBoard('demo');
        $admin = $this->admin();
        $app   = $this->createApp();
        $app->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => "title,votes\nImported idea,10\n", 'dry_run' => false, 'idempotency_key' => 'key-vote-0001',
        ]));
        $ideaId = (int) $this->conn->fetchOne('SELECT id FROM ideas');

        $voter = $this->insertUser('import-voter@example.com');
        $vote  = $app->handle($this->post("/demo/ideas/{$ideaId}/vote", $voter, ['value' => 1]));
        self::assertSame(200, $vote->getStatusCode(), (string) $vote->getBody());

        self::assertSame(11, (int) $this->conn->fetchOne('SELECT score_cache FROM ideas WHERE id = :id', ['id' => $ideaId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT up_count FROM ideas WHERE id = :id', ['id' => $ideaId]));

        $list = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo')->withHeader('Accept', 'application/json'));
        self::assertStringContainsString('Imported idea', (string) $list->getBody());
    }

    public function test_existing_tags_are_reused_case_insensitively(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->conn->insert('idea_tags', ['board_id' => $boardId, 'name' => 'ui', 'color' => '#ff0000']);
        $admin = $this->admin();

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => "title,tags\nA,UI\n", 'dry_run' => false, 'idempotency_key' => 'key-tags-0001',
        ]));

        self::assertSame([], $this->json($response)['report']['new_tags']);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tags'));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments'));
    }

    public function test_titles_already_on_the_board_or_repeated_in_the_file_are_skipped(): void
    {
        $boardId = $this->insertBoard('demo');
        $admin   = $this->admin();
        $this->seedIdea($boardId, $admin, 'Dark Mode', ['title_normalized' => 'darkmode']);

        $response = $this->createApp()->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => "title\ndark-mode\nNew one\nnew one\n", 'dry_run' => false, 'idempotency_key' => 'key-dupes-001',
        ]));

        $report = $this->json($response)['report'];
        self::assertSame(1, $report['importable']);
        self::assertSame(
            ['2:duplicate_title', '4:duplicate_title'],
            array_map(static fn (array $i): string => $i['line'] . ':' . $i['code'], $report['issues']),
        );
        self::assertSame(2, $this->ideaCount());
    }

    public function test_canny_import_does_not_store_author_identities(): void
    {
        $this->insertBoard('demo');
        $admin = $this->admin();

        $this->createApp()->handle($this->post('/admin/boards/demo/import', $admin, [
            'format'  => 'canny',
            'content' => "Title,Details,Score,Author Name,Author Email\nSSO,Need SSO,5,Jane Doe,jane.doe@example.com\n",
            'dry_run' => false,
            'idempotency_key' => 'key-canny-0001',
        ]));

        self::assertSame(1, $this->ideaCount());
        foreach (['ideas', 'idea_imports', 'users'] as $table) {
            $dump = json_encode($this->conn->fetchAllAssociative("SELECT * FROM {$table}"));
            self::assertIsString($dump);
            self::assertStringNotContainsString('jane', strtolower($dump), "author data leaked into {$table}");
        }
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_replaying_the_same_key_and_file_imports_only_once(): void
    {
        $this->insertBoard('demo');
        $admin   = $this->admin();
        $app     = $this->createApp();
        $payload = ['format' => 'csv', 'content' => self::CSV, 'dry_run' => false, 'idempotency_key' => 'key-replay-001'];

        $first  = $this->json($app->handle($this->post('/admin/boards/demo/import', $admin, $payload)))['report'];
        $second = $app->handle($this->post('/admin/boards/demo/import', $admin, $payload));

        self::assertSame(200, $second->getStatusCode());
        $replayed = $this->json($second)['report'];
        self::assertSame(BoardImportService::RESULT_REPLAYED, $replayed['result']);
        self::assertSame($first['import_id'], $replayed['import_id']);
        self::assertSame(2, $replayed['importable']);
        self::assertSame(2, $this->ideaCount());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_imports'));
    }

    public function test_reusing_a_key_for_a_different_file_is_a_conflict(): void
    {
        $this->insertBoard('demo');
        $admin = $this->admin();
        $app   = $this->createApp();

        $app->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => "title\nFirst\n", 'dry_run' => false, 'idempotency_key' => 'key-conflict-1',
        ]));
        $conflict = $app->handle($this->post('/admin/boards/demo/import', $admin, [
            'format' => 'csv', 'content' => "title\nSecond\n", 'dry_run' => false, 'idempotency_key' => 'key-conflict-1',
        ]));

        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(['First'], $this->conn->fetchFirstColumn('SELECT title FROM ideas'));
    }

    public function test_the_same_key_on_another_board_is_independent(): void
    {
        $this->insertBoard('one');
        $this->insertBoard('two', ['is_default' => 0]);
        $admin   = $this->admin();
        $app     = $this->createApp();
        $payload = ['format' => 'csv', 'content' => "title\nShared\n", 'dry_run' => false, 'idempotency_key' => 'key-shared-001'];

        self::assertSame('ok', $this->json($app->handle($this->post('/admin/boards/one/import', $admin, $payload)))['report']['result']);
        self::assertSame('ok', $this->json($app->handle($this->post('/admin/boards/two/import', $admin, $payload)))['report']['result']);
        self::assertSame(2, $this->ideaCount());
    }

    // ── Export round trip ────────────────────────────────────────────────

    public function test_formula_like_titles_are_neutralised_when_exported_again(): void
    {
        $this->insertBoard('demo');
        $owner = $this->admin('import-owner@example.com', 'owner');
        $app   = $this->createApp();
        $app->handle($this->post('/admin/boards/demo/import', $owner, [
            'format' => 'csv', 'content' => "title,votes\n\"=HYPERLINK(\"\"http://evil\"\")\",3\n", 'dry_run' => false, 'idempotency_key' => 'key-formula-01',
        ]));

        $sess   = (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $owner, 'v' => 0]);
        $export = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/export?format=csv')->withCookieParams(['votepit_sess' => $sess]),
        );
        self::assertSame(200, $export->getStatusCode());

        $tmp = tempnam(sys_get_temp_dir(), 'votepit-import-export-');
        self::assertIsString($tmp);
        file_put_contents($tmp, (string) $export->getBody());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $ideasCsv = (string) $zip->getFromName('ideas.csv');
        $zip->close();
        unlink($tmp);

        self::assertStringContainsString("'=HYPERLINK", $ideasCsv);
        self::assertStringContainsString('imported_vote_count', $ideasCsv);
    }
}
