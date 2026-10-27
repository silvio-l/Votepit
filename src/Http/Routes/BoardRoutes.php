<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\BoardOperatorAcknowledgmentGate;
use Votepit\Http\Action\BoardActiveSetAction;
use Votepit\Http\Action\BoardCreateAction;
use Votepit\Http\Action\BoardHomeAction;
use Votepit\Http\Action\BoardListAction;
use Votepit\Http\Action\DefaultBoardAction;
use Votepit\Http\Action\OnboardingCompleteAction;
use Votepit\Http\Action\TelemetryOptInAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Support\Clock;

/**
 * Account-scoped board overview/create/active-set, Setup Wizard
 * onboarding/telemetry, the default-board lookup and the board home
 * catch-all GET {account}/{board}.
 *
 * Every fixed `/admin/...`/`/api/...` route here MUST stay registered
 * before the catch-all GET /{board} (see the per-route comments).
 */
final class BoardRoutes
{
    /**
     * Board admin overview/create/onboarding + the board home catch-all.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        TagRepository $tagRepo,
        BoardOperatorAcknowledgmentGate $boardOperatorAckGate,
        BoardStatusRepository $boardStatusRepo,
        Clock $clock,
        AnonymousSubmissionGate $anonymousSubmissionGate,
        ?BoardAssetRepository $boardAssets = null,
    ): void {
        // GET /admin/boards — account-scoped board overview.
        // AuthZ: accountAdmin. MUST be registered before the catch-all GET /{board},
        // otherwise "admin" would be incorrectly interpreted as a board slug.
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $app->get($accountPrefix . '/admin/boards', (new BoardListAction($boardRepo, $accountRepoForPlan, $accountPlans, $planPolicy))->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/onboarding/complete — dismisses the first-run Setup
        // Wizard for the current account (finished or explicitly skipped;
        // see OnboardingCompleteAction). AuthZ: accountAdmin, same tier as
        // the boards list/create routes the wizard itself calls.
        $app->post($accountPrefix . '/admin/onboarding/complete', (new OnboardingCompleteAction($accountRepoForPlan))->complete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/telemetry-opt-in — Setup Wizard's product-improvement
        // telemetry consent decision (self-host only in practice; see
        // TelemetryOptInAction). AuthZ: accountAdmin, same tier as the
        // rest of the wizard.
        $app->post($accountPrefix . '/admin/telemetry-opt-in', (new TelemetryOptInAction($accountRepoForPlan))->optIn(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/boards — creates a new board in the current account.
        // AuthZ: accountAdmin; CSRF globally enforced.
        // MUST be registered before the catch-all GET /{board}, otherwise
        // "admin" would be incorrectly interpreted as a board slug.
        $app->post($accountPrefix . '/admin/boards', (new BoardCreateAction($boardRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $audit, $boardOperatorAckGate))->create(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/boards/active-set — owner re-choice of which
        // board(s) stay active after a downgrade froze boards over the new
        // plan's limit. AuthZ: accountOwner (same tier as invites).
        // MUST be registered before the catch-all GET /{board}.
        $app->post($accountPrefix . '/admin/boards/active-set', (new BoardActiveSetAction($boardRepo, $accountPlans, $planPolicy, $audit))->set(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // GET /api/board/default — default board slug for the SPA root route `/`
        // (BoardPage.tsx has no :boardSlug there yet). MUST be registered before
        // the catch-all GET /{board}, otherwise "api" would be incorrectly
        // interpreted as a board slug.
        $app->get($accountPrefix . '/api/board/default', new DefaultBoardAction($boardRepo, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board} — board home = idea list (newest, status filter, pagination).
        // AuthZ: anon (reading is public). Unknown slug → 404.
        $brandResolver = new BrandResolver($accountPlans, $planPolicy, $boardAssets);
        $app->get($accountPrefix . '/{board}', new BoardHomeAction($boardRepo, $ideaRepo, $accountMemberRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $tagRepo, $boardStatusRepo, $clock, $brandResolver, $anonymousSubmissionGate, $boardAssets))
            ->add(AuthZMiddleware::anon($responseFactory));
    }
}
