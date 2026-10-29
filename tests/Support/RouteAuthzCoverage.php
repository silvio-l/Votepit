<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Slim\App;
use Slim\Interfaces\RouteInterface;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\IssueTrackerWebhookMiddleware;

/**
 * Deny-by-default AuthZ coverage check over a built Slim app's route table
 * (see core/tests/Http/RouteAuthzCoverageTest.php and
 * cloud/tests/RouteAuthzCoverageTest.php).
 *
 * Every route must fall into exactly one of three classes:
 *
 *  1. Guarded: carries an AuthZMiddleware with a level other than `anon`.
 *     Nothing to list — this is the default for every new route.
 *  2. Public: carries AuthZMiddleware::anon() and is listed in an ANON
 *     allow-list with a one-line reason why it is genuinely public.
 *  3. No AuthZ at all: listed in a NO_AUTHZ allow-list with a reason AND the
 *     substitute guard middleware class that must be on the route instead
 *     (e.g. ApiTokenAuthMiddleware for /api/v1/*, a payment provider's
 *     signature middleware for its webhook). `guard => null` is only for routes that
 *     are public by design and need no session machinery at all.
 *
 * Keys are `METHOD /pattern` exactly as registered, except that a leading
 * `/{account}` (cloud routing mode) is stripped, so one entry covers both
 * routing modes. Entries that no longer match any route are reported by
 * staleEntries() so the lists cannot silently rot.
 *
 * Cloud-only routes (CloudExtension) are allow-listed in the cloud test,
 * not here — this file is part of the public core export.
 *
 * @phpstan-type NoAuthzList array<string, array{guard: class-string|null, reason: string}>
 * @phpstan-type AnonList array<string, string>
 */
