<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\Action\WorklistAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Support\Clock;

/**
 * Operator worklist routes (P-06). Registered BEFORE the board catch-all
 * routes (`{accountPrefix}/{board}`): FastRoute shadows a static route that
 * is registered after a same-shape variable route.
 */
final class WorklistRoutes
{
    /** @param App<null> $app */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        string $accountPrefix,
        BoardRepository $boardRepo,
        IdeaRepository $ideaRepo,
        UserRepository $userRepo,
        AccountMemberRepository $accountMemberRepo,
        AuthzDenialLogger $authzDenialLogger,
        Clock $clock,
    ): void {
        $action = new WorklistAction($boardRepo, $ideaRepo, $userRepo, $clock);

        // GET {accountPrefix}/admin/worklist — header counts + one page of rows.
        // GET {accountPrefix}/admin/worklist/summary — header counts only.
        // AuthZ: accountModerate (owner|admin|moderator); anon → 401, other members → 403.
        $app->get($accountPrefix . '/admin/worklist', $action->list(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->get($accountPrefix . '/admin/worklist/summary', $action->summary(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST {accountPrefix}/admin/worklist/ideas/{id}/dismiss — hide an idea that needs no reaction (P-06 part B).
        // POST {accountPrefix}/admin/worklist/ideas/{id}/undismiss — undo. CSRF global; account-scoped in the action.
        $app->post($accountPrefix . '/admin/worklist/ideas/{id:[0-9]+}/dismiss', $action->dismiss(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/worklist/ideas/{id:[0-9]+}/undismiss', $action->undismiss(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
