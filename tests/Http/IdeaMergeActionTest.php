<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingDuplicateConfirmer;
use Votepit\Tests\Support\StubExtension;

/**
 * Merging duplicate ideas (competitor-parity issue 10) through the HTTP seam:
 *   POST /{board}/ideas/{id}/merge           {target_id}
 *   POST /{board}/ideas/{id}/unmerge
 *   GET  /{board}/ideas/{id}/merge-candidates?q=
 * all accountModerate (owner|admin|moderator). Same board only, no chains,
 * no merging an idea that already has merged children, never into itself.
 * Cross-tenant: a target from another account/board is never touched.
 */
final class IdeaMergeActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /** @param array<string, string> $query */
    private function get(string $path, ?int $userId, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)->withQueryParams($query);
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    private function moderator(string $email = 'mod@example.com', string $role = 'moderator', ?int $accountId = null): int
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($accountId ?? $this->defaultAccountId(), $userId, $role);

        return $userId;
    }

    private function mergedInto(int $ideaId): ?int
    {
        $value = $this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = :id', ['id' => $ideaId]);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} [boardId, authorId, targetId, sourceId] */
    private function pair(string $slug = 'demo'): array
    {
        $boardId = $this->insertBoard($slug);
        $author  = $this->insertUser($slug . '-author@example.com');
        $target  = $this->seedIdea($boardId, $author, 'Dark mode');
        $source  = $this->seedIdea($boardId, $author, 'Dark mode please');

        return [$boardId, $author, $target, $source];
    }

    // --- Happy path -------------------------------------------------------

    public function test_moderator_merges_a_duplicate_which_then_points_at_its_target(): void
    {
        [, , $target, $source] = $this->pair();
        $mod   = $this->moderator();
        $voter = $this->insertUser('voter@example.com');
        $this->seedVote($source, $voter, 1);

        $app      = $this->createApp();
        $response = $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'idea_id' => $source, 'merged_into' => ['id' => $target, 'title' => 'Dark mode']], $this->json($response));
        self::assertSame($target, $this->mergedInto($source));

        // Hidden from the board list, counted once on the target.
        $home = $this->json($app->handle($this->get('/demo', null)));
        self::assertSame([$target], array_map(static fn (array $i): int => (int) $i['id'], $home['ideas']));
        self::assertSame(1, (int) $home['ideas'][0]['up_count']);

        // An old link to the duplicate still resolves and points at the target.
        $detail = $app->handle($this->get("/demo/ideas/{$source}", null));
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(['id' => $target, 'title' => 'Dark mode'], $this->json($detail)['idea']['merged_into']);
        self::assertNull($this->json($app->handle($this->get("/demo/ideas/{$target}", null)))['idea']['merged_into']);
    }

    public function test_merged_duplicate_accepts_no_votes_or_comments(): void
    {
        [, , $target, $source] = $this->pair();
        $mod  = $this->moderator();
        $user = $this->insertUser('late@example.com');
        $app  = $this->createApp();
        $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        self::assertSame(404, $app->handle($this->post("/demo/ideas/{$source}/vote", $user, ['value' => 1]))->getStatusCode());
        self::assertSame(404, $app->handle($this->post("/demo/ideas/{$source}/comments", $user, ['body' => 'Me too!']))->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $source]));
    }

    public function test_moderators_see_the_merged_duplicates_on_the_canonical_idea(): void
    {
        [, , $target, $source] = $this->pair();
        $mod = $this->moderator();
        $app = $this->createApp();
        $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        $asModerator = $this->json($app->handle($this->get("/demo/ideas/{$target}", $mod)));
        self::assertSame([['id' => $source, 'title' => 'Dark mode please']], $asModerator['idea']['merged_ideas']);

        $asVisitor = $this->json($app->handle($this->get("/demo/ideas/{$target}", null)));
        self::assertSame([], $asVisitor['idea']['merged_ideas']);
    }

    // --- AuthZ ----------------------------------------------------------

    public function test_anonymous_is_401_and_plain_member_is_403(): void
    {
        [, , $target, $source] = $this->pair();
        $member = $this->moderator('member@example.com', 'member');
        $app    = $this->createApp();

        self::assertSame(401, $app->handle($this->post("/demo/ideas/{$source}/merge", null, ['target_id' => $target]))->getStatusCode());
        self::assertSame(403, $app->handle($this->post("/demo/ideas/{$source}/merge", $member, ['target_id' => $target]))->getStatusCode());
        self::assertSame(403, $app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $member))->getStatusCode());
        self::assertNull($this->mergedInto($source));
    }

    // --- Merge rules ------------------------------------------------------

    public function test_cannot_merge_into_itself(): void
    {
        [, , , $source] = $this->pair();
        $response = $this->createApp()->handle($this->post("/demo/ideas/{$source}/merge", $this->moderator(), ['target_id' => $source]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('merge_invalid_target', $this->json($response)['error']['key']);
    }

    public function test_cannot_merge_into_an_already_merged_idea(): void
    {
        [$boardId, $author, $target, $source] = $this->pair();
        $third = $this->seedIdea($boardId, $author, 'Dark UI');
        $mod   = $this->moderator();
        $app   = $this->createApp();
        $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        $response = $app->handle($this->post("/demo/ideas/{$third}/merge", $mod, ['target_id' => $source]));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->mergedInto($third), 'No merge chains.');
    }

    public function test_pending_idea_can_be_neither_merge_source_nor_target(): void
    {
        [$boardId, $author, $target, $source] = $this->pair();
        $pending = $this->seedIdea($boardId, $author, 'Dark mode pretty please');
        $this->conn->update('ideas', ['moderation_state' => 'pending'], ['id' => $pending]);
        $mod = $this->moderator();
        $app = $this->createApp();

        $asSource = $app->handle($this->post("/demo/ideas/{$pending}/merge", $mod, ['target_id' => $target]));
        self::assertSame(404, $asSource->getStatusCode(), 'A pending idea is not mergeable.');

        $asTarget = $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $pending]));
        self::assertSame(422, $asTarget->getStatusCode());
        self::assertSame('merge_invalid_target', $this->json($asTarget)['error']['key']);

        self::assertNull($this->mergedInto($pending));
        self::assertNull($this->mergedInto($source));

        $candidates = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod)));
        $searched   = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod, ['q' => 'pretty'])));
        $ids        = array_map(static fn (array $c): int => (int) $c['id'], [...$candidates['candidates'], ...$searched['candidates']]);
        self::assertNotContains($pending, $ids, 'Pending ideas are never offered as merge targets.');
    }

    public function test_cannot_merge_an_idea_that_has_merged_children(): void
    {
        [$boardId, $author, $target, $source] = $this->pair();
        $other = $this->seedIdea($boardId, $author, 'Night mode');
        $mod   = $this->moderator();
        $app   = $this->createApp();
        $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        $response = $app->handle($this->post("/demo/ideas/{$target}/merge", $mod, ['target_id' => $other]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('merge_has_merged_children', $this->json($response)['error']['key']);
        self::assertNull($this->mergedInto($target));
    }

    public function test_target_on_another_board_is_rejected(): void
    {
        [, $author, , $source] = $this->pair();
        $otherBoard = $this->insertBoard('other');
        $foreign    = $this->seedIdea($otherBoard, $author, 'Dark mode');

        $response = $this->createApp()->handle($this->post("/demo/ideas/{$source}/merge", $this->moderator(), ['target_id' => $foreign]));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->mergedInto($source));
    }

    public function test_frozen_board_rejects_merges(): void
    {
        [$boardId, , $target, $source] = $this->pair();
        $this->conn->update('boards', ['frozen_at' => '2026-01-01 00:00:00'], ['id' => $boardId]);

        $response = $this->createApp()->handle($this->post("/demo/ideas/{$source}/merge", $this->moderator(), ['target_id' => $target]));

        self::assertSame('board_frozen', $this->json($response)['error']['key'] ?? null);
        self::assertNull($this->mergedInto($source));
    }

    // --- Cross-tenant -----------------------------------------------------

    public function test_target_from_another_account_is_never_touched(): void
    {
        [, , , $source] = $this->pair();
        $foreignAccount = $this->insertAccount(['slug' => 'foreign']);
        $foreignBoard   = $this->insertBoard('demo', ['account_id' => $foreignAccount]);
        $foreignIdea    = $this->seedIdea($foreignBoard, $this->insertUser('f@example.com'), 'Dark mode');

        $app = $this->createApp();
        $response = $app->handle($this->post("/demo/ideas/{$source}/merge", $this->moderator(), ['target_id' => $foreignIdea]));
        self::assertSame(422, $response->getStatusCode());

        // And a foreign idea id as the SOURCE is simply not found.
        $response = $app->handle($this->post("/demo/ideas/{$foreignIdea}/merge", $this->moderator('m2@example.com'), ['target_id' => $source]));
        self::assertSame(404, $response->getStatusCode());
        self::assertNull($this->mergedInto($source));
        self::assertNull($this->mergedInto($foreignIdea));
    }

    public function test_cloud_mode_moderator_of_one_account_cannot_merge_in_another(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acct-a']);
        $accountB = $this->insertAccount(['slug' => 'acct-b']);
        $boardB   = $this->insertBoard('ideas', ['account_id' => $accountB]);
        $author   = $this->insertUser('b-author@example.com');
        $targetB  = $this->seedIdea($boardB, $author, 'Dark mode');
        $sourceB  = $this->seedIdea($boardB, $author, 'Dark theme');
        $modA     = $this->moderator('mod-a@example.com', 'moderator', $accountA);

        $app = AppFactory::create(Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'routing_mode'        => 'cloud',
        ]), $this->conn, new InMemoryMailer(), new AuditLogger($this->logFile));

        $response = $app->handle($this->post("/acct-b/ideas/ideas/{$sourceB}/merge", $modA, ['target_id' => $targetB]));
        self::assertSame(403, $response->getStatusCode());
        $response = $app->handle($this->post("/acct-b/ideas/ideas/{$sourceB}/unmerge", $modA));
        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->mergedInto($sourceB));
    }

    // --- Side effects -----------------------------------------------------

    public function test_voters_commenters_and_author_of_the_duplicate_are_notified_except_the_actor(): void
    {
        [, $author, $target, $source] = $this->pair();
        $mod       = $this->moderator();
        $voter     = $this->insertUser('v@example.com');
        $commenter = $this->insertUser('c@example.com');
        $this->seedVote($source, $voter, 1);
        $this->seedVote($source, $mod, 1);
        $this->seedComment($source, $commenter);

        $this->createApp()->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));

        foreach ([$author, $voter, $commenter] as $recipient) {
            $rows = $this->conn->fetchAllAssociative('SELECT type, body, link_path FROM notifications WHERE user_id = :u', ['u' => $recipient]);
            self::assertCount(1, $rows, "user {$recipient}");
            self::assertSame('idea_status_changed', $rows[0]['type']);
            self::assertSame('"Dark mode please" was merged into "Dark mode".', $rows[0]['body']);
            self::assertSame("/demo/idea/{$target}", $rows[0]['link_path']);
        }
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM notifications WHERE user_id = :u', ['u' => $mod]));
    }

    public function test_merge_fires_the_idea_merged_webhook_and_audits_merge_and_unmerge(): void
    {
        [, , $target, $source] = $this->pair();
        $sender = new class () implements WebhookSenderInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function send(WebhookTarget $target, array $headers, string $body, int $connectTimeoutSeconds, int $timeoutSeconds): WebhookSendResult
            {
                $this->bodies[] = $body;

                return WebhookSendResult::response(200, null);
            }
        };
        $app   = $this->createApp(webhookSender: $sender, webhookDeferRunner: static fn (\Closure $work): mixed => $work());
        $owner = $this->moderator('owner@example.com', 'owner');
        $app->handle($this->post('/admin/boards/demo/webhook', $owner, ['url' => 'https://93.184.216.34/hook'])->withMethod('PUT'));

        $app->handle($this->post("/demo/ideas/{$source}/merge", $owner, ['target_id' => $target]));
        $app->handle($this->post("/demo/ideas/{$source}/unmerge", $owner));

        self::assertCount(1, $sender->bodies, 'Only the merge emits a webhook event.');
        $payload = json_decode($sender->bodies[0], true);
        self::assertSame('idea.merged', $payload['event']);
        self::assertSame([
            'idea_id'           => $source,
            'title'             => 'Dark mode please',
            'merged_into_id'    => $target,
            'merged_into_title' => 'Dark mode',
        ], $payload['data']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.merged', $log);
        self::assertStringContainsString('idea.unmerged', $log);
    }

    // --- Undo -------------------------------------------------------------

    public function test_unmerge_restores_the_duplicate_and_the_target_score(): void
    {
        [, , $target, $source] = $this->pair();
        $mod = $this->moderator();
        $this->seedVote($source, $this->insertUser('x@example.com'), 1);
        $app = $this->createApp();
        $app->handle($this->post("/demo/ideas/{$source}/merge", $mod, ['target_id' => $target]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT up_count FROM ideas WHERE id = :id', ['id' => $target]));

        $response = $app->handle($this->post("/demo/ideas/{$source}/unmerge", $mod));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'idea_id' => $source], $this->json($response));
        self::assertNull($this->mergedInto($source));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT up_count FROM ideas WHERE id = :id', ['id' => $target]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT up_count FROM ideas WHERE id = :id', ['id' => $source]));
        $home = $this->json($app->handle($this->get('/demo', null)));
        self::assertCount(2, $home['ideas']);

        // Unmerging an idea that is not merged → 404.
        self::assertSame(404, $app->handle($this->post("/demo/ideas/{$source}/unmerge", $mod))->getStatusCode());
    }

    // --- Merge picker -------------------------------------------------------

    public function test_merge_candidates_suggest_similar_ideas_and_search_by_title(): void
    {
        [$boardId, $author, $target, $source] = $this->pair();
        $unrelated = $this->seedIdea($boardId, $author, 'Export to CSV');
        $mod       = $this->moderator();
        $app       = $this->createApp();

        $suggested = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod)));
        $ids       = array_map(static fn (array $c): int => (int) $c['id'], $suggested['candidates']);
        self::assertContains($target, $ids);
        self::assertNotContains($source, $ids, 'Never suggests the idea itself.');
        self::assertNotContains($unrelated, $ids);

        $searched = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod, ['q' => 'csv'])));
        self::assertSame([$unrelated], array_map(static fn (array $c): int => (int) $c['id'], $searched['candidates']));
    }

    public function test_merge_dialog_suggests_a_rephrased_duplicate_without_a_search(): void
    {
        $boardId  = $this->insertBoard('demo');
        $author   = $this->insertUser('rephrased-author@example.com');
        $existing = $this->seedIdea($boardId, $author, 'Wir brauchen eine mobile App'); // export-ok: comment-language
        $this->seedIdea($boardId, $author, 'dgfgdsf');
        $new      = $this->seedIdea($boardId, $author, 'Ich möchte eine mobile App haben.'); // export-ok: comment-language

        $suggested = $this->json($this->createApp()->handle($this->get("/demo/ideas/{$new}/merge-candidates", $this->moderator())));

        self::assertSame([$existing], array_column($suggested['candidates'], 'id'));
    }

    public function test_merge_suggestions_report_the_confirmation_state_and_verdict(): void
    {
        [$boardId, $author, $target, $source] = $this->pair();
        $mod       = $this->moderator();
        $confirmer = new RecordingDuplicateConfirmer([$target]);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $suggested = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod)));
        self::assertSame('available', $suggested['confirmation']);
        self::assertSame($target, $suggested['candidates'][0]['id']);
        self::assertNull($suggested['candidates'][0]['confirmed']);
        self::assertCount(0, $confirmer->calls, 'A deterministic match is never sent for confirmation.');

        $related   = $this->seedIdea($boardId, $author, 'Smartphone app for voting');
        $rephrased = $this->seedIdea($boardId, $author, 'Offline mode for the app');
        $confirmer->verdict = [$related];
        $suggested = $this->json($app->handle($this->get("/demo/ideas/{$rephrased}/merge-candidates", $mod)));
        self::assertSame([$related], array_column($suggested['candidates'], 'id'));
        self::assertTrue($suggested['candidates'][0]['confirmed']);
        self::assertCount(1, $confirmer->calls);
        self::assertSame('Offline mode for the app', $confirmer->calls[0]['title']);
        self::assertNotContains($rephrased, array_column($confirmer->calls[0]['candidates'], 'id'));

        $searched = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $mod, ['q' => 'dark'])));
        self::assertNull($searched['confirmation'], 'A manual title search is never confirmed.');
        self::assertCount(1, $confirmer->calls);
    }

    public function test_merge_suggestions_without_plan_access_report_upgrade_required_and_skip_the_confirmer(): void
    {
        [, , , $source] = $this->pair();
        $confirmer = new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::UpgradeRequired);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $suggested = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $this->moderator())));

        self::assertSame('upgrade_required', $suggested['confirmation']);
        self::assertSame([], $confirmer->calls);
        self::assertNull($suggested['candidates'][0]['confirmed']);
    }

    public function test_merge_suggestions_of_an_account_that_switched_the_confirmation_off_report_disabled_and_skip_it(): void
    {
        [, , , $source] = $this->pair();
        $confirmer = new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::Disabled);
        $app       = $this->createApp(extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])]);

        $suggested = $this->json($app->handle($this->get("/demo/ideas/{$source}/merge-candidates", $this->moderator())));

        self::assertSame('disabled', $suggested['confirmation']);
        self::assertSame([], $confirmer->calls);
    }

    public function test_merge_suggestions_without_a_confirmer_report_no_confirmation(): void
    {
        [, , , $source] = $this->pair();

        $suggested = $this->json($this->createApp()->handle($this->get("/demo/ideas/{$source}/merge-candidates", $this->moderator())));

        self::assertNull($suggested['confirmation']);
    }
}
