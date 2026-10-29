<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Persistence\BoardWidgetRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\Password\InMemoryHibpHttpClient;
use Votepit\Security\WidgetJwtVerifier;
use Votepit\Security\WidgetTokenService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Embeddable widget end to end (ADR 0005, issue 20): the iframe page and
 * its framing headers, signed identify, the widget-token routes, the owner
 * settings, plan gating (downgrade ⇒ 404, settings kept; self-host
 * unrestricted) and cross-tenant isolation.
 */
final class WidgetEmbedTest extends IntegrationTestCase
{
    private const SECRET = 'widget-secret-0123456789abcdef0123456789abcdef';
    private const ORIGIN = 'https://shop.customer.example';

    private string $shellPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shellPath = sys_get_temp_dir() . '/votepit-widget-shell-' . uniqid() . '.html';
        file_put_contents($this->shellPath, "<!doctype html><html><head><title>Widget</title></head><body><div id=\"root\"></div></body></html>");
    }

    protected function tearDown(): void
    {
        if (is_file($this->shellPath)) {
            unlink($this->shellPath);
        }
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Built directly (not via createApp()) to inject the widget shell path.
     *
     * @param list<\Votepit\Extension\AppExtension> $extensions
     * @return App<null>
     */
    private function app(?\Votepit\Domain\PlanPolicy $policy = null, string $routingMode = 'self-host', array $extensions = []): App
    {
        return AppFactory::create(
            Config::fromArray($this->testConfigArray(['routing_mode' => $routingMode])),
            $this->conn,
            new InMemoryMailer(),
            new AuditLogger($this->logFile),
            avatarDirOverride: $this->avatarDir,
            planPolicy: $policy ?? self::syntheticPlanPolicy(),
            extensions: $extensions,
            hibpHttpClient: new InMemoryHibpHttpClient(),
            widgetShellPath: $this->shellPath,
        );
    }

    /** @return App<null> */
    private function cloudApp(): App
    {
        return $this->app(routingMode: 'cloud');
    }

    /** @param list<string> $origins */
    private function configureWidget(int $boardId, array $origins = [self::ORIGIN], ?string $secret = self::SECRET, ?int $accountId = null): void
    {
        $repo      = new BoardWidgetRepository($this->conn);
        $accountId ??= $this->defaultAccountId();
        $repo->saveOrigins($accountId, $boardId, $origins);
        if ($secret !== null) {
            $repo->saveSecret($accountId, $boardId, (new EncryptionService(str_repeat('a', 64), 'board_widget'))->encrypt($secret));
        }
    }

    /** @param array<string, mixed> $overrides */
    private static function jwt(int $boardId, array $overrides = [], string $secret = self::SECRET, string $alg = 'HS256'): string
    {
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $h   = $b64((string) json_encode(['alg' => $alg, 'typ' => 'JWT']));
        $p   = $b64((string) json_encode(array_merge([
            'email' => 'jane@customer.example',
            'name'  => 'Jane',
            'aud'   => WidgetJwtVerifier::audienceFor($boardId),
            'iat'   => time(),
            'exp'   => time() + 300,
        ], $overrides)));

        return $h . '.' . $p . '.' . $b64(hash_hmac('sha256', $h . '.' . $p, $secret, true));
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, ?string $bearer = null, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withHeader('Accept', 'application/json');
        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);
        }

        return $body === [] ? $request : $request->withHeader('Content-Type', 'application/json')->withParsedBody($body);
    }

    /** @param App<null> $app */
    private function identify(App $app, string $path, string $jwt): ResponseInterface
    {
        return $app->handle($this->request('POST', $path, $jwt));
    }

    /**
     * @param App<null>            $app
     * @param array<string, mixed> $claims
     */
    private function widgetToken(App $app, string $prefix, int $boardId, array $claims = []): string
    {
        $response = $this->identify($app, $prefix . '/widget/identify', self::jwt($boardId, $claims));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) json_decode((string) $response->getBody(), true)['token'];
    }

    /** @param array<string, mixed> $body */
    private function sessionMutation(string $method, string $path, int $userId, array $body = []): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId), 'votepit_csrf' => $csrf->sign($token)])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody($body);
    }

    private function frameAncestors(ResponseInterface $response): ?string
    {
        foreach (explode(';', $response->getHeaderLine('Content-Security-Policy')) as $directive) {
            $directive = trim($directive);
            if (str_starts_with($directive, 'frame-ancestors')) {
                return $directive;
            }
        }

        return null;
    }

    // ── Iframe page: framing headers (the security acceptance bar) ─────

    public function test_widget_page_relaxes_frame_ancestors_to_exactly_the_allowed_origins_without_xfo(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId, [self::ORIGIN, 'https://www.customer.example:8443']);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('frame-ancestors ' . self::ORIGIN . ' https://www.customer.example:8443', $this->frameAncestors($response));
        self::assertFalse($response->hasHeader('X-Frame-Options'), 'the widget page must not send X-Frame-Options');
        self::assertSame('cross-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame(1, substr_count($response->getHeaderLine('Content-Security-Policy'), 'frame-ancestors'));
        self::assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'the iframe runs cookie-less');
        self::assertFalse($response->hasHeader('X-Votepit-Internal-Frame-Ancestors'), 'internal marker must never leak');
        self::assertFalse($response->hasHeader('X-Votepit-Internal-Cookieless'), 'internal marker must never leak');
    }

    /** @return iterable<string, array{string, string}> */
    public static function otherRoutes(): iterable
    {
        yield 'board home'      => ['GET', '/demo'];
        yield 'idea detail'     => ['GET', '/demo/ideas/1'];
        yield 'roadmap'         => ['GET', '/demo/roadmap'];
        yield 'home'            => ['GET', '/'];
        yield 'health'          => ['GET', '/health'];
        // (Slim's own 404/405 exceptions are rendered by ErrorMiddleware,
        // outside SecurityHeaderMiddleware — those responses get their
        // framing headers from .htaccess, see HtaccessFramingTest.)
        yield 'widget identify' => ['POST', '/demo/widget/identify'];
        yield 'widget ideas'    => ['GET', '/demo/widget/ideas'];
        yield 'admin settings'  => ['GET', '/admin/boards/demo/widget'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('otherRoutes')]
    public function test_every_other_route_keeps_frame_ancestors_none_and_xfo_deny(string $method, string $path): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest($method, $path));

        self::assertSame("frame-ancestors 'none'", $this->frameAncestors($response), "$method $path");
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), "$method $path");
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'), "$method $path");
    }

    public function test_a_handler_cannot_relax_framing_with_an_invalid_origin_marker(): void
    {
        // Defence in depth: SecurityHeaderMiddleware re-validates the marker;
        // a corrupted row (e.g. written outside the validator) fails secure.
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $this->conn->update('board_widgets', ['allowed_origins' => json_encode(["https://ok.example; script-src *"])], ['board_id' => $boardId]);

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'));

        self::assertSame("frame-ancestors 'none'", $this->frameAncestors($response));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function test_only_the_widget_page_action_may_set_the_framing_marker(): void
    {
        // The relaxation is opt-in per response; keep the set of code paths
        // that can opt in reviewable (exactly one producer, one consumer).
        $users = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'INTERNAL_FRAME_ANCESTORS')) {
                $users[] = $file->getBasename();
            }
        }
        sort($users);

        self::assertSame(['SecurityHeaderMiddleware.php', 'WidgetPageAction.php'], $users);
    }

    public function test_widget_page_injects_escaped_config_and_records_install(): void
    {
        $boardId = $this->insertBoard('demo', ['name' => 'Acme "Ideas" <b>$1</b>']);
        $this->configureWidget($boardId);

        $response = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/demo/widget')
                ->withHeader('Referer', self::ORIGIN . '/features?ref=1'),
        );

        $html = (string) $response->getBody();
        self::assertStringContainsString('<meta name="votepit-widget" content="', $html);
        self::assertStringNotContainsString('<b>', $html, 'board name must be attribute-escaped');
        self::assertStringContainsString('&lt;b&gt;$1&lt;/b&gt;', $html);
        self::assertSame(self::ORIGIN, $this->conn->fetchOne('SELECT last_seen_origin FROM board_widgets WHERE board_id = ?', [$boardId]));
    }

    public function test_foreign_referer_is_not_recorded_as_install(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);

        $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget')->withHeader('Referer', 'https://evil.example/'));

        self::assertNull($this->conn->fetchOne('SELECT last_seen_origin FROM board_widgets WHERE board_id = ?', [$boardId]));
    }

    public function test_widget_page_is_404_without_configured_origins(): void
    {
        $this->insertBoard('demo');

        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame("frame-ancestors 'none'", $this->frameAncestors($response));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function test_private_board_is_never_embeddable(): void
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'private']);
        $this->configureWidget($boardId);

        self::assertSame(404, $this->app()->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'))->getStatusCode());
        self::assertSame(404, $this->identify($this->app(), '/demo/widget/identify', self::jwt($boardId))->getStatusCode());
    }

    // ── Plan gate ────────────────────────────────────────────────────────

    public function test_downgrade_turns_the_widget_off_with_404_but_keeps_the_settings(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $app   = $this->app();
        $token = $this->widgetToken($app, '/demo', $boardId);

        $this->setAccountPlan($this->defaultAccountId(), 'free');

        self::assertSame(404, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'))->getStatusCode());
        self::assertSame(404, $this->identify($app, '/demo/widget/identify', self::jwt($boardId))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('GET', '/demo/widget/ideas', $token))->getStatusCode());

        // Settings kept: an upgrade brings the widget straight back.
        self::assertSame([self::ORIGIN], json_decode((string) $this->conn->fetchOne('SELECT allowed_origins FROM board_widgets WHERE board_id = ?', [$boardId]), true));
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        self::assertSame(200, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('GET', '/demo/widget/ideas', $token))->getStatusCode());
    }

    public function test_self_host_is_unrestricted(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $this->setAccountPlan($this->defaultAccountId(), 'anything');

        $response = $this->app(new UnrestrictedPlanPolicy())->handle((new ServerRequestFactory())->createServerRequest('GET', '/demo/widget'));

        self::assertSame(200, $response->getStatusCode());
    }

    // ── Identify ─────────────────────────────────────────────────────────

    public function test_identify_issues_a_token_and_creates_the_user_by_hmac_only(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);

        $response = $this->identify($this->app(), '/demo/widget/identify', self::jwt($boardId, ['email' => 'New.User@customer.example']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertStringStartsWith('vpw1.', $data['token']);
        self::assertSame(WidgetTokenService::TTL, $data['expires_in']);
        self::assertSame('Jane', $data['user']['name']);
        self::assertFalse($response->hasHeader('Set-Cookie'));

        $hmac = (new IdentityHasher(self::identityServerKey()))->hashWidgetIdentity($this->defaultAccountId(), 'New.User@customer.example');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE email_hmac = ?', [$hmac]));
        self::assertStringNotContainsString('customer.example', $this->readAuditLog(), 'the asserted e-mail is never logged');
    }

    public function test_identify_never_resolves_to_the_global_user_with_that_email(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $global = $this->insertUser('jane@customer.example');

        $token  = $this->widgetToken($this->app(), '/demo', $boardId);
        $userId = (new WidgetTokenService(str_repeat('a', 64)))->verify($token, time())['user_id'] ?? null;

        self::assertNotNull($userId);
        self::assertNotSame($global, $userId, 'a customer-signed e-mail must not act as the real platform user');
        self::assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_identify_reuses_the_widget_user_within_the_account(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $app = $this->app();

        $first  = (new WidgetTokenService(str_repeat('a', 64)))->verify($this->widgetToken($app, '/demo', $boardId), time())['user_id'] ?? null;
        $second = (new WidgetTokenService(str_repeat('a', 64)))->verify($this->widgetToken($app, '/demo', $boardId), time())['user_id'] ?? null;

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    /** @return iterable<string, array{\Closure(int): string}> */
    public static function badJwts(): iterable
    {
        yield 'wrong secret'      => [static fn (int $b): string => self::jwt($b, secret: 'other-secret')];
        yield 'other board aud'   => [static fn (int $b): string => self::jwt($b + 1000)];
        yield 'expired'           => [static fn (int $b): string => self::jwt($b, ['iat' => time() - 900, 'exp' => time() - 300])];
        yield 'lifetime > 10 min' => [static fn (int $b): string => self::jwt($b, ['exp' => time() + 3600])];
        yield 'alg HS512'         => [static fn (int $b): string => self::jwt($b, alg: 'HS512')];
        yield 'alg none'          => [static fn (int $b): string => implode('.', array_slice(explode('.', self::jwt($b, alg: 'none')), 0, 2)) . '.'];
    }

    /** @param \Closure(int): string $make */
    #[\PHPUnit\Framework\Attributes\DataProvider('badJwts')]
    public function test_identify_rejects_invalid_jwts(\Closure $make): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);

        $response = $this->identify($this->app(), '/demo/widget/identify', $make($boardId));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function test_identify_without_a_configured_secret_is_rejected(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId, secret: null);

        self::assertSame(401, $this->identify($this->app(), '/demo/widget/identify', self::jwt($boardId))->getStatusCode());
    }

    public function test_identify_rejects_a_blocked_user(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $this->insertUser('unused@example.com', [
            'email_hmac' => (new IdentityHasher(self::identityServerKey()))->hashWidgetIdentity($this->defaultAccountId(), 'jane@customer.example'),
            'is_blocked' => 1,
        ]);

        self::assertSame(403, $this->identify($this->app(), '/demo/widget/identify', self::jwt($boardId))->getStatusCode());
    }

    // ── Widget-token routes ──────────────────────────────────────────────

    public function test_identified_visitor_votes_and_comments_without_cookies_or_csrf(): void
    {
        $boardId = $this->insertBoard('demo', ['anonymous_voting_enabled' => 1]);
        $this->configureWidget($boardId);
        $author = $this->insertUser('author@example.com');
        $ideaId = $this->seedIdea($boardId, $author);
        $app    = $this->app();
        $token  = $this->widgetToken($app, '/demo', $boardId);

        $vote = $app->handle($this->request('POST', "/demo/widget/ideas/{$ideaId}/vote", $token, ['value' => 'up']));
        self::assertSame(200, $vote->getStatusCode(), (string) $vote->getBody());
        self::assertFalse($vote->hasHeader('Set-Cookie'), 'no cookie (not even the anonymous-voter one) in the iframe');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = ?', [$ideaId]));

        $comment = $app->handle($this->request('POST', "/demo/widget/ideas/{$ideaId}/comments", $token, ['body' => 'Great idea from the widget.']));
        self::assertContains($comment->getStatusCode(), [200, 201], (string) $comment->getBody());
        self::assertFalse($comment->hasHeader('Set-Cookie'));

        $list = $app->handle($this->request('GET', '/demo/widget/ideas', $token));
        self::assertSame(200, $list->getStatusCode());
        $ideas = json_decode((string) $list->getBody(), true)['ideas'] ?? [];
        self::assertSame('up', $ideas[0]['my_vote'] ?? null);
    }

    public function test_identified_visitor_submits_an_idea(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $app   = $this->app();
        $token = $this->widgetToken($app, '/demo', $boardId);

        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');

        $response = $app->handle($this->request('POST', '/demo/widget/ideas', $token, [
            'title'    => 'Dark mode for the widget',
            'body'     => 'Would love a dark theme.',
            '_form_at' => $ts . '.' . $mac,
        ]));

        self::assertContains($response->getStatusCode(), [200, 201], (string) $response->getBody());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = ?', [$boardId]));
    }

    // ── Board deadlines (broaden-use-cases issue 04) ─────────────────────
    // The widget reuses the SPA's IdeaCreateAction/VoteAction instances, so
    // the submission/voting windows bind it exactly like the app. Dates far
    // in the past/future keep these tests independent of the real clock.

    public function test_widget_submission_outside_the_window_is_423_and_nothing_is_written(): void
    {
        $closed = $this->insertBoard('closed', ['submissions_closes_at' => '2000-01-01 00:00:00']);
        $soon   = $this->insertBoard('soon', ['submissions_opens_at' => '2099-01-01 00:00:00']);
        $this->configureWidget($closed);
        $this->configureWidget($soon);
        $app = $this->app();

        $ts  = (string) (time() - 5);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, str_repeat('a', 64), true)), '+/', '-_'), '=');
        $body = ['title' => 'Dark mode for the widget', 'body' => 'Would love a dark theme.', '_form_at' => $ts . '.' . $mac];

        foreach (['closed' => 'submissions_closed', 'soon' => 'submissions_not_open_yet'] as $slug => $key) {
            $boardId  = $slug === 'closed' ? $closed : $soon;
            $response = $app->handle($this->request('POST', "/{$slug}/widget/ideas", $this->widgetToken($app, "/{$slug}", $boardId), $body));
            self::assertSame(423, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($key, json_decode((string) $response->getBody(), true)['error']['key']);
        }
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas'));
    }

    public function test_widget_vote_outside_the_window_is_423_and_nothing_is_written(): void
    {
        $closed = $this->insertBoard('closed', ['voting_closes_at' => '2000-01-01 00:00:00']);
        $soon   = $this->insertBoard('soon', ['voting_opens_at' => '2099-01-01 00:00:00']);
        $this->configureWidget($closed);
        $this->configureWidget($soon);
        $author = $this->insertUser('author@example.com');
        $app    = $this->app();

        foreach (['closed' => 'voting_closed', 'soon' => 'voting_not_open_yet'] as $slug => $key) {
            $boardId  = $slug === 'closed' ? $closed : $soon;
            $ideaId   = $this->seedIdea($boardId, $author);
            $token    = $this->widgetToken($app, "/{$slug}", $boardId);
            $response = $app->handle($this->request('POST', "/{$slug}/widget/ideas/{$ideaId}/vote", $token, ['value' => 'up']));
            self::assertSame(423, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame($key, json_decode((string) $response->getBody(), true)['error']['key']);

            // The widget's board read carries the same server-computed phase.
            $list = json_decode((string) $app->handle($this->request('GET', "/{$slug}/widget/ideas", $token))->getBody(), true);
            self::assertSame($slug === 'closed' ? 'closed' : 'upcoming', $list['board']['phases']['voting']['state']);
        }
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokenRoutes(): iterable
    {
        yield 'list'    => ['GET', '/demo/widget/ideas'];
        yield 'submit'  => ['POST', '/demo/widget/ideas'];
        yield 'vote'    => ['POST', '/demo/widget/ideas/1/vote'];
        yield 'comment' => ['POST', '/demo/widget/ideas/1/comments'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenRoutes')]
    public function test_token_routes_require_a_widget_token_and_never_fall_back_to_the_session(string $method, string $path): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $userId = $this->insertUser('member@example.com');
        $app    = $this->app();

        // No Authorization at all: a mutating request is not CSRF-exempt and
        // dies in CsrfMiddleware (403) before the guard; a read gets 401.
        self::assertSame($method === 'GET' ? 401 : 403, $app->handle($this->request($method, $path))->getStatusCode());
        self::assertSame(401, $app->handle($this->request($method, $path, 'garbage'))->getStatusCode());
        // A valid first-party session + CSRF is NOT a widget token.
        self::assertSame(401, $app->handle($this->sessionMutation($method, $path, $userId, ['value' => 'up']))->getStatusCode());
    }

    public function test_extension_middleware_on_core_write_routes_also_wraps_the_widget_twins(): void
    {
        $boardId  = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $author   = $this->insertUser('author@example.com');
        $ideaId   = $this->seedIdea($boardId, $author);
        $recorder = new \Votepit\Tests\Support\StatusRecorder();
        $app      = $this->app(extensions: [new \Votepit\Tests\Support\StubExtension(['route_middleware' => [
            \Votepit\Http\CoreRoute::IDEA_VOTE      => [$recorder],
            \Votepit\Http\CoreRoute::IDEA_CREATE    => [$recorder],
            \Votepit\Http\CoreRoute::COMMENT_CREATE => [$recorder],
        ]])]);
        $token = $this->widgetToken($app, '/demo', $boardId);

        $app->handle($this->request('POST', "/demo/widget/ideas/{$ideaId}/vote", $token, ['value' => 'up']));
        $app->handle($this->request('POST', "/demo/widget/ideas/{$ideaId}/comments", $token, ['body' => 'Via the widget.']));
        $app->handle($this->request('POST', '/demo/widget/ideas', $token, ['title' => 'x']));

        self::assertCount(3, $recorder->statuses, 'an abuse guard on the core route must not be bypassable through the embed path');
    }

    public function test_token_of_board_a_is_rejected_on_board_b_of_the_same_account(): void
    {
        $boardA = $this->insertBoard('board-a');
        $boardB = $this->insertBoard('board-b', ['is_default' => 0]);
        $this->configureWidget($boardA);
        $this->configureWidget($boardB);
        $author = $this->insertUser('author@example.com');
        $ideaB  = $this->seedIdea($boardB, $author);
        $app    = $this->app();
        $tokenA = $this->widgetToken($app, '/board-a', $boardA);

        self::assertSame(401, $app->handle($this->request('POST', "/board-b/widget/ideas/{$ideaB}/vote", $tokenA, ['value' => 'up']))->getStatusCode());
        self::assertSame(401, $app->handle($this->request('GET', '/board-b/widget/ideas', $tokenA))->getStatusCode());
        // An idea of board B addressed through board A's path is not found.
        self::assertSame(404, $app->handle($this->request('POST', "/board-a/widget/ideas/{$ideaB}/vote", $tokenA, ['value' => 'up']))->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_cross_tenant_token_and_jwt_are_rejected_in_cloud_mode(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acme', 'plan' => 'lite']);
        $accountB = $this->insertAccount(['slug' => 'globex', 'plan' => 'lite']);
        $boardA   = $this->insertBoard('ideas', ['account_id' => $accountA, 'is_default' => 0]);
        $boardB   = $this->insertBoard('ideas', ['account_id' => $accountB, 'is_default' => 0]);
        $this->configureWidget($boardA, accountId: $accountA);
        $this->configureWidget($boardB, accountId: $accountB);
        $author = $this->insertUser('author@example.com');
        $ideaB  = $this->seedIdea($boardB, $author);
        $app    = $this->cloudApp();

        self::assertSame(200, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/acme/ideas/widget'))->getStatusCode());

        $tokenA = $this->widgetToken($app, '/acme/ideas', $boardA);

        // Same board slug, other tenant: the token is account-bound.
        self::assertSame(401, $app->handle($this->request('GET', '/globex/ideas/widget/ideas', $tokenA))->getStatusCode());
        self::assertSame(401, $app->handle($this->request('POST', "/globex/ideas/widget/ideas/{$ideaB}/vote", $tokenA, ['value' => 'up']))->getStatusCode());
        // A JWT minted for tenant A's board (same shared secret even) does not identify on tenant B.
        self::assertSame(401, $this->identify($app, '/globex/ideas/widget/identify', self::jwt($boardA))->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_the_same_asserted_email_is_a_different_user_per_account(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acme', 'plan' => 'lite']);
        $accountB = $this->insertAccount(['slug' => 'globex', 'plan' => 'lite']);
        $boardA   = $this->insertBoard('ideas', ['account_id' => $accountA, 'is_default' => 0]);
        $boardB   = $this->insertBoard('ideas', ['account_id' => $accountB, 'is_default' => 0]);
        $this->configureWidget($boardA, accountId: $accountA);
        $this->configureWidget($boardB, accountId: $accountB);
        $app      = $this->cloudApp();
        $verifier = new WidgetTokenService(str_repeat('a', 64));

        $userA = $verifier->verify($this->widgetToken($app, '/acme/ideas', $boardA), time())['user_id'] ?? null;
        $userB = $verifier->verify($this->widgetToken($app, '/globex/ideas', $boardB), time())['user_id'] ?? null;

        self::assertNotNull($userA);
        self::assertNotNull($userB);
        self::assertNotSame($userA, $userB, 'account A must not be able to act as account B\'s widget user');
    }

    public function test_rotating_the_secret_revokes_outstanding_tokens(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $app   = $this->app();
        $token = $this->widgetToken($app, '/demo', $boardId);

        $this->conn->update('board_widgets', ['secret_rotated_at' => '2030-01-01 00:00:00'], ['board_id' => $boardId]);

        self::assertSame(401, $app->handle($this->request('GET', '/demo/widget/ideas', $token))->getStatusCode());
    }

    public function test_a_blocked_user_token_is_refused(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $app    = $this->app();
        $token  = $this->widgetToken($app, '/demo', $boardId);
        $this->conn->update('users', ['is_blocked' => 1], ['email_hmac' => (new IdentityHasher(self::identityServerKey()))->hashWidgetIdentity($this->defaultAccountId(), 'jane@customer.example')]);

        self::assertSame(403, $app->handle($this->request('GET', '/demo/widget/ideas', $token))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('POST', '/demo/widget/ideas/1/vote', $token, ['value' => 'up']))->getStatusCode());
    }

    public function test_csrf_bearer_exemption_does_not_extend_to_first_party_routes(): void
    {
        $boardId = $this->insertBoard('demo');
        $this->configureWidget($boardId);
        $author = $this->insertUser('author@example.com');
        $ideaId = $this->seedIdea($boardId, $author);
        $userId = $this->insertUser('member@example.com');

        // Session cookie + an arbitrary Bearer header, but no CSRF token.
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/demo/ideas/{$ideaId}/vote")
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            ->withHeader('Authorization', 'Bearer anything')
            ->withParsedBody(['value' => 'up']);

        self::assertSame(403, $this->app()->handle($request)->getStatusCode());
    }

    // ── Owner settings ───────────────────────────────────────────────────

    public function test_owner_saves_origins_and_rotates_the_secret_once(): void
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->app();

        $put = $app->handle($this->sessionMutation('PUT', '/admin/boards/demo/widget', $ownerId, ['allowed_origins' => ['  HTTPS://Shop.Customer.example ', '']]));
        self::assertSame(200, $put->getStatusCode(), (string) $put->getBody());
        self::assertSame([self::ORIGIN], json_decode((string) $put->getBody(), true)['allowed_origins']);

        $rotate = $app->handle($this->sessionMutation('POST', '/admin/boards/demo/widget/secret', $ownerId));
        $secret = json_decode((string) $rotate->getBody(), true)['secret'] ?? null;
        self::assertIsString($secret);
        self::assertSame(64, strlen($secret));
        self::assertStringNotContainsString($secret, (string) $this->conn->fetchOne('SELECT secret_encrypted FROM board_widgets WHERE board_id = ?', [$boardId]));

        $get = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin/boards/demo/widget')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($ownerId)]));
        $data = json_decode((string) $get->getBody(), true);
        self::assertTrue($data['secret_configured']);
        self::assertTrue($data['plan_allowed']);
        self::assertSame(WidgetJwtVerifier::audienceFor($boardId), $data['audience']);
        self::assertStringNotContainsString($secret, (string) $get->getBody(), 'GET never returns the secret');

        // The revealed secret actually works for identify.
        self::assertSame(200, $this->identify($app, '/demo/widget/identify', self::jwt($boardId, secret: $secret))->getStatusCode());
    }

    public function test_settings_reject_invalid_origins(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        foreach ([['http://shop.example'], ['https://*.example.com'], ['https://a.example "; script-src *']] as $origins) {
            $response = $this->app()->handle($this->sessionMutation('PUT', '/admin/boards/demo/widget', $ownerId, ['allowed_origins' => $origins]));
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($origins));
        }
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_widgets'));
    }

    public function test_settings_writes_are_plan_gated_but_reads_stay_available(): void
    {
        $this->insertBoard('demo');
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $app = $this->app();

        $put = $app->handle($this->sessionMutation('PUT', '/admin/boards/demo/widget', $ownerId, ['allowed_origins' => [self::ORIGIN]]));
        self::assertSame(422, $put->getStatusCode());
        self::assertSame('plan_limit_widget', json_decode((string) $put->getBody(), true)['error']['key']);
        self::assertSame(422, $app->handle($this->sessionMutation('POST', '/admin/boards/demo/widget/secret', $ownerId))->getStatusCode());

        $get = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin/boards/demo/widget')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($ownerId)]));
        self::assertSame(200, $get->getStatusCode());
        self::assertFalse(json_decode((string) $get->getBody(), true)['plan_allowed']);
    }

    public function test_settings_require_account_admin(): void
    {
        $this->insertBoard('demo');
        $userId = $this->insertUser('plain@example.com');
        $app    = $this->app();

        self::assertSame(401, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/admin/boards/demo/widget'))->getStatusCode());
        self::assertSame(403, $app->handle($this->sessionMutation('PUT', '/admin/boards/demo/widget', $userId, ['allowed_origins' => [self::ORIGIN]]))->getStatusCode());
        self::assertSame(403, $app->handle($this->sessionMutation('POST', '/admin/boards/demo/widget/secret', $userId))->getStatusCode());
    }

    public function test_owner_of_tenant_a_cannot_configure_tenant_b_board_in_cloud_mode(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acme', 'plan' => 'lite']);
        $accountB = $this->insertAccount(['slug' => 'globex', 'plan' => 'lite']);
        $this->insertBoard('ideas', ['account_id' => $accountA, 'is_default' => 0]);
        $boardB  = $this->insertBoard('other', ['account_id' => $accountB, 'is_default' => 0]);
        $ownerA  = $this->insertUser('owner-a@example.com');
        $this->insertAccountMember($accountA, $ownerA, 'owner');
        $app = $this->cloudApp();

        // B's board through A's account prefix: not found, nothing written.
        self::assertSame(404, $app->handle($this->sessionMutation('PUT', '/acme/admin/boards/other/widget', $ownerA, ['allowed_origins' => [self::ORIGIN]]))->getStatusCode());
        // B's own prefix: not a member there.
        self::assertSame(403, $app->handle($this->sessionMutation('PUT', '/globex/admin/boards/other/widget', $ownerA, ['allowed_origins' => [self::ORIGIN]]))->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_widgets WHERE board_id = ?', [$boardB]));
    }

    public function test_repository_refuses_cross_account_writes(): void
    {
        $accountB = $this->insertAccount(['slug' => 'globex']);
        $boardB   = $this->insertBoard('other', ['account_id' => $accountB, 'is_default' => 0]);
        $repo     = new BoardWidgetRepository($this->conn);

        $repo->saveOrigins($this->defaultAccountId(), $boardB, [self::ORIGIN]);
        $repo->saveSecret($this->defaultAccountId(), $boardB, 'enc');

        self::assertFalse($this->conn->fetchOne('SELECT id FROM board_widgets WHERE board_id = ?', [$boardB]));
        self::assertNull($repo->findForBoard($this->defaultAccountId(), $boardB));
    }
}
