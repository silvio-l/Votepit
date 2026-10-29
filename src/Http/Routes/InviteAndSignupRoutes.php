<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\TermsAcceptanceGate;
use Votepit\Http\Action\InviteAcceptAction;
use Votepit\Http\Action\SignupAccountAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Security\TokenVault;

/**
 * Global (unprefixed) invite acceptance and cloud onboarding signup.
 *
 * Registered before the first {account}-prefixed route (cloud-routing
 * safety net, see AppFactory::create()).
 */
final class InviteAndSignupRoutes
{
    /**
     * Invite acceptance + cloud onboarding signup (both global/unprefixed).
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        InviteRepository $inviteRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepoForPlan,
        TokenVault $vault,
        BoardRepository $boardRepo,
        PlanPolicy $planPolicy,
        TermsAcceptanceGate $termsGate,
    ): void {

        // GET /invite/accept?token=<plaintext> — accept an invite.
        // AuthZ: user (anon → 401, the SPA then redirects to /login?r=…).
        // GET → CSRF-exempt (the one-time token is the capability, analogous to /login/verify).
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $app->get('/invite/accept', new InviteAcceptAction($inviteRepo, $accountMemberRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $vault, $audit))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GET/POST /signup/account — Cloud onboarding step 2 (cloud signup
        // onboarding). Cloud-mode only: self-host already operates
        // exactly one, pre-seeded account and never needs a second-account-
        // creation flow (Config::routingMode lockstep invariant). Global/
        // identity-scoped, unprefixed — MUST likewise stand here before the first
        // $accountPrefix route (2-segment route, same FastRoute
        // collision risk as /invite/accept, see comment block above).
        if ($config->routingMode === 'cloud') {
            $signupAction = new SignupAccountAction(new AccountRepository($conn), $accountMemberRepo, $boardRepo, $conn, $audit, $planPolicy, $termsGate);

            $app->get('/signup/account', $signupAction->status(...))
                ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

            $app->post('/signup/account', $signupAction->create(...))
                ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        }

    }
}
