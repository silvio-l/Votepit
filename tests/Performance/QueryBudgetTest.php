<?php

declare(strict_types=1);

namespace Votepit\Tests\Performance;

use Doctrine\DBAL\Configuration;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\QueryCounting\QueryCountingMiddleware;
use Votepit\Tests\Support\QueryCounting\QueryLog;

/**
 * Query budgets for the key journeys (competitor-parity M2).
 *
 * Every journey is driven through the real HTTP seam (AppFactory, full
 * middleware pipeline) and the number of SQL statements the DB driver
 * executes for the single measured request is counted by
 * QueryCountingMiddleware. Two guarantees per journey:
 *
 *  1. Row-count independence (no N+1): the request issues exactly the same
 *     number of statements with SMALL rows as with LARGE rows (ideas,
 *     comments, appeals, boards — whatever the journey lists).
 *  2. Budget: that number does not exceed the journey's constant below.
 *
 * A failing budget prints the executed statements. If a change legitimately
 * needs another query, raise the constant in the same commit and say why;
 * if the count started to depend on row count, fix the N+1 instead.
 *
 * The counts are measured on the SQLite test schema; the repositories use
 * portable SQL, so the statement count is the same on MySQL.
 */
final class QueryBudgetTest extends IntegrationTestCase
{
    // Budgets: maximum statements per request, set to the measured count
    // (2026-10-01) so any additional query fails loudly. Includes the
    // pipeline's own bookkeeping (account resolution, session user, member
    // role, rate-limit upsert = 3 statements, view dedup, plan lookup).
    // 13 since G-09 (2026-10-22): one statement for the board's tags with their idea counts
    // (rail and tag menu). Still one fixed statement, no per-tag or per-idea query.
    // 14 since board imagery (P-28): one statement for the board's stored image
    // renditions (BoardAssetRepository::listForBoard), constant per board.
    // 15 for the idea detail since board branding on the idea page (W3/W6): one
    // statement, AccountMemberRepository::isOperatorMember (operator-owned accounts
    // keep full branding for every visitor, 96e576fc). Constant per request.
    private const BUDGET_BOARD_HOME     = 14;
    private const BUDGET_IDEA_DETAIL    = 15;
    private const BUDGET_ROADMAP        = 8; // +1: board image ids of the shared branding payload (one constant query)
    private const BUDGET_VOTE           = 13;
    // +1 (issue 18): one indexed lookup of the board's Slack/Discord chat
    // integrations (board_chat_integrations by board_id, joined with the
    // plan) next to the existing board_webhooks lookup — constant per
    // request, independent of N; the actual HTTP delivery runs post-response.
    private const BUDGET_SUBMIT_IDEA    = 14;
    private const BUDGET_APPEAL_INBOX   = 4;
    // +1 (issue 06, ADR 0006): one indexed lookup of the guest-placeholder
    // user id (users by email_hmac) for the output-only `author_type` —
    // constant per request, skipped when the page is empty.
    private const BUDGET_API_IDEAS_LIST = 9;
    private const BUDGET_DISCOVER       = 4;
    // 7 = 6 before the operator-member rule; +1 is AccountPlanResolver's
    // isOperatorMember() check (AccountPlanResolver, EffectivePlan::resolve).
    private const BUDGET_ADMIN_BOARDS   = 7;
    private const BUDGET_RESULTS_EXPORT = 9;
    // P-06 worklist: 6 = pipeline lookups (account, user, role = 3) + header counts (1)
    // + the page rows (1) + the guest-placeholder lookup for pending rows (1). Constant
    // per request; the guest lookup runs only when the page holds pending ideas.
    private const BUDGET_WORKLIST       = 6;

    private const SMALL = 1;
    private const LARGE = 25;

    private ?QueryLog $queryLog = null;

    private int $userSeq = 0;

    protected function connectionConfiguration(): Configuration
    {
        return (new Configuration())->setMiddlewares([new QueryCountingMiddleware($this->log())]);
    }

    // -------------------------------------------------------------------------
    // Journeys
    // -------------------------------------------------------------------------

