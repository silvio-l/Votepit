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
 * Integration tests for GET /notifications + POST /notifications/{id}/read +
 * POST /notifications/translate (the in-app inbox —
 * migrations/0024_add_notifications_remove_support_email.sql — plus bulk
 * notification translation, also covering operator broadcast
 * announcements since those are physically notifications rows).
 */
final class NotificationActionTest extends IntegrationTestCase
{
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const TRANSLATE_URL = 'https://translate-eu.googleapis.com/v3/projects/test-project/locations/europe-west1:translateText';

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function translationTestConfig(): Config
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

    private function translationRowCount(int $notificationId): int
    {
        return (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM content_translations WHERE entity_type = 'notification' AND entity_id = :id",
            ['id' => $notificationId],
        );
    }

    /** @param array<string, mixed> $body */
    private function memberPost(string $path, int $userId, array $body): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($userId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));
    }

    private function memberGet(string $path, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
    }

    private function memberDelete(string $path, int $userId): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('DELETE', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($userId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(['_csrf' => $token]);
    }

    public function test_anon_cannot_list_notifications(): void
    {
        $app      = $this->createApp();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/notifications'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_user_sees_own_account_notification_and_every_broadcast(): void
    {
        $userId = $this->insertUser('inbox-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId = $this->insertAccount();
        $otherUserId    = $this->insertUser('inbox-other@example.com');
        $this->insertAccountMember($otherAccountId, $otherUserId, 'owner');

        $this->insertNotification($this->defaultAccountId(), ['title' => 'Your request']);
        $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);
        $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'New feature']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberGet('/notifications', $userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        $titles = array_column($data['notifications'], 'title');
        self::assertContains('Your request', $titles);
        self::assertContains('New feature', $titles);
        self::assertNotContains('Foreign request', $titles);
    }

    public function test_marking_a_notification_read_persists_across_requests(): void
    {
        $userId = $this->insertUser('inbox-read@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $unread = $app->handle($this->memberGet('/notifications', $userId));
        $unreadData = json_decode((string) $unread->getBody(), true);
        self::assertFalse($unreadData['notifications'][0]['is_read']);

        $markResponse = $app->handle($this->memberPost("/notifications/{$notificationId}/read", $userId, []));
        self::assertSame(200, $markResponse->getStatusCode());

        $read = $app->handle($this->memberGet('/notifications', $userId));
        $readData = json_decode((string) $read->getBody(), true);
        self::assertTrue($readData['notifications'][0]['is_read']);
    }

    public function test_marking_an_unknown_notification_read_is_404(): void
    {
        $userId = $this->insertUser('inbox-404@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/notifications/999999/read', $userId, []));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_marking_another_accounts_notification_read_is_404_and_does_not_persist(): void
    {
        $userId = $this->insertUser('inbox-cross-tenant@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId  = $this->insertAccount();
        $notificationId  = $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberPost("/notifications/{$notificationId}/read", $userId, []));

        self::assertSame(404, $response->getStatusCode());

        $ownerId = $this->insertUser('inbox-cross-tenant-owner@example.com');
        $this->insertAccountMember($otherAccountId, $ownerId, 'owner');
        $ownerView = $app->handle($this->memberGet('/notifications', $ownerId));
        $ownerData = json_decode((string) $ownerView->getBody(), true);
        self::assertFalse($ownerData['notifications'][0]['is_read']);
    }

    public function test_dismissing_a_notification_removes_it_from_the_users_inbox(): void
    {
        $userId = $this->insertUser('inbox-dismiss@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $dismissResponse = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));
        self::assertSame(200, $dismissResponse->getStatusCode());
        $dismissData = json_decode((string) $dismissResponse->getBody(), true);
        self::assertTrue($dismissData['ok']);

        $after     = $app->handle($this->memberGet('/notifications', $userId));
        $afterData = json_decode((string) $after->getBody(), true);
        self::assertSame([], $afterData['notifications']);
    }

    public function test_dismissing_is_idempotent(): void
    {
        $userId = $this->insertUser('inbox-dismiss-twice@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $first  = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));
        $second = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_dismissing_a_broadcast_does_not_affect_other_users(): void
    {
        $dismissingUserId = $this->insertUser('inbox-dismiss-broadcast@example.com');
        $otherUserId      = $this->insertUser('inbox-keep-broadcast@example.com');
        $notificationId   = $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'New feature']);

        $app = $this->createApp();
        $app->handle($this->memberDelete("/notifications/{$notificationId}", $dismissingUserId));

        $dismissingView = $app->handle($this->memberGet('/notifications', $dismissingUserId));
        $dismissingData = json_decode((string) $dismissingView->getBody(), true);
        self::assertSame([], $dismissingData['notifications']);

        $otherView = $app->handle($this->memberGet('/notifications', $otherUserId));
        $otherData = json_decode((string) $otherView->getBody(), true);
        self::assertContains('New feature', array_column($otherData['notifications'], 'title'));
    }

    public function test_dismissing_an_unknown_notification_is_404(): void
    {
        $userId = $this->insertUser('inbox-dismiss-404@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberDelete('/notifications/999999', $userId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_dismissing_another_accounts_notification_is_404_and_does_not_persist(): void
    {
        $userId = $this->insertUser('inbox-dismiss-cross-tenant@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId = $this->insertAccount();
        $notificationId = $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));

        self::assertSame(404, $response->getStatusCode());

        $ownerId = $this->insertUser('inbox-dismiss-cross-tenant-owner@example.com');
        $this->insertAccountMember($otherAccountId, $ownerId, 'owner');
        $ownerView = $app->handle($this->memberGet('/notifications', $ownerId));
        $ownerData = json_decode((string) $ownerView->getBody(), true);
        self::assertContains('Foreign request', array_column($ownerData['notifications'], 'title'));
    }

    // --- Bulk translate ------------------------------------------------------

    public function test_translate_returns_translated_title_and_body_for_every_id(): void
    {
        $userId = $this->insertUser('inbox-translate@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $n1 = $this->insertNotification($this->defaultAccountId(), ['title' => 'First', 'body' => 'First body.']);
        $n2 = $this->insertNotification($this->defaultAccountId(), ['title' => 'Second', 'body' => 'Second body.']);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $this->translationTestConfig(),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, [
            'notification_ids' => [$n1, $n2],
            'target_locale'    => 'de',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $data['notifications']);
        foreach ($data['notifications'] as $result) {
            self::assertTrue($result['ok']);
            self::assertSame('Übersetzt', $result['translated_title']); // export-ok: comment-language
            self::assertSame('Übersetzt', $result['translated_body']); // export-ok: comment-language
            self::assertSame('en', $result['detected_source_locale']);
            self::assertFalse($result['cached']);
        }
        self::assertSame(1, $this->translationRowCount($n1));
        self::assertSame(1, $this->translationRowCount($n2));
    }

    public function test_translate_skips_notifications_of_an_account_without_the_translation_feature(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $userId = $this->insertUser('inbox-translate-free@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $n1 = $this->insertNotification($this->defaultAccountId(), ['title' => 'First', 'body' => 'First body.']);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Translated');

        $app = \Votepit\Http\AppFactory::create(
            $this->translationTestConfig(),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            new \Votepit\Logging\AuditLogger($this->logFile),
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, [
            'notification_ids' => [$n1],
            'target_locale'    => 'de',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([['id' => $n1, 'ok' => false]], $data['notifications']);
        self::assertSame(0, $this->translationRowCount($n1));
    }

    public function test_translate_serves_unchanged_notifications_from_cache_on_second_request(): void
    {
        $userId = $this->insertUser('inbox-translate-cache@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId(), ['title' => 'Cached', 'body' => 'Cached body.']);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $this->translationTestConfig(),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $body = ['notification_ids' => [$notificationId], 'target_locale' => 'de'];
        $app->handle($this->memberPost('/notifications/translate', $userId, $body));
        $callsAfterFirst = $http->callCount(self::TRANSLATE_URL);

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, $body));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['notifications'][0]['cached']);
        self::assertSame($callsAfterFirst, $http->callCount(self::TRANSLATE_URL), 'the provider must not be called again on a cache hit');
    }

    public function test_translate_covers_broadcast_announcements(): void
    {
        $userId = $this->insertUser('inbox-translate-broadcast@example.com');
        $notificationId = $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'New feature', 'body' => 'We shipped something new.']);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $this->translationTestConfig(),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, [
            'notification_ids' => [$notificationId],
            'target_locale'    => 'de',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['notifications'][0]['ok']);
    }

    public function test_translate_of_a_foreign_accounts_notification_id_comes_back_not_ok(): void
    {
        $userId = $this->insertUser('inbox-translate-cross-tenant@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId  = $this->insertAccount();
        $notificationId  = $this->insertNotification($otherAccountId, ['title' => 'Foreign']);

        $http = new InMemoryTranslationHttpClient();
        $this->stubOkTranslate($http, 'Übersetzt'); // export-ok: comment-language

        $audit = new \Votepit\Logging\AuditLogger($this->logFile);
        $app   = \Votepit\Http\AppFactory::create(
            $this->translationTestConfig(),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            planPolicy: self::syntheticPlanPolicy(),
            translationHttpClient: $http,
        );

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, [
            'notification_ids' => [$notificationId],
            'target_locale'    => 'de',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['notifications'][0]['ok']);
        self::assertSame(0, $http->callCount(self::TRANSLATE_URL));
    }

    public function test_translate_not_configured_returns_503(): void
    {
        $userId = $this->insertUser('inbox-translate-disabled@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $response = $app->handle($this->memberPost('/notifications/translate', $userId, [
            'notification_ids' => [$notificationId],
            'target_locale'    => 'de',
        ]));

        self::assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('translation_unavailable', $data['error']['key']);
    }

    public function test_translate_anon_returns_401(): void
    {
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $csrf  = $this->csrf();
        $token = $csrf->generate();

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/notifications/translate')
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
                ->withParsedBody(['notification_ids' => [$notificationId], 'target_locale' => 'de', '_csrf' => $token]),
        );

        self::assertSame(401, $response->getStatusCode());
    }
}
