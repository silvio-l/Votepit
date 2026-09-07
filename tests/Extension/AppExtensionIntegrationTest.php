<?php

declare(strict_types=1);

namespace Votepit\Tests\Extension;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Extension\AppExtension;
use Votepit\Extension\DeletionBlocked;
use Votepit\Http\AppFactory;
use Votepit\Http\CoreRoute;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\StatusRecorder;
use Votepit\Tests\Support\StubExtension;

/**
 * The AppExtension seam end to end through AppFactory: bootstrap feature
 * flags, extension routes (global + account-prefixed), CSRF header
 * exemptions, the account-deletion precondition, static response headers,
 * middleware on core-owned routes (CoreRoute), the sanctioned session
 * issuer, and the edition gate on per-board SMTP routes. Everything here
 * runs WITHOUT any real extension — StubExtension is a test fixture; the
 * Community Edition itself ships none.
 */
final class AppExtensionIntegrationTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * @param list<AppExtension> $extensions
     * @return App<null>
     */
    private function cloudApp(array $extensions = []): App
    {
        $config = Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'routing_mode'        => 'cloud',
        ]);

        return AppFactory::create(
            $config,
            $this->conn,
            new InMemoryMailer(),
            new AuditLogger($this->logFile),
            planPolicy: self::syntheticPlanPolicy(),
            extensions: $extensions,
        );
    }

    private function get(string $path, ?int $userId = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function postWithCsrf(string $path, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /**
     * @param App<null> $app
     * @return array<string, mixed>
     */
    private function bootstrapFeatures(App $app): array
    {
        $response = $app->handle($this->get('/api/bootstrap'));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['features'] ?? null);

        return $data['features'];
    }

    // -------------------------------------------------------------------------
    // Bootstrap feature flags
    // -------------------------------------------------------------------------

    public function test_community_bootstrap_features_have_no_extension_and_no_legal_links(): void
    {
        $features = $this->bootstrapFeatures($this->createApp());

        self::assertSame(['board_smtp' => true, 'legal_links' => null, 'marketing_discover_url' => null], $features);
    }

    public function test_cloud_mode_disables_board_smtp_feature(): void
    {
        $features = $this->bootstrapFeatures($this->cloudApp());

        self::assertFalse($features['board_smtp']);
        self::assertNull($features['legal_links']);
    }

    public function test_extension_features_merge_into_bootstrap(): void
    {
        $extension = new StubExtension(['features' => [
            'billing'     => true,
            'legal_links' => ['de' => [['label' => 'Impressum', 'href' => 'https://example.test/impressum']]],
        ]]);

        $features = $this->bootstrapFeatures($this->createApp(extensions: [$extension]));

        self::assertTrue($features['billing']);
        self::assertTrue($features['board_smtp']);
        self::assertSame('Impressum', $features['legal_links']['de'][0]['label']);
    }

    // -------------------------------------------------------------------------
    // Extension routes + CSRF exemption
    // -------------------------------------------------------------------------

    public function test_extension_global_route_is_csrf_exempt_only_with_its_header(): void
    {
        $extension = new StubExtension(['routes' => true, 'csrf_exemptions' => ['/ext/webhook' => 'X-Ext-Sig']]);
        $app       = $this->createApp(extensions: [$extension]);

        $signed = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook')
            ->withHeader('X-Ext-Sig', 'ts=1;h1=abc');
        self::assertSame(200, $app->handle($signed)->getStatusCode());

        $bare = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook');
        self::assertSame(403, $app->handle($bare)->getStatusCode());
    }

    public function test_without_extension_the_route_does_not_exist(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/ext/webhook')
            ->withHeader('X-Ext-Sig', 'ts=1;h1=abc');

        // Not registered at all without the extension (routing runs before
        // CSRF, so an unknown path is a plain 404).
        self::assertSame(404, $this->createApp()->handle($request)->getStatusCode());
        self::assertSame(404, $this->createApp()->handle($this->get('/admin/ext'))->getStatusCode());
    }

    public function test_extension_account_route_uses_prefix_and_core_authz(): void
    {
        $extension = new StubExtension(['routes' => true]);

        // self-host: unprefixed, owner only
        $ownerId = $this->insertUser('owner-ext@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $modId = $this->insertUser('mod-ext@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $app = $this->createApp(extensions: [$extension]);
        self::assertSame(200, $app->handle($this->get('/admin/ext', $ownerId))->getStatusCode());
        self::assertSame(403, $app->handle($this->get('/admin/ext', $modId))->getStatusCode());
        self::assertSame(401, $app->handle($this->get('/admin/ext'))->getStatusCode());

        // cloud: {account}-prefixed, foreign tenant fails closed
        $accountId = $this->insertAccount(['slug' => 'ext-tenant']);
        $tenantOwner = $this->insertUser('owner-tenant@example.com');
        $this->insertAccountMember($accountId, $tenantOwner, 'owner');

        $cloud = $this->cloudApp([$extension]);
        $ok    = $cloud->handle($this->get('/ext-tenant/admin/ext', $tenantOwner));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('/{account}', json_decode((string) $ok->getBody(), true)['prefix']);
        self::assertSame(403, $cloud->handle($this->get('/ext-tenant/admin/ext', $ownerId))->getStatusCode());
        self::assertSame(404, $cloud->handle($this->get('/no-such-tenant/admin/ext', $tenantOwner))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Account-deletion precondition
    // -------------------------------------------------------------------------

    public function test_blocking_precondition_fails_closed_without_scheduling(): void
    {
        $accountId = $this->insertAccount(['slug' => 'blocked-tenant']);
        $ownerId   = $this->insertUser('owner-blocked@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $extension = new StubExtension(['block_deletion' => new DeletionBlocked(502, 'external_cancel_failed', 'Try again later.')]);
        $response  = $this->cloudApp([$extension])->handle($this->postWithCsrf(
            '/blocked-tenant/admin/account/delete',
            ['confirm_slug' => 'blocked-tenant'],
            $ownerId,
        ));

        self::assertSame(502, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('external_cancel_failed', $data['error']['key'] ?? null);
        self::assertNull($this->conn->fetchOne('SELECT deletion_scheduled_at FROM accounts WHERE id = :id', ['id' => $accountId]));
    }

    public function test_precondition_runs_after_confirmation_check(): void
    {
        $accountId = $this->insertAccount(['slug' => 'blocked-tenant-2']);
        $ownerId   = $this->insertUser('owner-blocked-2@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $extension = new StubExtension(['block_deletion' => new DeletionBlocked(502, 'external_cancel_failed', 'Try again later.')]);
        $response  = $this->cloudApp([$extension])->handle($this->postWithCsrf(
            '/blocked-tenant-2/admin/account/delete',
            ['confirm_slug' => 'wrong'],
            $ownerId,
        ));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('confirmation_mismatch', json_decode((string) $response->getBody(), true)['error']['key'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Static response headers
    // -------------------------------------------------------------------------

    public function test_extension_response_headers_apply_to_every_response_including_denials(): void
    {
        $extension = new StubExtension(['response_headers' => ['X-Robots-Tag' => 'noindex, nofollow']]);
        $app       = $this->createApp(extensions: [$extension]);

        $ok = $app->handle($this->get('/api/bootstrap'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('noindex, nofollow', $ok->getHeaderLine('X-Robots-Tag'));
        // Core's own headers are untouched next to the extension's.
        self::assertStringContainsString("default-src 'self'", $ok->getHeaderLine('Content-Security-Policy'));

        // A denial from inside the pipeline (CSRF) carries it too — same
        // middleware as core's own security headers, same coverage.
        $denied = $app->handle((new ServerRequestFactory())->createServerRequest('POST', '/login')->withParsedBody(['email' => 'x@example.com']));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('noindex, nofollow', $denied->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-store', $denied->getHeaderLine('Cache-Control'));

        // Without the extension the header does not exist.
        self::assertSame('', $this->createApp()->handle($this->get('/api/bootstrap'))->getHeaderLine('X-Robots-Tag'));
    }

    public function test_extension_may_not_override_a_core_security_header(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Content-Security-Policy');

        $this->createApp(extensions: [new StubExtension(['response_headers' => ['Content-Security-Policy' => 'default-src *']])]);
    }

    // -------------------------------------------------------------------------
    // Middleware on core-owned routes (CoreRoute)
    // -------------------------------------------------------------------------

    public function test_guard_on_invite_send_short_circuits_before_core_and_sends_no_mail(): void
    {
        $ownerId = $this->insertUser('owner-guard@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $mailer    = new InMemoryMailer();
        $extension = new StubExtension(['route_middleware' => [
            CoreRoute::INVITE_SEND => [StubExtension::reply(403, '{"error":{"key":"disabled_here","message":"Not on this installation."}}')],
        ]]);
        $app = $this->createApp($mailer, extensions: [$extension]);

        $response = $app->handle($this->postWithCsrf('/admin/invites', ['email' => 'new@example.com', 'role' => 'moderator'], $ownerId));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('disabled_here', json_decode((string) $response->getBody(), true)['error']['key'] ?? null);
        self::assertSame([], $mailer->sent);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM invites'));

        // Untouched routes still work — the guard is route-specific.
        self::assertSame(200, $app->handle($this->get('/api/bootstrap', $ownerId))->getStatusCode());
    }

    public function test_guard_on_login_request_replaces_the_magic_link_flow(): void
    {
        $mailer    = new InMemoryMailer();
        $extension = new StubExtension(['route_middleware' => [
            CoreRoute::LOGIN_REQUEST => [StubExtension::reply(200, '{"ok":true}')],
        ]]);
        $app = $this->createApp($mailer, extensions: [$extension]);

        $response = $app->handle($this->postWithCsrf('/login', ['email' => 'someone@example.com'], null));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode((string) $response->getBody(), true)['ok'] ?? false);
        self::assertSame([], $mailer->sent);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM login_tokens'));
    }

    public function test_guard_on_robots_txt_replaces_the_crawler_policy(): void
    {
        $extension = new StubExtension(['route_middleware' => [
            CoreRoute::ROBOTS_TXT => [StubExtension::reply(200, "User-agent: *\nDisallow: /\n", 'text/plain; charset=utf-8')],
        ]]);

        self::assertSame("User-agent: *\nDisallow:\n", (string) $this->createApp()->handle($this->get('/robots.txt'))->getBody());
        self::assertSame("User-agent: *\nDisallow: /\n", (string) $this->createApp(extensions: [$extension])->handle($this->get('/robots.txt'))->getBody());
    }

    public function test_observer_sits_outside_core_authz_and_sees_the_final_status(): void
    {
        $modId = $this->insertUser('mod-observe@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $observer  = new StatusRecorder();
        $extension = new StubExtension(['route_middleware' => [
            CoreRoute::INVITE_SEND => [$observer],
        ]]);
        $app = $this->createApp(extensions: [$extension]);

        // A moderator may not invite: AuthZ (inside the extension middleware)
        // answers 403 and the observer sees exactly that on the way out.
        $response = $app->handle($this->postWithCsrf('/admin/invites', ['email' => 'x@example.com', 'role' => 'moderator'], $modId));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([403], $observer->statuses);

        // Other routes never touch the observer.
        $app->handle($this->get('/api/bootstrap'));
        self::assertSame([403], $observer->statuses);
    }

    public function test_unknown_route_name_fails_the_boot(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown core route');

        $this->createApp(extensions: [new StubExtension(['route_middleware' => ['core.admin.members' => [StubExtension::reply(403, '{}')]]])]);
    }

    public function test_route_absent_in_this_routing_mode_fails_the_boot(): void
    {
        $extension = new StubExtension(['route_middleware' => [CoreRoute::BOARD_SMTP_TEST => [StubExtension::reply(403, '{}')]]]);

        // Self-host: the route exists, the guard attaches.
        $this->createApp(extensions: [$extension]);

        // Cloud: per-board SMTP is not registered at all — a guard on it
        // would silently protect nothing, so the configuration is rejected.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not registered in routing_mode "cloud"');
        $this->cloudApp([$extension]);
    }

    // -------------------------------------------------------------------------
    // Sanctioned session issuing
    // -------------------------------------------------------------------------

    public function test_extension_issues_a_session_through_the_shared_login_path(): void
    {
        $userId = $this->insertUser('ext-session@example.com', ['token_version' => 3]);
        $app    = $this->createApp(extensions: [new StubExtension(['session_route' => true])]);

        $response = $app->handle($this->postWithCsrf('/ext/session', ['user_id' => $userId], null));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'redirect' => '/'], json_decode((string) $response->getBody(), true));

        $cookies = array_values(array_filter($response->getHeader('Set-Cookie'), static fn (string $c): bool => str_starts_with($c, 'votepit_sess=')));
        self::assertCount(1, $cookies);
        self::assertStringContainsString('HttpOnly', $cookies[0]);
        $value   = explode(';', substr($cookies[0], strlen('votepit_sess=')), 2)[0];
        $payload = (new SessionService(str_repeat('a', 64), 3600, false))->verify($value);
        self::assertSame($userId, $payload['uid'] ?? null);
        self::assertSame(3, $payload['v'] ?? null);

        // Unknown user → no session.
        $missing = $app->handle($this->postWithCsrf('/ext/session', ['user_id' => 999999], null));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame([], array_filter($missing->getHeader('Set-Cookie'), static fn (string $c): bool => str_starts_with($c, 'votepit_sess=')));
    }

    // -------------------------------------------------------------------------
    // Edition gate: per-board SMTP is self-host only
    // -------------------------------------------------------------------------

    public function test_board_smtp_routes_exist_in_self_host_but_not_in_cloud_mode(): void
    {
        $ownerId = $this->insertUser('owner-smtp@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->insertBoard('smtp-board');

        self::assertSame(200, $this->createApp()->handle($this->get('/admin/boards/smtp-board/smtp', $ownerId))->getStatusCode());

        $accountId   = $this->insertAccount(['slug' => 'smtp-tenant']);
        $tenantOwner = $this->insertUser('owner-smtp-tenant@example.com');
        $this->insertAccountMember($accountId, $tenantOwner, 'owner');
        $this->insertBoard('tenant-board', ['account_id' => $accountId]);

        $cloud = $this->cloudApp();
        // The account itself resolves fine in cloud mode …
        self::assertSame(200, $cloud->handle($this->get('/smtp-tenant/admin/tokens', $tenantOwner))->getStatusCode());
        // … but the SMTP routes are not registered at all.
        self::assertSame(404, $cloud->handle($this->get('/smtp-tenant/admin/boards/tenant-board/smtp', $tenantOwner))->getStatusCode());
        self::assertSame(404, $cloud->handle($this->postWithCsrf('/smtp-tenant/admin/boards/tenant-board/smtp/test', [], $tenantOwner))->getStatusCode());
    }
}