    public function test_operator_worklist(): void
    {
        $boardId = $this->insertBoard('qb-worklist');
        $owner   = $this->newUser();
        $this->insertAccountMember($this->defaultAccountId(), $owner, 'owner');

        // Waiting ideas (older than the waiting threshold) and pending ideas on one board,
        // so the page carries both categories and the guest lookup is exercised.
        $this->assertJourneyBudget(
            'operator worklist (GET /admin/worklist)',
            self::BUDGET_WORKLIST,
            function (int $n) use ($boardId, $owner): void {
                for ($i = 0; $i < $n; $i++) {
                    $this->seedIdea($boardId, $owner, "Waiting {$i}", ['created_at' => (new \DateTimeImmutable())->modify('-4 days')->format('Y-m-d H:i:s')]);
                    $this->seedIdea($boardId, $owner, "Pending {$i}", ['moderation_state' => 'pending']);
                }
            },
            fn (): ServerRequestInterface => $this->get('/admin/worklist', $owner),
        );
    }

    public function test_board_home_idea_list(): void
    {
        $boardId = $this->insertBoard('qb-home');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();

        $this->assertJourneyBudget(
            'board home (GET /{board})',
            self::BUDGET_BOARD_HOME,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            fn (): ServerRequestInterface => $this->get('/qb-home', $this->newUser()),
        );
    }

    public function test_board_home_search(): void
    {
        $boardId = $this->insertBoard('qb-search');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();

        // Search (H6) narrows the same list + count queries — it must not add
        // a statement, hence the board-home budget.
        $this->assertJourneyBudget(
            'board search (GET /{board}?q=)',
            self::BUDGET_BOARD_HOME,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            fn (): ServerRequestInterface => $this->get('/qb-search', $this->newUser())
                ->withQueryParams(['q' => 'idea']),
        );
    }

    public function test_board_home_controversial_sort(): void
    {
        $boardId = $this->insertBoard('qb-controversial');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();

        // G-11: the controversial order reads the denormalised counters of the same row,
        // so it must not add a statement either.
        $this->assertJourneyBudget(
            'board home controversial sort (GET /{board}?sort=controversial)',
            self::BUDGET_BOARD_HOME,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            fn (): ServerRequestInterface => $this->get('/qb-controversial', $this->newUser())
                ->withQueryParams(['sort' => 'controversial']),
        );
    }

    public function test_idea_detail(): void
    {
        $boardId = $this->insertBoard('qb-detail');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();
        $ideaId  = $this->seedIdea($boardId, $crowd, 'Detail idea');
        $this->assignTag($ideaId, $tagId);
        $this->seedVote($ideaId, $crowd, 1);

        $this->assertJourneyBudget(
            'idea detail (GET /{board}/ideas/{id})',
            self::BUDGET_IDEA_DETAIL,
            function (int $n) use ($ideaId): void {
                for ($i = 0; $i < $n; ++$i) {
                    $author    = $this->newUser();
                    $commentId = $this->seedComment($ideaId, $author, 'Comment ' . $i);
                    $this->seedReaction($commentId, $author);
                }
            },
            fn (): ServerRequestInterface => $this->get('/qb-detail/ideas/' . $ideaId, $this->newUser()),
        );
    }

