<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\Action\SessionBadgeAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GET /api/v1/session/badge — the signed-in header badge endpoint.
 *
 * Pins: exact response shapes (signed out / signed in, no PII, no ids, no
 * roles), the CORS allowlist (exact origin, credentials only for listed
 * origins, empty by default), cache/vary headers, that anonymous and signed-in
 * callers never receive a Set-Cookie (no session created or extended), and the
 * per-IP rate limit.
 */
final class SessionBadgeTest extends IntegrationTestCase
{
    private const APP_ORIGIN = 'https://feedback.example.com';
    private const MARKETING  = 'https://votepit.com';

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        return Config::fromArray($this->testConfigArray(['app_url' => self::APP_ORIGIN, ...$overrides]));
    }

    /** @param array<string, string> $headers */
    private function badgeRequest(?string $cookie = null, array $headers = [], string $method = 'GET'): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, self::APP_ORIGIN . '/api/v1/session/badge', ['REMOTE_ADDR' => '198.51.100.7']);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookie !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $cookie]);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /** A session cookie whose inactivity claim is old enough to trigger AuthN's reissue. */
    private function staleSessionCookie(int $userId): string
    {
        return (new SessionService(str_repeat('a', 64), 3600, false))
            ->sign(['uid' => $userId, 'v' => 0, SessionService::CLAIM_LAST_ACTIVE => time() - 7200]);
    }

    // --- CORS allowlist -------------------------------------------------

    public function test_listed_origin_gets_allow_origin_and_credentials(): void
    {
        $app      = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));
        $response = $app->handle($this->badgeRequest(headers: ['Origin' => self::MARKETING]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::MARKETING, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function test_unlisted_origin_gets_neither_cors_header(): void
    {
        $app      = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));
        $response = $app->handle($this->badgeRequest(headers: ['Origin' => 'https://evil.example.com']));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function test_origin_match_is_exact_not_a_prefix_or_suffix(): void
    {
        $app = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));

        foreach (['https://votepit.com.evil.example', 'https://evilvotepit.com', 'https://votepit.com:8443', 'http://votepit.com'] as $origin) {
            $response = $app->handle($this->badgeRequest(headers: ['Origin' => $origin]));
            self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'), "origin {$origin} must not match");
        }
    }

    public function test_no_origin_header_gets_no_cors_headers(): void
    {
        $app      = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));
        $response = $app->handle($this->badgeRequest());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    public function test_empty_allowlist_gives_no_cors_to_anyone(): void
    {
        $app = $this->createApp(config: $this->config()); // default: cors_badge_origins = []

        self::assertSame([], $this->config()->corsBadgeOrigins);
        foreach ([self::MARKETING, 'https://dev.votepit-web.pages.dev', '*'] as $origin) {
            $response = $app->handle($this->badgeRequest(headers: ['Origin' => $origin]));
            self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'), "origin {$origin}");
            self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'), "origin {$origin}");
        }
    }

    public function test_vary_origin_is_present_for_every_origin_outcome(): void
    {
        $app = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));

        foreach ([self::MARKETING, 'https://evil.example.com', null] as $origin) {
            $headers  = $origin === null ? [] : ['Origin' => $origin];
            $response = $app->handle($this->badgeRequest(headers: $headers));
            self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
        }
    }

    public function test_options_preflight_grants_nothing_even_for_a_listed_origin(): void
    {
        $app      = $this->createApp(config: $this->config(['cors_badge_origins' => [self::MARKETING]]));
        $response = $app->handle($this->badgeRequest(headers: [
            'Origin'                        => self::MARKETING,
            'Access-Control-Request-Method' => 'GET',
        ], method: 'OPTIONS'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    // --- Response shape -------------------------------------------------

    public function test_signed_out_shape_is_exactly_authenticated_false(): void
    {
        $response = $this->createApp()->handle($this->badgeRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['authenticated' => false], $this->json($response));
    }

    public function test_signed_out_with_a_forged_cookie_is_still_anonymous(): void
    {
        $response = $this->createApp()->handle($this->badgeRequest(cookie: 'not-a-valid-session'));

        self::assertSame(['authenticated' => false], $this->json($response));
    }

    public function test_signed_in_shape_has_exactly_the_badge_keys_and_no_pii_or_ids(): void
    {
        $userId = $this->insertUser('badge@example.com', [
            'username'        => 'jane_doe',
            'username_lower'  => 'jane_doe',
            'avatar_filename' => 'avatar-abc.png',
        ]);

        $response = $this->createApp(config: $this->config())->handle(
            $this->badgeRequest(cookie: $this->sessionCookie($userId)),
        );
        $data = $this->json($response);

        self::assertSame(
            ['authenticated', 'displayName', 'initials', 'avatarUrl', 'appUrl', 'profileUrl'],
            array_keys($data),
        );
        self::assertTrue($data['authenticated']);
        self::assertSame('jane_doe', $data['displayName']);
        self::assertSame('JD', $data['initials']);
        self::assertSame(self::APP_ORIGIN . '/avatar/avatar-abc.png', $data['avatarUrl']);
        self::assertSame(self::APP_ORIGIN, $data['appUrl']);
        self::assertSame(self::APP_ORIGIN . '/account/profile', $data['profileUrl']);

        $publicId = (string) $this->conn->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $userId]);
        $encoded  = (string) $response->getBody();
        self::assertStringNotContainsString('badge@example.com', $encoded);
        self::assertStringNotContainsString($publicId, $encoded); // username is shown, the opaque id is not
        self::assertStringNotContainsString('"id"', $encoded);
        self::assertStringNotContainsString('"roles"', $encoded);
        self::assertStringNotContainsString('"memberships"', $encoded);
    }

    public function test_display_name_falls_back_to_public_id_and_avatar_is_null_when_unset(): void
    {
        $userId = $this->insertUser('noname@example.com', ['public_id' => 'pubXYZ12']);

        $data = $this->json($this->createApp(config: $this->config())->handle(
            $this->badgeRequest(cookie: $this->sessionCookie($userId)),
        ));

        self::assertSame('pubXYZ12', $data['displayName']);
        self::assertSame('PU', $data['initials']);
        self::assertNull($data['avatarUrl']);
    }

    public function test_blocked_user_is_reported_as_signed_out(): void
    {
        $userId = $this->insertUser('blocked@example.com', ['is_blocked' => 1, 'username' => 'blocked']);

        $data = $this->json($this->createApp(config: $this->config())->handle(
            $this->badgeRequest(cookie: $this->sessionCookie($userId)),
        ));

        self::assertSame(['authenticated' => false], $data);
    }

    public function test_malformed_hydrated_user_fails_closed_to_signed_out(): void
    {
        $request = $this->badgeRequest()->withAttribute(AuthNMiddleware::ATTR_USER, 'garbage');

        $response = (new SessionBadgeAction($this->config()))->badge($request, (new ResponseFactory())->createResponse());

        self::assertSame(['authenticated' => false], $this->json($response));
    }

    // --- Headers & session side effects ---------------------------------

    public function test_cache_and_hardening_headers_are_present(): void
    {
        $response = $this->createApp()->handle($this->badgeRequest());

        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Origin, Cookie', $response->getHeaderLine('Vary'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($response->hasHeader('X-Votepit-Internal-Cookieless'));
    }

    public function test_anonymous_caller_never_receives_a_set_cookie(): void
    {
        $response = $this->createApp()->handle($this->badgeRequest());

        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function test_signed_in_caller_with_stale_activity_gets_no_session_reissue(): void
    {
        $userId = $this->insertUser('stale@example.com', ['username' => 'stale']);
        $app    = $this->createApp(config: $this->config());

        // Control: a normal authenticated route reissues the session cookie
        // for this stale-activity session (AuthNMiddleware sliding refresh) ...
        $control = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', self::APP_ORIGIN . '/api/bootstrap')
                ->withCookieParams(['votepit_sess' => $this->staleSessionCookie($userId)]),
        );
        self::assertStringContainsString('votepit_sess=', $control->getHeaderLine('Set-Cookie'));

        // ... the badge must not, so polling it cannot keep a session alive.
        $response = $app->handle($this->badgeRequest(cookie: $this->staleSessionCookie($userId)));

        self::assertSame(['authenticated', 'displayName', 'initials', 'avatarUrl', 'appUrl', 'profileUrl'], array_keys($this->json($response)));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    // --- Rate limit -----------------------------------------------------

    public function test_per_ip_rate_limit_applies_to_the_badge(): void
    {
        $app = $this->createApp(config: $this->config([
            'rate_limits' => ['session:badge' => ['limit' => 2, 'window' => 60]],
        ]));

        self::assertSame(200, $app->handle($this->badgeRequest())->getStatusCode());
        self::assertSame(200, $app->handle($this->badgeRequest())->getStatusCode());
        $third = $app->handle($this->badgeRequest());

        self::assertSame(429, $third->getStatusCode());
    }

    public function test_rate_limit_cannot_be_switched_off_by_config(): void
    {
        // limit 0 is a "disabled" switch for most keys; for the badge the
        // mandatory variant falls back to its default (120/min), so 0 never
        // means unlimited.
        $app = $this->createApp(config: $this->config([
            'rate_limits' => ['session:badge' => ['limit' => 0, 'window' => 60]],
        ]));

        self::assertSame(200, $app->handle($this->badgeRequest())->getStatusCode());
    }
}