final class RouteAuthzCoverage
{
    /**
     * Routes deliberately WITHOUT AuthZMiddleware (exception class). Each
     * must carry its `guard` middleware instead.
     *
     * @var NoAuthzList
     */
    public const CORE_NO_AUTHZ_ROUTES = [
        // Agent API: no session/cookies at all — authenticated by a hashed,
        // board-scoped Bearer token (ApiTokenAuthMiddleware fails closed with
        // 401) instead of the session-based AuthZ levels.
        'GET /api/v1/board'               => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API, Bearer API token instead of session AuthZ'],
        'GET /api/v1/ideas'               => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API, Bearer API token instead of session AuthZ'],
        'GET /api/v1/ideas/{id:[0-9]+}'   => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API, Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas'              => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (write), Bearer API token instead of session AuthZ'],
        'POST /api/v1/mcp'                => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'MCP endpoint, Bearer API token instead of session AuthZ (board from token scope)'],
        'POST /api/v1/ideas/{id:[0-9]+}/vote'                      => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (write grant), Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas/{id:[0-9]+}/comments'                  => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (write grant), Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas/{id:[0-9]+}/status'                    => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (moderate grant + issuer role, ApiIdeaWriteAction), Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas/{id:[0-9]+}/tags'                      => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (moderate grant + issuer role, ApiIdeaWriteAction), Bearer API token instead of session AuthZ'],
        'DELETE /api/v1/ideas/{id:[0-9]+}/tags/{tagId:[0-9]+}'     => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (moderate grant + issuer role, ApiIdeaWriteAction), Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas/{id:[0-9]+}/merge'                     => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (moderate grant + issuer role, ApiIdeaWriteAction), Bearer API token instead of session AuthZ'],
        'POST /api/v1/ideas/{id:[0-9]+}/unmerge'                   => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (moderate grant + issuer role, ApiIdeaWriteAction), Bearer API token instead of session AuthZ'],
        'GET /api/v1/board/branding'                               => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (read), Bearer API token instead of session AuthZ'],
        'PATCH /api/v1/board/branding'                             => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (manage grant, effective only for an owner/admin issuer), Bearer API token instead of session AuthZ'],
        'POST /api/v1/board/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}'   => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (manage grant, effective only for an owner/admin issuer), Bearer API token instead of session AuthZ'],
        'DELETE /api/v1/board/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}' => ['guard' => ApiTokenAuthMiddleware::class, 'reason' => 'Agent API (manage grant, effective only for an owner/admin issuer), Bearer API token instead of session AuthZ'],
        // Linear/GitHub status sync (issue 19): server-to-server provider
        // webhooks with no session — authenticated by the per-connection
        // HMAC signature (+ Linear's signed-timestamp replay window), per-IP
        // rate-limited, fail-secure 404/401 without detail.
        'POST /api/issue-tracker-webhooks/linear/{hook:[0-9a-f]{32}}' => ['guard' => IssueTrackerWebhookMiddleware::class, 'reason' => 'server-to-server provider webhook, HMAC signature (+ Linear replay window) instead of session AuthZ'],
        'POST /api/issue-tracker-webhooks/github/{hook:[0-9a-f]{32}}' => ['guard' => IssueTrackerWebhookMiddleware::class, 'reason' => 'server-to-server provider webhook, HMAC signature instead of session AuthZ'],
    ];

    /**
     * Routes guarded only by AuthZMiddleware::anon() — reviewed as
     * genuinely public. Board-scoped reads still 404 for unknown/private
     * boards inside the action (visibility gate), and every write here is
     * rate-limited and/or capability-token based.
     *
     * @var AnonList
     */
    public const CORE_ANON_ROUTES = [
        // System
        'GET /'            => 'SPA entry / auth-status probe, no data',
        'GET /health'      => 'liveness probe for uptime monitoring',
        'GET /ready'       => 'readiness probe for monitors/load balancers, body is only {"ok":true|false}',
        'GET /robots.txt'  => 'crawler policy',
        'GET /api/bootstrap' => 'SPA bootstrap config (edition, features, current user if any)',
        'GET /faq'         => 'public FAQ list',
        'GET /discover'    => 'cross-tenant listing of visibility=public boards only (repository chokepoint)',
        'GET /api/v1/session/badge'     => 'header badge: {"authenticated":false} for anonymous callers; signed-in answer is only the own display name/initials/avatar; CORS only for the cors_badge_origins allowlist; never a Set-Cookie',
        'OPTIONS /api/v1/session/badge' => 'preflight answered 405 with no CORS header (grants nothing)',
        'POST /reports'    => 'DSA Art. 16 notice-and-action: anyone may report content (rate-limited)',

        // Authentication (must be reachable without a session)
        'GET /login'                           => 'login page data',
        'POST /login'                          => 'magic-link request (anti-enumeration, rate-limited)',
        'GET /login/verify'                    => 'magic-link redemption, the one-time token is the capability',
        'POST /login/password'                 => 'password login (rate-limited)',
        'POST /login/2fa'                      => 'second factor step, pending-login token is the capability (rate-limited)',
        'POST /password/reset/request'         => 'forgot-password request (anti-enumeration, rate-limited)',
        'POST /password/reset/confirm'         => 'password reset, single-use reset token is the capability',
        'POST /digest/unsubscribe'             => 'weekly digest one-click unsubscribe from a mail link, signed token is the capability (can only switch that user\'s digest off; rate-limited)',
        'POST /participant-mail/unsubscribe'   => 'participant-mail one-click unsubscribe from a mail link (RFC 8058), signed token bound to one recipient and category; can only switch mails off; rate-limited per IP',
        'POST /operator-nudges/unsubscribe'    => 'operator nudge one-click unsubscribe from a mail link, signed token is the capability (can only switch that one kind off for that user; rate-limited)',
        'GET /login/oauth/{provider}/start'    => 'OAuth login start',
        'GET /login/oauth/{provider}/callback' => 'OAuth login callback, state-checked (rate-limited)',
        // MCP OAuth 2.1 authorization server (issue 21): machine endpoints
        // an MCP client calls before it holds any credential. No session is
        // read; they are CSRF-exempt by route name and get no cookie
        // (CsrfMiddleware::SESSIONLESS_MACHINE_ROUTES). The consent itself is
        // GET/POST /api/oauth/authorize behind AuthZ user (+ CSRF).
        'GET /.well-known/oauth-authorization-server'          => 'RFC 8414 authorization server metadata (static discovery document)',
        'GET /.well-known/oauth-protected-resource'            => 'RFC 9728 protected resource metadata (static discovery document)',
        'GET /.well-known/oauth-protected-resource/api/v1/mcp' => 'RFC 9728 protected resource metadata, path-suffixed form for the MCP endpoint',
        'POST /oauth/register' => 'RFC 7591 dynamic client registration, public clients only, validated + size-capped (per-IP + global rate limit); a client authorizes nothing by itself',
        'POST /oauth/token'    => 'OAuth token endpoint, the single-use PKCE-bound code / rotating refresh token is the capability (per-IP rate-limited)',
        'POST /oauth/revoke'   => 'RFC 7009 revocation, the presented token is the capability, always 200 (per-IP rate-limited)',

        // Public board reads (visibility gate in the action, private → 404)
        'GET /api/board/default'                 => 'resolve the default public board',
        'GET /{board}'                           => 'public board home / idea list',
        'GET /{board}/roadmap'                   => 'public roadmap',
        'GET /{board}/tags'                      => 'public tag list of a board',
        'GET /{board}/assets/{id:[0-9a-f]{32}}'  => 'public board imagery by opaque 32-hex id, board-scoped, plan-gated (BoardAssetAction::serve)',
        'GET /{board}/ideas/{id:[0-9]+}'         => 'public idea detail',
        'GET /{board}/ideas/new'                 => 'SPA form shell (board info, auth status, time-trap stamp, guest availability)',
        'GET /{board}/ideas/{id:[0-9]+}/edit'    => 'SPA form shell; action returns 401 for anon and checks ownership',
        'GET /members/{userId:[0-9]+}/profile'   => 'public member profile (profile-visibility setting enforced in action)',
        'GET /avatar/{filename}'                 => 'profile pictures are public like a board logo',

        // Anonymous voting (ADR 0004)
        'POST /{board}/ideas/{id:[0-9]+}/vote'               => 'VoteAction enforces user OR board.anonymous_voting_enabled (rate-limited, Turnstile)',
        'PATCH /{board}/ideas/{id:[0-9]+}/vote/notify-email' => 'anonymous voter edits own vote, matched by voter-token hash, else 404',
        'POST /{board}/ideas/{id:[0-9]+}/vote/reason'        => 'VoteReasonAction: same identity rules as VoteAction (user, or anonymous voter with switch + voter cookie), down vote required, rate-limited idea:vote, board/frozen guards first',

        // Guest idea submission (ADR 0006). Every one answers like before
        // (401 / 404) unless the board is public AND switched on AND the
        // plan allows it; Turnstile + own rate limits on submit.
        'POST /{board}/ideas'                              => 'IdeaCreateAction: member path unchanged; anon → AnonymousIdeaSubmission (switch off → unchanged AuthZ-user 401)',
        'GET /{board}/ideas/search-duplicates'             => 'guest duplicate hint: published titles only, never the AI confirmer, own IP bucket; else AuthZ-user 401',
        'GET /{board}/ideas/guest-mine'                    => 'guest device list, matched by account-bound author-token hash; switch off → 404',
        'POST /{board}/ideas/{id:[0-9]+}/guest-withdraw'   => 'guest withdraws own PENDING idea, matched by author-token hash, else 404',

        // Embeddable widget (ADR 0005). The token-authenticated
        // /{board}/widget/ideas… routes carry AuthZ user behind
        // WidgetTokenAuthMiddleware and need no entry here.
        'GET /{board}/widget'           => 'iframe shell of a PUBLIC board, 404 unless the widget is live (plan + origins); the only frame-ancestors relaxation',
        'POST /{board}/widget/identify' => 'customer-signed HS256 identify JWT under the per-board secret is the capability (per-IP rate-limited)',
    ];

    /**
     * Human-readable violations (empty list = every route is covered).
     *
     * @param App<null> $app
     * @param NoAuthzList $noAuthz
     * @param AnonList $anon
     * @return list<string>
     */
    public static function violations(App $app, array $noAuthz, array $anon): array
    {
        $violations = [];
        foreach (RouteTable::routes($app) as $route) {
            $key   = self::key($route);
            $level = RouteTable::authzLevel($route);

            if ($level === null) {
                if (!array_key_exists($key, $noAuthz)) {
                    $violations[] = $key . ': no AuthZMiddleware and not in a NO_AUTHZ allow-list';
                    continue;
                }
                $guard = $noAuthz[$key]['guard'];
                if ($guard !== null && !self::hasMiddleware($route, $guard)) {
                    $violations[] = $key . ': allow-listed without AuthZ but its substitute guard ' . $guard . ' is missing';
                }
                continue;
            }

            if ($level === AuthZMiddleware::LEVEL_ANON && !array_key_exists($key, $anon)) {
                $violations[] = $key . ': AuthZMiddleware::anon() but not in an ANON allow-list (is it really public?)';
            }
        }

        return $violations;
    }

    /**
     * Allow-list keys that match no route in any of the given apps.
     *
     * @param list<App<null>> $apps
     * @param NoAuthzList $noAuthz
     * @param AnonList $anon
     * @return list<string>
     */
    public static function staleEntries(array $apps, array $noAuthz, array $anon): array
    {
        $seen = [];
        foreach ($apps as $app) {
            foreach (RouteTable::routes($app) as $route) {
                $seen[self::key($route)] = true;
            }
        }

        $stale = [];
        foreach (array_merge(array_keys($noAuthz), array_keys($anon)) as $key) {
            if (!isset($seen[$key])) {
                $stale[] = $key;
            }
        }

        return $stale;
    }

    /** `METHOD /pattern` with a leading `/{account}` stripped. */
    public static function key(RouteInterface $route): string
    {
        $pattern = preg_replace('#^/\{account\}(?=/)#', '', $route->getPattern()) ?? $route->getPattern();

        return implode('|', $route->getMethods()) . ' ' . $pattern;
    }

    /** @param class-string $class */
    private static function hasMiddleware(RouteInterface $route, string $class): bool
    {
        foreach (RouteTable::middleware($route) as $mw) {
            if (is_a($mw, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
