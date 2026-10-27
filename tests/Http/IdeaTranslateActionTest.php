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
 * Integration tests for POST /{board}/ideas/{id}/translate — HTTP-level
 * only, going through the full app (AppFactory::create()) exactly like
 * every other action test, with InMemoryTranslationHttpClient injected so
 * no real network call ever happens.
 */
final class IdeaTranslateActionTest extends IntegrationTestCase
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

    private function stubHttp(InMemoryTranslationHttpClient $http, string $translatedBody, string $detectedLocale = 'en'): void
    {
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        // Both the title and body translate calls hit the SAME translate
        // URL — the fake returns the same stubbed response to each call in
        // sequence, so the title ends up translated to the same text as the
        // body. Tests only assert on translated_body / cached /
        // detected_source_locale, which stays meaningful either way.
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => $translatedBody, 'detectedLanguageCode' => $detectedLocale]]],
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
            ->createServerRequest('POST', "/{$slug}/ideas/{$ideaId}/translate")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'target_locale' => $targetLocale]);
    }

    private function translationRowCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM content_translations WHERE entity_type = 'idea' AND entity_id = :id",
            ['id' => $ideaId],
        );
    }

    // --- Happy path -------------------------------------------------------

    public function test_successful_translation_returns_200_and_writes_cache_row(): void
    {
        $boardId = $this->insertBoard('tr-idea-ok', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-ok-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Hello', ['body' => 'World body']);
        $userId  = $this->insertUser('tr-idea-ok-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt-Text');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-idea-ok', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Welt-Text', $data['translated_body']);
        self::assertSame('en', $data['detected_source_locale']);
        self::assertFalse($data['cached']);
        self::assertSame(1, $this->translationRowCount($ideaId));
    }

    /**
     * Regression: a title dominated by English loanwords (e.g. "Votepit
     * als native Mobile App (iOS & Android)") can auto-detect as English on
     * its own, even though the idea's body is clearly German. The action
     * must translate the body FIRST and force the title's source locale to
     * match — never trust the title's own (unreliable, short-text)
     * detection independently of the body.
     */
    public function test_title_translation_uses_the_body_detected_locale_not_its_own(): void
    {
        $boardId = $this->insertBoard('tr-idea-title-locale', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-title-locale-author@example.com');
        $ideaId  = $this->seedIdea(
            $boardId,
            $author,
            'Votepit als native Mobile App (iOS & Android)',
            ['body' => 'Eine native App für iOS und Android.'], // export-ok: comment-language
        );
        $userId = $this->insertUser('tr-idea-title-locale-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        // Body call: auto-detected as German (no source given).
        $http->queueResponse(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'A native app for iOS and Android.', 'detectedLanguageCode' => 'de']]],
        ]);
        // Title call: source is forced to 'de' — Google omits
        // detectedLanguageCode here, exactly as it does for a real
        // sourceLanguageCode request.
        $http->queueResponse(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Votepit as a native mobile app (iOS & Android)']]],
        ]);

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-idea-title-locale', $ideaId, 'en', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Votepit as a native mobile app (iOS & Android)', $data['translated_title']);
        self::assertSame('de', $data['detected_source_locale']);

        $sentBodies = $http->jsonBodiesFor(self::TRANSLATE_URL);
        self::assertArrayNotHasKey('sourceLanguageCode', $sentBodies[0]);
        self::assertSame('de', $sentBodies[1]['sourceLanguageCode']);
    }

    // --- Per-board opt-in (ticket 11) -----------------------------------------

    public function test_board_that_has_not_opted_in_returns_503(): void
    {
        // Default insertBoard() — translation_enabled defaults to 0
        // (migration 0055) — the installation IS configured (testConfig()
        // sets 'translation'), but this specific board never opted in.
        $boardId = $this->insertBoard('tr-idea-board-disabled');
        $author  = $this->insertUser('tr-idea-board-disabled-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Hello', ['body' => 'World body']);
        $userId  = $this->insertUser('tr-idea-board-disabled-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt-Text');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-idea-board-disabled', $ideaId, 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
        self::assertSame(0, $this->translationRowCount($ideaId));
        self::assertSame(0, $http->callCount(self::TRANSLATE_URL), 'a non-opted-in board must never reach the provider');
    }

    // --- Cache hit ----------------------------------------------------------

    public function test_second_identical_request_is_served_from_cache_without_calling_provider_again(): void
    {
        $boardId = $this->insertBoard('tr-idea-cache', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-cache-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Hello', ['body' => 'World body']);
        $userId  = $this->insertUser('tr-idea-cache-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt-Text');

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-idea-cache', $ideaId, 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);

        $response = $app->handle($this->postTranslate('tr-idea-cache', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['cached']);
        self::assertSame($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must not be called again on a cache hit');
        self::assertSame(1, $this->translationRowCount($ideaId));
    }

    // --- Edit invalidates cache ----------------------------------------------

    public function test_editing_the_idea_invalidates_the_cache(): void
    {
        $boardId = $this->insertBoard('tr-idea-edit', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-edit-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Hello', ['body' => 'World body']);
        $userId  = $this->insertUser('tr-idea-edit-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt-Text');

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-idea-edit', $ideaId, 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);

        $this->conn->update('ideas', ['body' => 'A completely different body now'], ['id' => $ideaId]);

        $response = $app->handle($this->postTranslate('tr-idea-edit', $ideaId, 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['cached'], 'edited content must be a cache miss');
        self::assertGreaterThan($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must be called again after an edit');
    }

    // --- Cross-board / cross-tenant isolation ---------------------------------

    public function test_translate_via_wrong_board_slug_returns_404(): void
    {
        $boardId1 = $this->insertBoard('tr-idea-b1');
        $this->insertBoard('tr-idea-b2', ['translation_enabled' => 1]);
        $author = $this->insertUser('tr-idea-cross-author@example.com');
        $ideaId = $this->seedIdea($boardId1, $author);
        $userId = $this->insertUser('tr-idea-cross-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-idea-b2', $ideaId, 'de', $userId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->translationRowCount($ideaId));
    }

    public function test_translate_idea_in_foreign_account_board_returns_404(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-translate', 'name' => 'Foreign Account']);
        $foreignBoardId   = $this->insertBoard('foreign-translate-board', ['account_id' => $foreignAccountId]);
        $foreignAuthorId  = $this->insertUser('foreign-translate-author@example.com');
        $foreignIdeaId    = $this->seedIdea($foreignBoardId, $foreignAuthorId);

        $ownerId = $this->insertUser('default-owner-translate@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('foreign-translate-board', $foreignIdeaId, 'de', $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Provider failure --------------------------------------------------

    public function test_provider_failure_returns_503_and_original_stays_reachable(): void
    {
        $boardId = $this->insertBoard('tr-idea-fail', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-fail-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author, 'Hello', ['body' => 'World body']);
        $userId  = $this->insertUser('tr-idea-fail-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        $http->stub(self::TRANSLATE_URL, ['status' => 500, 'body' => []]);

        $app = $this->createApp(translationHttpClient: $http);
        $response = $app->handle($this->postTranslate('tr-idea-fail', $ideaId, 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
        self::assertSame(0, $this->translationRowCount($ideaId));

        // Original content is unaffected — reachable via the normal detail route.
        $detail = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/tr-idea-fail/ideas/' . $ideaId));
        self::assertSame(200, $detail->getStatusCode());
        $detailData = json_decode((string) $detail->getBody(), true);
        self::assertSame('Hello', $detailData['idea']['title']);
    }

    // --- Disabled ------------------------------------------------------------

    public function test_translation_not_configured_returns_503(): void
    {
        $boardId = $this->insertBoard('tr-idea-disabled');
        $author  = $this->insertUser('tr-idea-disabled-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('tr-idea-disabled-user@example.com');

        // No translationHttpClient injected AND default testConfig() (no
        // 'translation' section) would still be overridden by this
        // subclass's testConfig() — use a fresh Config without the
        // translation section for this one test via a dedicated app.
        $response = $this->createAppWithoutTranslation()
            ->handle($this->postTranslate('tr-idea-disabled', $ideaId, 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
    }

    /** @return \Slim\App<null> */
    private function createAppWithoutTranslation(): \Slim\App
    {
        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        return \Votepit\Http\AppFactory::create(
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
    }

    // --- Validation ----------------------------------------------------------

    public function test_missing_target_locale_returns_422(): void
    {
        $boardId = $this->insertBoard('tr-idea-422', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-422-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('tr-idea-422-user@example.com');

        $response = $this->createApp()->handle($this->postTranslate('tr-idea-422', $ideaId, '', $userId));

        self::assertSame(422, $response->getStatusCode());
    }

    // --- AuthZ ---------------------------------------------------------------

    public function test_anon_translate_returns_401(): void
    {
        $boardId = $this->insertBoard('tr-idea-anon');
        $author  = $this->insertUser('tr-idea-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postTranslate('tr-idea-anon', $ideaId, 'de', null));

        self::assertSame(401, $response->getStatusCode());
    }

    // --- Rate limit ------------------------------------------------------------

    public function test_exceeding_rate_limit_returns_429(): void
    {
        $boardId = $this->insertBoard('tr-idea-rl', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-rl-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('tr-idea-rl-user@example.com');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => ['translation:idea' => ['limit' => 1, 'window' => 3600]],
            'translation'         => [
                'provider' => 'google',
                'google'   => ['project_id' => 'test-project', 'service_account_key' => $this->serviceAccountKeyPath(), 'region' => 'eu'],
            ],
        ]);

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt');

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $config,
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $app->handle($this->postTranslate('tr-idea-rl', $ideaId, 'de', $userId));
        $response = $app->handle($this->postTranslate('tr-idea-rl', $ideaId, 'fr', $userId));

        self::assertSame(429, $response->getStatusCode());
    }

    public function test_a_cache_hit_never_counts_against_the_rate_limit(): void
    {
        $boardId = $this->insertBoard('tr-idea-rl-cache', ['translation_enabled' => 1]);
        $author  = $this->insertUser('tr-idea-rl-cache-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('tr-idea-rl-cache-user@example.com');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => ['translation:idea' => ['limit' => 1, 'window' => 3600]],
            'translation'         => [
                'provider' => 'google',
                'google'   => ['project_id' => 'test-project', 'service_account_key' => $this->serviceAccountKeyPath(), 'region' => 'eu'],
            ],
        ]);

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Welt');

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $config,
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        // 1st call ('de'): cache miss, consumes the only allowed slot.
        $first = $app->handle($this->postTranslate('tr-idea-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $first->getStatusCode());

        // 2nd call: SAME idea/locale — a cache hit must succeed regardless
        // of the exhausted bucket, any number of times.
        $second = $app->handle($this->postTranslate('tr-idea-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $second->getStatusCode());
        $secondData = json_decode((string) $second->getBody(), true);
        self::assertTrue($secondData['cached']);

        $third = $app->handle($this->postTranslate('tr-idea-rl-cache', $ideaId, 'de', $userId));
        self::assertSame(200, $third->getStatusCode());

        // A genuinely new cache miss ('fr') still hits the real limit.
        $fourth = $app->handle($this->postTranslate('tr-idea-rl-cache', $ideaId, 'fr', $userId));
        self::assertSame(429, $fourth->getStatusCode());
    }
}
