<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Action\ApiTokenAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ApiTokenAuthenticator;

/**
 * Session-authenticated Agent-API token administration under
 * {account}/admin/tokens (the bearer-token endpoints themselves live in
 * ApiV1Routes).
 */
final class ApiTokenAdminRoutes
{
    /**
     * API token list/create/revoke (account-scoped).
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
        ApiTokenRepository $apiTokenRepo,
        ApiTokenAuthenticator $apiTokenAuthenticator,
    ): void {
        // GET  /admin/tokens             — list the account's tokens (all boards).
        // POST /admin/tokens             — create a token (plaintext only in this response).
        // POST /admin/tokens/{id}/revoke — revoke a token.
        // AuthZ: accountAdmin (owner + admin — mirrors branding/moderation/board-SMTP, see ApiTokenAction doc).
        // Account-scoped since migration 0044 — no board slug in the path,
        // a token's board grants are chosen in the request body (create()).
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $apiTokenAction = new ApiTokenAction($boardRepo, $apiTokenRepo, $apiTokenAuthenticator, $accountPlans, $planPolicy, $audit);

        $app->get($accountPrefix . '/admin/tokens', $apiTokenAction->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/tokens', $apiTokenAction->create(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/tokens/{id:[0-9]+}/revoke', $apiTokenAction->revoke(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
