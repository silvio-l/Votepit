<?php

declare(strict_types=1);

/**
 * Votepit — front controller.
 *
 * `public/` is the webroot. Application code (src/) and config live outside
 * it; on shared hosting without a freely choosable webroot, the .htaccess
 * protects the sensitive paths (see README).
 *
 * The Slim 4 app + PSR-15 middleware pipeline are built in Votepit\Http\AppFactory.
 */

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n";
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

// SPA shell fallback: the React client runs with BrowserRouter — every
// board/admin/login URL must serve the built SPA even on a direct hit
// (deep link, reload, shared link, magic-link click), not the JSON API
// response of the same-named Slim route. Distinction exactly as in the
// Vite dev proxy (vite.config.ts): the SPA's own fetch() calls ALWAYS set
// an explicit Accept header (api.ts request() → 'application/json',
// downloadAccountExport() → 'application/json' or 'application/zip') —
// only a real browser navigation sends 'text/html' first in the Accept
// header. Only then serve the shell; otherwise pass through to Slim
// unchanged (this also covers /robots.txt and the export blob download,
// neither of which carries 'text/html' in Accept).
//
// GET /login/oauth/{provider}/start and .../callback are the one exception:
// they too are real browser navigations with 'text/html' in Accept (the
// OAuth button does a full-page redirect, and the provider redirects back
// the same way), but they must reach OAuthStartAction/OAuthCallbackAction
// in Slim — not the static shell — since the whole point is the 302
// Location header those actions issue (to the provider, then back into the
// app). Serving the shell here instead silently swallows every OAuth login
// attempt behind a client-side 404, no error, no redirect (found live on
// staging 2026-09-07 testing real Google/GitHub credentials end-to-end).
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$isOAuthRedirectRoute = (bool) preg_match('#^/login/oauth/[^/]+/(?:start|callback)$#', $requestPath);

// Embeddable widget iframe page (ADR 0005): GET {account}/{board}/widget is
// a real browser navigation too, but it must be answered by
// WidgetPageAction in Slim — the ONLY response whose frame-ancestors is
// relaxed to the board's allowed origins. Serving the static SPA shell here
// would both break the embed (the shell carries the static, framing-denying
// headers from .htaccess) and skip the plan/origin gate. The pattern must
// stay in lockstep with WIDGET_PAGE_REQUEST in public/.htaccess.
$isWidgetPageRoute = (bool) preg_match('#^/(?:[a-z0-9-]+/)?[a-z0-9-]+/widget$#', $requestPath);

if (
    !$isOAuthRedirectRoute
    && !$isWidgetPageRoute
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')
) {
    $spaShell = __DIR__ . '/index.html';
    if (is_file($spaShell)) {
        header('Content-Type: text/html; charset=utf-8');
        // Must always revalidate — index.html references Vite's content-hashed
        // chunk filenames, and old chunks are deleted on every deploy. A
        // stale cached shell would reference now-404ing chunks (see the
        // .htaccess Cache-Control block for the same header on the
        // Apache-served path — this covers the PHP-rendered SPA-route path).
        header('Cache-Control: no-cache, must-revalidate');
        // Public board pages get their own favicon and share-image meta (P-28).
        // A member profile reached from a board (`?board=`) takes the same icon and share image (27b).
        // BoardShell fails open: on any error it returns the plain shell with 200.
        // A path that names an unknown account or board gets 404 with the same
        // shell body, so the SPA's own not-found view still renders.
        $boardContext = is_string($_GET['board'] ?? null) ? $_GET['board'] : null;
        $shell        = \Votepit\Http\Support\BoardShell::forRequest((string) file_get_contents($spaShell), $requestPath, $configPath, $boardContext);
        http_response_code($shell->status);
        // Whole HTML document; BoardShell escapes every injected value (htmlspecialchars, ENT_QUOTES).
        // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
        echo $shell->html;
        exit;
    }
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    // Lazy DBAL connection (connects only on the first query) — also carries
    // the RateLimit(IP) layer. The DB-less test uses null instead.
    $conn = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Votepit: invalid configuration (" . $e->getMessage() . ").\n";
    exit;
}

// Self-check: an installation with routing_mode: 'cloud' would answer every
// account-scoped page — e.g. /admin/boards — with only a 404, because the
// SPA frontend (core/app/src/App.tsx) doesn't yet have {account}-prefixed
// client routes (cloud multi-tenant routing is still open). The backend
// router itself handles cloud routing correctly and is fully tested
// (tests/Http/CloudRoutingTest.php) — only THIS built SPA bundle can't use
// it yet. So here (not in Config::fromArray, which the integration tests
// also run through) is a hard boot check just for the real front controller:
// a misconfigured installation now fails immediately and loudly instead of
// silently leaving users hanging with 404s. The check logic itself lives in
// SpaCapabilities::checkRoutingMode() (pure, unit-testable) — this is just
// the I/O part.
$routingModeError = \Votepit\SpaCapabilities::checkRoutingMode($config);
if ($routingModeError !== null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $routingModeError;
    exit;
}

\Votepit\Http\AppFactory::create($config, $conn)->run();
