<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\Action\BoardDiscoveryAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Persistence\BoardRepository;

/**
 * Public, cross-tenant board discovery (GET /discover, anon).
 */
final class DiscoveryRoutes
{
    /**
     * Public board discovery listing.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        BoardRepository $boardRepo,
    ): void {
        // GET /discover — public, cross-tenant board discovery listing
        // (BoardRepository::listPublicDiscovery(), only visibility='public'
        // boards of confirmed/unlocked/non-deletion-scheduled accounts).
        // Anon and platform-wide, must be registered here (before the
        // first $accountPrefix route) for the same FastRoute-collision
        // reason as /faq/reports//operator/* above.
        $app->get('/discover', (new BoardDiscoveryAction($boardRepo))->list(...))
            ->add(AuthZMiddleware::anon($responseFactory));
    }
}