    public function test_roadmap(): void
    {
        $boardId = $this->insertBoard('qb-roadmap');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();

        $this->assertJourneyBudget(
            'roadmap (GET /{board}/roadmap)',
            self::BUDGET_ROADMAP,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n, ['status' => 'planned']),
            fn (): ServerRequestInterface => $this->get('/qb-roadmap/roadmap', $this->newUser()),
        );
    }

    public function test_vote(): void
    {
        $boardId = $this->insertBoard('qb-vote');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();
        $target  = $this->seedIdea($boardId, $crowd, 'Vote target');

        $this->assertJourneyBudget(
            'vote (POST /{board}/ideas/{id}/vote)',
            self::BUDGET_VOTE,
            function (int $n) use ($boardId, $tagId, $crowd, $target): void {
                $this->seedRichIdeas($boardId, $tagId, $crowd, $n);
                for ($i = 0; $i < $n; ++$i) {
                    $this->seedVote($target, $this->newUser(), 1);
                }
            },
            fn (): ServerRequestInterface => $this->post('/qb-vote/ideas/' . $target . '/vote', ['value' => 'up'], $this->newUser()),
        );
    }

    public function test_submit_idea(): void
    {
        $boardId = $this->insertBoard('qb-submit');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();
        $seq     = 0;

        $this->assertJourneyBudget(
            'submit idea (POST /{board}/ideas)',
            self::BUDGET_SUBMIT_IDEA,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            function () use (&$seq): ServerRequestInterface {
                ++$seq;

                return $this->post('/qb-submit/ideas', [
                    'title'    => 'Completely unrelated proposal number ' . $seq,
                    'body'     => 'A description long enough to be accepted.',
                    '_form_at' => $this->validTimeTrap(),
                ], $this->newUser());
            },
        );
    }

    public function test_moderation_appeal_inbox(): void
    {
        $boardId     = $this->insertBoard('qb-appeals');
        $moderatorId = $this->newUser();
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');

        $this->assertJourneyBudget(
            'appeal inbox (GET /moderation/appeals)',
            self::BUDGET_APPEAL_INBOX,
            function (int $n) use ($boardId, $moderatorId): void {
                for ($i = 0; $i < $n; ++$i) {
                    $authorId = $this->newUser();
                    $ideaId   = $this->seedIdea($boardId, $authorId, 'Removed idea ' . $i);
                    $actionId = $this->seedModerationAction('idea', $ideaId, $boardId, $this->defaultAccountId(), $moderatorId);
                    $this->conn->update(
                        'ideas',
                        ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId],
                        ['id' => $ideaId],
                    );
                    $this->seedAppeal($actionId, $authorId);
                }
            },
            fn (): ServerRequestInterface => $this->get('/moderation/appeals', $moderatorId),
        );
    }

    public function test_api_v1_ideas_list(): void
    {
        $boardId = $this->insertBoard('qb-api');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();
        $pair    = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $crowd, $pair['hash'], 'Budget', scope: 'read');

        $this->assertJourneyBudget(
            'api/v1 ideas list (GET /api/v1/ideas)',
            self::BUDGET_API_IDEAS_LIST,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            fn (): ServerRequestInterface => (new ServerRequestFactory())
                ->createServerRequest('GET', '/api/v1/ideas')
                ->withHeader('Authorization', 'Bearer ' . $pair['token']),
        );
    }

    public function test_board_discovery(): void
    {
        $crowd = $this->newUser();
        $seq   = 0;

        $this->assertJourneyBudget(
            'board discovery (GET /discover)',
            self::BUDGET_DISCOVER,
            function (int $n) use ($crowd, &$seq): void {
                for ($i = 0; $i < $n; ++$i) {
                    ++$seq;
                    $accountId = $this->insertAccount(['slug' => 'qb-acct-' . $seq]);
                    $boardId   = $this->insertBoard('qb-disc-' . $seq, ['account_id' => $accountId, 'visibility' => 'public']);
                    $this->seedVote($this->seedIdea($boardId, $crowd), $crowd, 1);
                }
            },
            fn (): ServerRequestInterface => $this->get('/discover', null),
        );
    }

    public function test_admin_board_list(): void
    {
        $ownerId = $this->newUser();
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $crowd = $this->newUser();
        $seq   = 0;

        $this->assertJourneyBudget(
            'admin board list (GET /admin/boards)',
            self::BUDGET_ADMIN_BOARDS,
            function (int $n) use ($crowd, &$seq): void {
                for ($i = 0; $i < $n; ++$i) {
                    ++$seq;
                    $boardId = $this->insertBoard('qb-admin-' . $seq);
                    $this->seedVote($this->seedIdea($boardId, $crowd), $crowd, 1);
                }
            },
            fn (): ServerRequestInterface => $this->get('/admin/boards', $ownerId),
        );
    }

    public function test_board_results_export(): void
    {
        $boardId = $this->insertBoard('qb-export');
        $tagId   = $this->insertTag($boardId);
        $crowd   = $this->newUser();
        $ownerId = $this->newUser();
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $this->assertJourneyBudget(
            'board results export (GET /admin/boards/{slug}/export)',
            self::BUDGET_RESULTS_EXPORT,
            fn (int $n) => $this->seedRichIdeas($boardId, $tagId, $crowd, $n),
            fn (): ServerRequestInterface => $this->get('/admin/boards/qb-export/export', $ownerId),
        );
    }

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    /**
     * Seeds SMALL rows, measures one request, tops up to LARGE rows,
     * measures a second (fresh-viewer) request; asserts both counts are equal
     * and within the budget.
     *
     * @param \Closure(int): void                  $seed    adds N rows of the listed entity
     * @param \Closure(): ServerRequestInterface    $request builds the measured request
     */
    private function assertJourneyBudget(string $journey, int $budget, \Closure $seed, \Closure $request): void
    {
        $seed(self::SMALL);
        [$small, $smallSql] = $this->measure($journey, $request);

        $seed(self::LARGE - self::SMALL);
        [$large, $largeSql] = $this->measure($journey, $request);

        self::assertSame(
            $small,
            $large,
            sprintf(
                "%s: query count depends on row count (%d rows: %d queries, %d rows: %d queries) — N+1?\n--- %d rows ---\n%s\n--- %d rows ---\n%s",
                $journey,
                self::SMALL,
                $small,
                self::LARGE,
                $large,
                self::SMALL,
                $smallSql,
                self::LARGE,
                $largeSql,
            ),
        );
        self::assertLessThanOrEqual(
            $budget,
            $large,
            sprintf("%s: %d queries exceed the budget of %d.\n%s", $journey, $large, $budget, $largeSql),
        );
    }

    /**
     * @param \Closure(): ServerRequestInterface $request
     * @return array{0: int, 1: string}
     */
    private function measure(string $journey, \Closure $request): array
    {
        // Build app + request first: only the handled request is measured.
        $app         = $this->createApp();
        $httpRequest = $request();

        $this->log()->reset();
        $response = $app->handle($httpRequest);
        $count    = $this->log()->count();
        $dump     = $this->log()->dump();

        $status = $response->getStatusCode();
        self::assertTrue(
            $status >= 200 && $status < 300,
            sprintf("%s: expected a 2xx response, got %d: %s", $journey, $status, (string) $response->getBody()),
        );

        return [$count, $dump];
    }

    private function log(): QueryLog
    {
        return $this->queryLog ??= new QueryLog();
    }

    private function newUser(): int
    {
        ++$this->userSeq;

        return $this->insertUser('qb-user-' . $this->userSeq . '@example.com');
    }

    /**
     * Seeds N ideas that each carry a vote, a comment and a tag — so any
     * per-idea lookup (votes, comments, tags, author) shows up as N+1.
     *
     * @param array<string, mixed> $overrides
     */
    private function seedRichIdeas(int $boardId, int $tagId, int $crowd, int $n, array $overrides = []): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $author = $this->newUser();
            $ideaId = $this->seedIdea($boardId, $author, 'Idea ' . $this->userSeq . ' ' . $i, $overrides);
            $this->seedVote($ideaId, $crowd, 1);
            $this->seedComment($ideaId, $crowd);
            $this->assignTag($ideaId, $tagId);
        }
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        // Unique User-Agent: IdeaViewTracker dedups views per IP+UA, so every
        // measured idea-detail request is a counted first view.
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withHeader('User-Agent', 'query-budget-' . (++$this->userSeq));

        return $userId !== null
            ? $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            : $request;
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body, int $userId): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), 'votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /** Time-trap stamp backdated 5 s (same MAC as TimeTrapService), see IdeaSubmitActionTest. */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        return $ts . '.' . $mac;
    }
}
