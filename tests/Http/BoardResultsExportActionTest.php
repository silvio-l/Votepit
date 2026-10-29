<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /admin/boards/{slug}/export — board results export as CSV
 * (broaden-use-cases issue 07) through the real HTTP seam: golden output,
 * default scope (published, non-deleted, non-archived), filters/sort,
 * AuthZ, cross-tenant isolation, rate limit, audit, no author identities.
 */
final class BoardResultsExportActionTest extends IntegrationTestCase
{
    private int $seq = 0;

    public function test_export_matches_the_golden_csv(): void
    {
        [$boardId, $ids] = $this->seedFixtureBoard();
        $owner = $this->owner();

        $response = $this->createApp()->handle($this->get('/admin/boards/demo/export?lang=de', $owner));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="votepit-results-demo-\d{8}-\d{6}\.csv"$/', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $expected = "\xEF\xBB\xBF" . implode("\r\n", [
            'id;title;body;status_key;status_label;status_category;up;down;score;consensus_percent;comments;tags;created_at;target_date',
            $ids['umlaut'] . ';"Grüße aus Köln";"Mit ""Zitat"" und' . "\n" . 'Zeilenumbruch";planned;Geplant;planned;3;1;2;75;2;"Bug, Événement";"2026-09-01 10:00:00";2026-12-24', // export-ok: comment-language
            $ids['formula'] . ';"\'=HYPERLINK(""http://evil.example"")";"\'-1 + 2";s1;Beschlossen;completed;0;0;0;;0;;"2026-09-02 10:00:00";',
        ]) . "\r\n";
        self::assertSame($expected, (string) $response->getBody());
        self::assertGreaterThan(0, $boardId);
    }

    public function test_default_language_uses_comma_and_english_labels(): void
    {
        [, $ids] = $this->seedFixtureBoard();

        $body = (string) $this->createApp()->handle($this->get('/admin/boards/demo/export', $this->owner()))->getBody();

        self::assertStringStartsWith("\xEF\xBB\xBFid,title,body,", $body);
        self::assertStringContainsString($ids['umlaut'] . ',"Grüße aus Köln",', $body); // export-ok: comment-language
        self::assertStringContainsString(',planned,Planned,planned,3,1,2,75,2,', $body);
    }

    public function test_separator_parameter_overrides_the_language_default(): void
    {
        $this->seedFixtureBoard();

        $body = (string) $this->createApp()->handle($this->get('/admin/boards/demo/export?lang=de&sep=comma', $this->owner()))->getBody();

        self::assertStringStartsWith("\xEF\xBB\xBFid,title,", $body);
        self::assertStringContainsString(',Geplant,', $body);
    }

    public function test_pending_deleted_merged_archived_and_foreign_ideas_never_appear(): void
    {
        $this->seedFixtureBoard();
        $body = (string) $this->createApp()->handle($this->get('/admin/boards/demo/export', $this->owner()))->getBody();

        foreach (['Pending idea', 'Deleted idea', 'Merged duplicate', 'Archived idea', 'Foreign secret', 'Other board idea'] as $hidden) {
            self::assertStringNotContainsString($hidden, $body);
        }
        self::assertCount(3, $this->lines($body));
    }

    public function test_status_filter_and_sort_are_respected(): void
    {
        [, $ids] = $this->seedFixtureBoard();
        $owner   = $this->owner();
        $app     = $this->createApp();

        $done = $this->lines((string) $app->handle($this->get('/admin/boards/demo/export?status=done', $owner))->getBody());
        self::assertCount(2, $done);
        self::assertStringStartsWith($ids['formula'] . ',', $done[1]);

        $key = $this->lines((string) $app->handle($this->get('/admin/boards/demo/export?status_key=planned', $owner))->getBody());
        self::assertCount(2, $key);
        self::assertStringStartsWith($ids['umlaut'] . ',', $key[1]);

        // Archived ideas only on the explicit archive filter (same rule as the board list).
        $archived = $this->lines((string) $app->handle($this->get('/admin/boards/demo/export?status=archived', $owner))->getBody());
        self::assertCount(2, $archived);
        self::assertStringContainsString('Archived idea', $archived[1]);

        // Default sort = top (score); newest flips the two fixture ideas.
        $top    = $this->lines((string) $app->handle($this->get('/admin/boards/demo/export', $owner))->getBody());
        $newest = $this->lines((string) $app->handle($this->get('/admin/boards/demo/export?sort=newest', $owner))->getBody());
        self::assertStringStartsWith($ids['umlaut'] . ',', $top[1]);
        self::assertStringStartsWith($ids['formula'] . ',', $newest[1]);

        // Unknown values fall back to the defaults instead of erroring.
        $junk = $this->createApp()->handle($this->get('/admin/boards/demo/export?status=nope&sort=drop&sep=tab', $owner));
        self::assertSame(200, $junk->getStatusCode());
        self::assertSame($top, $this->lines((string) $junk->getBody()));
    }

    public function test_export_contains_no_author_identity(): void
    {
        $this->seedFixtureBoard();

        $body = (string) $this->createApp()->handle($this->get('/admin/boards/demo/export', $this->owner()))->getBody();

        self::assertStringNotContainsString('author', $body);
        foreach ($this->conn->fetchFirstColumn('SELECT public_id FROM users') as $publicId) {
            self::assertStringNotContainsString((string) $publicId, $body);
        }
    }

    public function test_requires_account_admin(): void
    {
        $this->seedFixtureBoard();
        $moderator = $this->insertUser('mod@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderator, 'moderator');
        $admin = $this->insertUser('admin@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $admin, 'admin');
        $app = $this->createApp();

        self::assertSame(401, $app->handle($this->get('/admin/boards/demo/export', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/admin/boards/demo/export', $moderator))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/admin/boards/demo/export', $this->insertUser('voter@example.com')))->getStatusCode());
        self::assertSame(200, $app->handle($this->get('/admin/boards/demo/export', $admin))->getStatusCode());
    }

    public function test_foreign_accounts_board_is_not_addressable(): void
    {
        $this->seedFixtureBoard();

        $response = $this->createApp()->handle($this->get('/admin/boards/foreign-board/export', $this->owner()));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Foreign secret', (string) $response->getBody());
    }

    public function test_rate_limit_is_enforced(): void
    {
        $this->seedFixtureBoard();
        $owner = $this->owner();
        $app   = $this->createApp(config: \Votepit\Config::fromArray($this->testConfigArray([
            'rate_limits' => ['board:export' => ['limit' => 2, 'window' => 3600]],
        ])));

        self::assertSame(200, $app->handle($this->get('/admin/boards/demo/export', $owner))->getStatusCode());
        self::assertSame(200, $app->handle($this->get('/admin/boards/demo/export', $owner))->getStatusCode());
        self::assertSame(429, $app->handle($this->get('/admin/boards/demo/export', $owner))->getStatusCode());
    }

    public function test_export_is_audit_logged_without_content(): void
    {
        [$boardId] = $this->seedFixtureBoard();
        $owner     = $this->owner();

        $this->createApp()->handle($this->get('/admin/boards/demo/export?status=done&sort=newest', $owner));

        $log = $this->readAuditLog();
        self::assertStringContainsString('"action":"board.results.exported"', $log);
        self::assertStringContainsString('"board_id":' . $boardId, $log);
        self::assertStringContainsString('"actor_id":' . $owner, $log);
        self::assertStringContainsString('"rows":1', $log);
        self::assertStringNotContainsString('HYPERLINK', $log);
    }

    // ── Fixture ───────────────────────────────────────────────────────────

    /** @return array{0: int, 1: array{umlaut: int, formula: int}} */
    private function seedFixtureBoard(): array
    {
        $boardId = $this->insertBoard('demo', ['statuses_customized' => 1]);
        foreach (['open', 'planned', 'in_progress', 'done', 'declined', 'archived'] as $i => $key) {
            $this->conn->insert('board_statuses', ['board_id' => $boardId, 'status_key' => $key, 'category' => $key, 'position' => $i, 'is_system' => 1]);
        }
        $this->conn->insert('board_statuses', [
            'board_id' => $boardId, 'status_key' => 's1', 'category' => 'done',
            'label_de' => 'Beschlossen', 'label_en' => 'Agreed', 'position' => 10, 'is_system' => 0,
        ]);
        $author = $this->insertUser('author-' . (++$this->seq) . '@example.com');

        $umlaut = $this->seedIdea($boardId, $author, 'Grüße aus Köln', [ // export-ok: comment-language
            'body' => 'Mit "Zitat" und' . "\n" . 'Zeilenumbruch', 'status' => 'planned', // export-ok: comment-language
            'up_count' => 3, 'down_count' => 1, 'score_cache' => 2, 'comment_count' => 2,
            'created_at' => '2026-09-01 10:00:00', 'target_date' => '2026-12-24',
        ]);
        $this->assignTag($umlaut, $this->insertTag($boardId, 'Événement'));
        $this->assignTag($umlaut, $this->insertTag($boardId, 'Bug'));

        $formula = $this->seedIdea($boardId, $author, '=HYPERLINK("http://evil.example")', [
            'body' => '-1 + 2', 'status' => 's1', 'created_at' => '2026-09-02 10:00:00',
        ]);

        $this->seedIdea($boardId, $author, 'Pending idea', ['moderation_state' => 'pending', 'created_at' => '2026-09-03 10:00:00']);
        $this->seedIdea($boardId, $author, 'Deleted idea', ['deleted_at' => '2026-09-04 10:00:00']);
        $this->seedIdea($boardId, $author, 'Merged duplicate', ['merged_into_id' => $umlaut, 'merged_at' => '2026-09-05 10:00:00', 'score_cache' => 9]);
        $this->seedIdea($boardId, $author, 'Archived idea', ['status' => 'archived', 'created_at' => '2026-08-01 10:00:00']);

        $otherBoard = $this->insertBoard('other');
        $this->seedIdea($otherBoard, $author, 'Other board idea', ['score_cache' => 9]);

        $foreign      = $this->insertAccount(['slug' => 'acct-foreign-export']);
        $foreignBoard = $this->insertBoard('foreign-board', ['account_id' => $foreign]);
        $this->seedIdea($foreignBoard, $author, 'Foreign secret', ['score_cache' => 9]);

        return [$boardId, ['umlaut' => $umlaut, 'formula' => $formula]];
    }

    private function owner(): int
    {
        $id = $this->insertUser('owner-' . (++$this->seq) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $id, 'owner');

        return $id;
    }

    /** @return list<string> logical CSV records (header first) of a comma export */
    private function lines(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        self::assertNotFalse($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $out = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $out[] = implode(',', array_map(strval(...), $row));
        }
        fclose($stream);

        return $out;
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        [, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query)
            ->withHeader('User-Agent', 'export-test-' . (++$this->seq));

        return $userId !== null
            ? $request->withCookieParams(['votepit_sess' => (new SessionService(str_repeat('a', 64), 3600, false))->sign(['uid' => $userId, 'v' => 0])])
            : $request;
    }
}
