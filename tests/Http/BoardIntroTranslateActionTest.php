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
 * Integration tests for POST /{board}/translate-intro — single-item board
 * intro translation, mirrors IdeaTranslateActionTest's structure/discipline
 * (AppFactory::create() with an InMemoryTranslationHttpClient injected, no
 * real network calls).
 */
final class BoardIntroTranslateActionTest extends IntegrationTestCase
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
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => $translatedBody, 'detectedLanguageCode' => $detectedLocale]]],
        ]);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postTranslate(string $slug, string $targetLocale, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', "/{$slug}/translate-intro")
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'target_locale' => $targetLocale]);
    }

    private function translationRowCount(int $boardId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM content_translations WHERE entity_type = 'board_intro' AND entity_id = :id",
            ['id' => $boardId],
        );
    }

    // --- Happy path -------------------------------------------------------

    public function test_successful_translation_returns_200_and_writes_cache_row(): void
    {
        $boardId = $this->insertBoard('tr-intro-ok', ['translation_enabled' => 1, 'intro' => 'Welcome to our board.']);
        $userId  = $this->insertUser('tr-intro-ok-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Willkommen auf unserem Board.');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-intro-ok', 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Willkommen auf unserem Board.', $data['translated_body']);
        self::assertSame('en', $data['detected_source_locale']);
        self::assertFalse($data['cached']);
        self::assertSame(1, $this->translationRowCount($boardId));
    }

    // --- Per-board opt-in ----------------------------------------------------

    public function test_board_that_has_not_opted_in_returns_503(): void
    {
        $this->insertBoard('tr-intro-board-disabled', ['intro' => 'Welcome.']);
        $userId = $this->insertUser('tr-intro-board-disabled-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Willkommen.');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-intro-board-disabled', 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
        self::assertSame(0, $http->callCount(self::TRANSLATE_URL), 'a non-opted-in board must never reach the provider');
    }

    // --- No intro --------------------------------------------------------------

    public function test_board_with_no_intro_returns_404(): void
    {
        $this->insertBoard('tr-intro-empty', ['translation_enabled' => 1, 'intro' => null]);
        $userId = $this->insertUser('tr-intro-empty-user@example.com');

        $response = $this->createApp()
            ->handle($this->postTranslate('tr-intro-empty', 'de', $userId));

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Cache hit ----------------------------------------------------------

    public function test_second_identical_request_is_served_from_cache_without_calling_provider_again(): void
    {
        $boardId = $this->insertBoard('tr-intro-cache', ['translation_enabled' => 1, 'intro' => 'Welcome to our board.']);
        $userId  = $this->insertUser('tr-intro-cache-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Willkommen.');

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-intro-cache', 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);

        $response = $app->handle($this->postTranslate('tr-intro-cache', 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['cached']);
        self::assertSame($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must not be called again on a cache hit');
        self::assertSame(1, $this->translationRowCount($boardId));
    }

    // --- Editing the intro invalidates the cache ------------------------------

    public function test_editing_the_intro_invalidates_the_cache(): void
    {
        $boardId = $this->insertBoard('tr-intro-edit', ['translation_enabled' => 1, 'intro' => 'Welcome to our board.']);
        $userId  = $this->insertUser('tr-intro-edit-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Willkommen.');

        $app = $this->createApp(translationHttpClient: $http);
        $app->handle($this->postTranslate('tr-intro-edit', 'de', $userId));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);

        $this->conn->update('boards', ['intro' => 'A completely different intro now.'], ['id' => $boardId]);

        $response = $app->handle($this->postTranslate('tr-intro-edit', 'de', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['cached'], 'edited content must be a cache miss');
        self::assertGreaterThan($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must be called again after an edit');
    }

    // --- Cross-tenant isolation ------------------------------------------------

    public function test_translate_intro_in_foreign_account_board_returns_404(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-intro-translate', 'name' => 'Foreign Account']);
        $this->insertBoard('foreign-intro-translate-board', ['account_id' => $foreignAccountId, 'intro' => 'Foreign intro.']);

        $ownerId = $this->insertUser('default-owner-intro-translate@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Fremd.');

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('foreign-intro-translate-board', 'de', $ownerId));

        self::assertSame(404, $response->getStatusCode());
    }

    // --- Provider failure --------------------------------------------------

    public function test_provider_failure_returns_503(): void
    {
        $this->insertBoard('tr-intro-fail', ['translation_enabled' => 1, 'intro' => 'Welcome.']);
        $userId = $this->insertUser('tr-intro-fail-user@example.com');

        $http = new InMemoryTranslationHttpClient();
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-token']]);
        $http->stub(self::TRANSLATE_URL, ['status' => 500, 'body' => []]);

        $response = $this->createApp(translationHttpClient: $http)
            ->handle($this->postTranslate('tr-intro-fail', 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
    }

    // --- Disabled installation-wide -------------------------------------------

    public function test_translation_not_configured_returns_503(): void
    {
        $this->insertBoard('tr-intro-disabled', ['translation_enabled' => 1, 'intro' => 'Welcome.']);
        $userId = $this->insertUser('tr-intro-disabled-user@example.com');

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

        $response = $app->handle($this->postTranslate('tr-intro-disabled', 'de', $userId));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
    }

    // --- Validation ----------------------------------------------------------

    public function test_missing_target_locale_returns_422(): void
    {
        $this->insertBoard('tr-intro-422', ['translation_enabled' => 1, 'intro' => 'Welcome.']);
        $userId = $this->insertUser('tr-intro-422-user@example.com');

        $response = $this->createApp()->handle($this->postTranslate('tr-intro-422', '', $userId));

        self::assertSame(422, $response->getStatusCode());
    }

    // --- AuthZ ---------------------------------------------------------------

    public function test_anon_translate_returns_401(): void
    {
        $this->insertBoard('tr-intro-anon', ['intro' => 'Welcome.']);

        $response = $this->createApp()->handle($this->postTranslate('tr-intro-anon', 'de', null));

        self::assertSame(401, $response->getStatusCode());
    }

    // --- Rate limit ------------------------------------------------------------

    public function test_exceeding_rate_limit_returns_429(): void
    {
        $this->insertBoard('tr-intro-rl', ['translation_enabled' => 1, 'intro' => 'Welcome.']);
        $userId = $this->insertUser('tr-intro-rl-user@example.com');

        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => ['translation:board_intro' => ['limit' => 1, 'window' => 3600]],
            'translation'         => [
                'provider' => 'google',
                'google'   => ['project_id' => 'test-project', 'service_account_key' => $this->serviceAccountKeyPath(), 'region' => 'eu'],
            ],
        ]);

        $http = new InMemoryTranslationHttpClient();
        $this->stubHttp($http, 'Willkommen.');

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $config,
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $app->handle($this->postTranslate('tr-intro-rl', 'de', $userId));
        $response = $app->handle($this->postTranslate('tr-intro-rl', 'fr', $userId));

        self::assertSame(429, $response->getStatusCode());
    }
}
