<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Translation\InMemoryTranslationHttpClient;

/**
 * Integration tests for POST /{board}/ideas/{id}/comments/translate — the
 * bulk comment-translation endpoint (ticket 06). Same HTTP-seam-only
 * discipline as IdeaTranslateActionTest: AppFactory::create() with an
 * InMemoryTranslationHttpClient injected, no real network calls.
 */
final class CommentTranslateActionTest extends IntegrationTestCase
{
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const TRANSLATE_URL = 'https://translate-eu.googleapis.com/v3/projects/test-project/locations/europe-west1:translateText';

    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'translation'         => [
                'provider' => 'google',
                'google'   => [
                    'project_id'          => 'test-project',
                    'service_account_key' => $this->serviceAccountKeyPath(),
                    'region'              => 'eu',
                ],
            ],
        ]);
    }

    private function serviceAccountKeyPath(): string
    {
        static $path = null;
        if ($path !== null) {
            return $path;
        }

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($resource, 'test fixture: RSA key generation must succeed');
        openssl_pkey_export($resource, $pem);

        $path = sys_get_temp_dir() . '/votepit-test-sa-key-' . uniqid() . '.json';
        file_put_contents($path, (string) json_encode([
            'client_email' => 'svc@test-project.iam.gserviceaccount.com',
            'private_key'  => $pem,
        ]));

        return $path;
    }

    private function stubOkTranslate(InMemoryTranslationHttpClient $http, string $translatedText, string $detectedLocale = 'en'): void
    {
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => $translatedText, 'detectedLanguageCode' => $detectedLocale]]],
        ]);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postTranslate(string $slug, int $ideaId, string $targetLocale, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/{$slug}/ideas/{$ideaId}/comments/translate")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'target_locale' => $targetLocale]);
    }

    private function translationRowCount(int $commentId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM content_translations WHERE entity_type = 'comment' AND entity_id = :id",
            ['id' => $commentId],
        );
    }

    // --- Happy path -------------------------------------------------------

    public function test_translates_all_comments_of_the_idea_in_one_response(): void
    {
        $boardId = $this->insertBoard('tr-cmt-ok', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-ok-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $c1      = $this->seedComment($ideaId, $author, 'First comment.');
        $c2      = $this->seedComment($ideaId, $author, 'Second comment.');
        $userId  = $this->insertUser('tr-cmt-ok-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-cmt-ok', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['comments']);
        foreach ($data['comments'] as $result) {
            self::assertTrue($result['ok']);
            self::assertSame('Übersetzt', $result['translated_body']); // export-ok: comment-language
            self::assertSame('en', $result['detected_source_locale']);
            self::assertFalse($result['cached']);
        }
        self::assertSame(1, $this->translationRowCount($c1));
        self::assertSame(1, $this->translationRowCount($c2));
    }

    // --- Per-board opt-in (ticket 11) -----------------------------------------

    public function test_board_that_has_not_opted_in_returns_503(): void
    {
        // Default insertBoard() — translation_enabled defaults to 0
        // (migration 0055) — the installation IS configured (testConfig()
        // sets 'translation'), but this specific board never opted in.
        $boardId = $this->insertBoard('tr-cmt-board-disabled');
        $author  = $this->insertUser('tr-cmt-board-disabled-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->seedComment($ideaId, $author, 'First comment.');
        $userId = $this->insertUser('tr-cmt-board-disabled-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-cmt-board-disabled', $ideaId, 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
        self::assertSame(0, $http->callCount(self::TRANSLATE_URL), 'a non-opted-in board must never reach the provider');
    }

    // --- Cache hit ----------------------------------------------------------

    public function test_second_request_serves_unchanged_comments_from_cache_without_calling_provider_again(): void
    {
        $boardId = $this->insertBoard('tr-cmt-cache', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-cache-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->seedComment($ideaId, $author, 'First comment.');
        $this->seedComment($ideaId, $author, 'Second comment.');
        $userId = $this->insertUser('tr-cmt-cache-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-cmt-cache', $ideaId, 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);
        self::assertSame(2, $callsAfterFirst);

        $response = $app->handle($this->postTranslate('tr-cmt-cache', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        foreach ($data['comments'] as $result) {
            self::assertTrue($result['cached']);
        }
        self::assertSame($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must not be called again for unchanged comments');
    }

    // --- Editing one comment invalidates only its own cache entry -----------

    public function test_editing_one_comment_invalidates_only_its_own_cache_entry(): void
    {
        $boardId = $this->insertBoard('tr-cmt-edit', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-edit-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $c1      = $this->seedComment($ideaId, $author, 'First comment.');
        $this->seedComment($ideaId, $author, 'Second comment.');
        $userId = $this->insertUser('tr-cmt-edit-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-cmt-edit', $ideaId, 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);
        self::assertSame(2, $callsAfterFirst);

        $this->conn->update('comments', ['body' => 'First comment, edited now.'], ['id' => $c1]);

        $response = $app->handle($this->postTranslate('tr-cmt-edit', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        $byId = [];
        foreach ($data['comments'] as $result) {
            $byId[$result['id']] = $result;
        }
        self::assertFalse($byId[$c1]['cached'], 'the edited comment must be a cache miss');
        // exactly one more provider call: only the edited comment.
        self::assertSame($callsAfterFirst + 1, $http->callCount(self::TRANSLATE_URL));
    }

    // --- Cross-board / cross-tenant isolation --------------------------------

    public function test_translate_via_wrong_board_slug_returns_404(): void
    {
        $boardId1 = $this->insertBoard('tr-cmt-b1');
        $this->insertBoard('tr-cmt-b2', ['translation_enabled' => 1]);
        $author = $this->insertUser('tr-cmt-cross-author@example.com');
        $ideaId = $this->seedIdea($boardId1, $author);
        $this->seedComment($ideaId, $author, 'Some comment.');
        $userId = $this->insertUser('tr-cmt-cross-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-cmt-b2', $ideaId, 'de', $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Rate limit: bulk endpoint counts as ONE hit -------------------------

    public function test_bulk_endpoint_counts_as_a_single_rate_limit_hit_regardless_of_comment_count(): void
    {
        $boardId = $this->insertBoard('tr-cmt-rl', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-rl-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        for ($i = 0; $i < 5; $i++) {
            $this->seedComment($ideaId, $author, "Comment {$i}.");
        }
        $userId = $this->insertUser('tr-cmt-rl-user@example.com');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => ['translation:comment' => ['limit' => 1, 'window' => 3600]],
            'translation'         => [
                'provider' => 'google',
                'google'   => ['project_id' => 'test-project', 'service_account_key' => $this->serviceAccountKeyPath(), 'region' => 'eu'],
            ],
        ]);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $config,
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $first = $app->handle($this->postTranslate('tr-cmt-rl', $ideaId, 'de', $userId));
        self::assertSame(200, $first->getStatusCode());

        $second = $app->handle($this->postTranslate('tr-cmt-rl', $ideaId, 'fr', $userId));
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_a_fully_cached_batch_never_counts_against_the_rate_limit(): void
    {
        $boardId = $this->insertBoard('tr-cmt-rl-cache', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-rl-cache-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->seedComment($ideaId, $author, 'Comment one.');
        $this->seedComment($ideaId, $author, 'Comment two.');
        $userId  = $this->insertUser('tr-cmt-rl-cache-user@example.com');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => ['translation:comment' => ['limit' => 1, 'window' => 3600]],
            'translation'         => [
                'provider' => 'google',
                'google'   => ['project_id' => 'test-project', 'service_account_key' => $this->serviceAccountKeyPath(), 'region' => 'eu'],
            ],
        ]);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $config,
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        // 1st call ('de'): cache miss for both comments, consumes the only slot.
        $first = $app->handle($this->postTranslate('tr-cmt-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $first->getStatusCode());

        // 2nd/3rd call: SAME locale — every comment is now a cache hit, so
        // the (exhausted) bucket must not block it, any number of times.
        $second = $app->handle($this->postTranslate('tr-cmt-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $second->getStatusCode());
        $secondData = json_decode((string) $second->getBody(), true);
        self::assertTrue($secondData['comments'][0]['cached']);

        $third = $app->handle($this->postTranslate('tr-cmt-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $third->getStatusCode());

        // A genuinely new cache miss ('fr') still hits the real limit.
        $fourth = $app->handle($this->postTranslate('tr-cmt-rl-cache', $ideaId, 'fr', $userId));
        self::assertSame(429, $fourth->getStatusCode());
    }

    // --- Provider failure — partial success --------------------------------

    public function test_a_single_failing_comment_does_not_fail_the_whole_batch(): void
    {
        $boardId = $this->insertBoard('tr-cmt-fail', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-fail-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $ok      = $this->seedComment($ideaId, $author, 'Good comment.');
        $bad     = $this->seedComment($ideaId, $author, 'Bad comment.');
        $userId  = $this->insertUser('tr-cmt-fail-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        // Per-call sequencing on the shared translate URL: the first
        // comment's call succeeds, the second's fails transiently — this
        // exercises the actual per-comment ok:true/false distinction rather
        // than only the "everything fails" case.
        $http->queueResponse(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Gut', 'detectedLanguageCode' => 'en']]],
        ]);
        $http->queueFailure(self::TRANSLATE_URL);

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-cmt-fail', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['comments']);

        $byId = [];
        foreach ($data['comments'] as $result) {
            $byId[$result['id']] = $result;
        }
        self::assertTrue($byId[$ok]['ok']);
        self::assertSame('Gut', $byId[$ok]['translated_body']);
        self::assertFalse($byId[$bad]['ok']);
        self::assertArrayNotHasKey('translated_body', $byId[$bad]);

        self::assertSame(1, $this->translationRowCount($ok));
        self::assertSame(0, $this->translationRowCount($bad));
    }

    // --- Translation fully disabled: whole-batch 503 -------------------------

    public function test_translation_not_configured_returns_503_for_the_whole_batch(): void
    {
        $boardId = $this->insertBoard('tr-cmt-disabled');
        $author  = $this->insertUser('tr-cmt-disabled-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->seedComment($ideaId, $author, 'Some comment.');
        $userId = $this->insertUser('tr-cmt-disabled-user@example.com');

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            Config::fromArray([
                'env'                 => 'dev',
                'app_url'             => 'http://localhost:8000',
                'app_key'             => str_repeat('a', 64),
                'identity_server_key' => self::identityServerKey(),
                'db'                  => ['name' => ':memory:'],
                'smtp'                => ['from_email' => 'noreply@example.com'],
                'magic_link_ttl'      => 900,
            ]),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
        );

        $response = $app->handle($this->postTranslate('tr-cmt-disabled', $ideaId, 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
    }

    // --- Empty idea (no comments) --------------------------------------------

    public function test_idea_with_no_comments_returns_empty_list(): void
    {
        $boardId = $this->insertBoard('tr-cmt-empty', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-cmt-empty-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('tr-cmt-empty-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-cmt-empty', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([], $data['comments']);
    }

    // --- AuthZ ---------------------------------------------------------------

    public function test_anon_translate_returns_401(): void
    {
        $boardId = $this->insertBoard('tr-cmt-anon');
        $author  = $this->insertUser('tr-cmt-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->seedComment($ideaId, $author, 'Some comment.');

        $response = $this->createApp()->handle($this->postTranslate('tr-cmt-anon', $ideaId, 'de', null));

        self::assertSame(401, $response->getStatusCode());
    }
}
