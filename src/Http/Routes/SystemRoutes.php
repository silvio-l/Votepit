<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Monitoring\ReadinessCheck;

/**
 * Installation-level system routes (GET /, /health, /robots.txt — the
 * only routes that also exist in the DB-less smoke-test app — plus the
 * DB-backed GET /ready).
 */
final class SystemRoutes
{
    /**
     * Smoke/liveness/robots — anon, no DB required.
     *
     * @param App<null> $app
     */
    public static function register(App $app, ResponseFactory $responseFactory, AuditLogger $audit): void
    {
        // --- Routes -------------------------------------------------------
        // Smoke route: proves boot + pipeline + security headers.
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit): ResponseInterface {
            $audit->log('smoke.hit', ['ua' => $request->getHeaderLine('User-Agent')]);
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $response->getBody()->write((string) json_encode([
                'ok'               => true,
                'status'           => 'Security foundation active.',
                'csrf_token'       => is_string($csrfToken) ? $csrfToken : '',
                'is_authenticated' => $user !== null,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // GET /health — dedicated liveness endpoint for uptime monitoring/load
        // balancers: unlike `/`, this is a documented, stable contract, not
        // the informal smoke route — no audit logging (would spam the log on
        // every health-check interval) and no DB touch (liveness only, not readiness — a DB
        // outage should surface as failing application routes, not as this
        // probe flapping the process supervisor/load balancer).
        $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // GET /robots.txt — everything allowed (an installation is crawlable by
        // default; the SPA's public board pages are meant to be found). Named
        // so an extension can replace the policy (CoreRoute::ROBOTS_TXT).
        $app->get('/robots.txt', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write("User-agent: *\nDisallow:\n");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        })->setName(CoreRoute::ROBOTS_TXT)->add(AuthZMiddleware::anon($responseFactory));
    }

    /**
     * GET /ready — readiness probe (DB required, so registered from the
     * DB-backed block of AppFactory; the global per-IP rate limit applies).
     * Intentionally public (AuthZ anon) like /health: uptime monitors and
     * load balancers probe without a session. The body is `{"ok":true}` or
     * `{"ok":false}` and nothing else — no failing check, version, path or
     * error text ever leaves the server; the failed check names go to the
     * audit log for the operator instead.
     *
     * @param App<null> $app
     */
    public static function registerReadiness(App $app, ResponseFactory $responseFactory, ReadinessCheck $readiness, AuditLogger $audit): void
    {
        $app->get('/ready', function (ServerRequestInterface $request, ResponseInterface $response) use ($readiness, $audit): ResponseInterface {
            $failures = $readiness->failures(new \DateTimeImmutable());
            if ($failures !== []) {
                $audit->log('readiness.not_ready', ['failed' => implode(',', $failures)]);
            }

            $response->getBody()->write($failures === [] ? '{"ok":true}' : '{"ok":false}');
            return $response
                ->withStatus($failures === [] ? 200 : 503)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store');
        })->add(AuthZMiddleware::anon($responseFactory));
    }
}
