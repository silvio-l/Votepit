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
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
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
        readfile($spaShell);
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
