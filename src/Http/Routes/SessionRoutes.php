<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Action\SessionBadgeAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\CredentialedCorsAllowlistMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Security\RateLimiter;

/**
 * GET /api/v1/session/badge — signed-in identity for the header badge (see
 * SessionBadgeAction). Global/unprefixed group-1 route: registered next to
 * /api/bootstrap, before any {account}-prefixed route (AppFactory safety net).
 */
final class SessionRoutes
{
    /**
     * @param App<null> $app
     */
    public static function register(App $app, ResponseFactory $responseFactory, Config $config, Connection $conn): void
    {
        // Trust level anon: the endpoint answers signed-out visitors too.
        // Per-IP limit like the other public reads; mandatory so a missing
        // or zero config entry can never switch it off (default 120/min:
        // one call per page view, generous for shared NAT addresses).
        $limit = $config->mandatoryRateLimit('session:badge', 120, 60);

        $app->get('/api/v1/session/badge', (new SessionBadgeAction($config))->badge(...))
            ->setName(CoreRoute::SESSION_BADGE)
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(new CredentialedCorsAllowlistMiddleware($config->corsBadgeOrigins))
            ->add(RateLimitMiddleware::perIp(
                new RateLimiter($conn),
                $responseFactory,
                'session:badge',
                $limit['limit'],
                $limit['window'],
                $config->trustCloudflareIp,
            ));

        // No preflight is ever granted: the GET carries no custom headers, so
        // browsers never send OPTIONS for it. An explicit 405 (no CORS
        // headers, route-level AuthZ) instead of letting Slim's routing
        // error decide the answer.
        $app->options('/api/v1/session/badge', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $response
            ->withStatus(405)
            ->withHeader('Allow', 'GET')
            ->withHeader('Cache-Control', 'no-store'))->add(AuthZMiddleware::anon($responseFactory));
    }
}
