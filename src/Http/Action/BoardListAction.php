<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\EffectivePlan;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;

/**
 * GET /admin/boards — board overview of the current account (AuthZ: accountAdmin).
 *
 * Read path for the admin overview page: returns exclusively the boards of
 * the account resolved via AccountContextMiddleware (id, slug, name). No
 * cross-tenant leak possible, since BoardRepository::listForAccount()
 * strictly filters by account_id.
 *
 * Onboarding follow-up: also returns `account.onboarding_completed_at` —
 * BoardsAdminPage.tsx uses it (together with an empty board list) to decide
 * whether to render the first-run Setup Wizard instead of the normal board
 * list/create-form. Piggy-backing on this existing round trip avoids a
 * second bootstrap-shaped endpoint just for one flag.
 *
 * Also returns `account.allowed_visibilities`/`account.default_visibility`
 * (from PlanPolicy, same source of truth as BoardBrandingAction) so the
 * create-board form can render an explicit, plan-aware visibility choice
 * BEFORE the board exists — mirrors the same fields already surfaced
 * post-creation in BoardBrandingAction's response.
 */
final readonly class BoardListAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boards    = $this->boardRepo->listForAccount($accountId);
        $account   = $this->accountRepo->findById($accountId);
        $rawPlan   = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $plan      = EffectivePlan::resolve($rawPlan, is_array($user) ? $user : null, $this->planPolicy);

        $response->getBody()->write((string) json_encode([
            'boards'  => $boards,
            'account' => [
                'onboarding_completed_at' => is_array($account) ? $account['onboarding_completed_at'] : null,
                'allowed_visibilities'    => $this->planPolicy->allowedVisibilities($plan),
                'default_visibility'      => $this->planPolicy->defaultVisibility($plan),
            ],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
