<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Action\BoardRoadmapAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * Public board roadmap (GET {account}/{board}/roadmap).
 */
final class RoadmapRoutes
{
    /**
     * Board-scoped public roadmap.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        BoardStatusRepository $boardStatusRepo,
        BoardAssetRepository $boardAssets,
        bool $ganttInstalled,
    ): void {
        // GET /{board}/roadmap — board-scoped roadmap.
        // Trust level: anon (public roadmap, no login required).
        // Returns only aggregates (score, consensus, comment count) — no voter PII.
        // Ideas are returned grouped by status (planned / in_progress / done);
        // open and declined don't appear. Uses idx_ideas_board_status.
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $app->get($accountPrefix . '/{board}/roadmap', new BoardRoadmapAction($boardRepo, $ideaRepo, $accountMemberRepo, $accountPlans, $planPolicy, $boardStatusRepo, new BrandResolver($accountPlans, $planPolicy, $boardAssets), $boardAssets, $ganttInstalled))
            ->add(AuthZMiddleware::anon($responseFactory));
    }
}
