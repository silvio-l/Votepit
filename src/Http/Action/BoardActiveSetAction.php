<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\EffectivePlan;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;

/**
 * POST /admin/boards/active-set — owner picks which board(s) stay active
 * after a downgrade froze boards over the new plan's limit (plan
 * upgrade/downgrade/cancellation lifecycle: "customer picks the
 * active one"). AuthZ: accountOwner (same tier as invite/member mutations
 * and billing — MemberAction/BillingAction class docs).
 *
 * Body: `board_ids` — the list of board IDs that should end up UNFROZEN.
 * Every OTHER board of this account gets frozen. This is a full re-choice,
 * not an incremental toggle — matches BoardRepository::setActiveBoards()'s
 * contract (unfreeze exactly these, freeze everything else).
 *
 * Validation (account-scoped, plan-limit) happens here BEFORE any write —
 * BoardRepository::setActiveBoards() itself performs none:
 *   - every ID must resolve via BoardRepository::findByIdForAccount() for
 *     THIS account (cross-tenant IDs are silently dropped rather than
 *     erroring — same "structurally unfindable" posture as every other
 *     account-scoped chokepoint in this codebase).
 *   - the resulting active count must not exceed $this->planPolicy->boardLimit()
 *     for the account's CURRENT plan — an owner cannot use this route to
 *     keep more boards active than the plan allows.
 */
final readonly class BoardActiveSetAction
{
    public function __construct(
        private BoardRepository $boards,
        private AccountRepository $accounts,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    public function set(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $account = $this->accounts->findById($accountId);
        $rawPlan = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $plan    = EffectivePlan::resolve($rawPlan, is_array($user) ? $user : null, $this->planPolicy);
        $limit   = $this->planPolicy->boardLimit($plan);

        $parsed  = $request->getParsedBody();
        $rawIds  = is_array($parsed) ? ($parsed['board_ids'] ?? []) : [];
        $rawIds  = is_array($rawIds) ? $rawIds : [];

        /** @var list<int> $keepIds */
        $keepIds = [];
        foreach ($rawIds as $rawId) {
            $id = is_numeric($rawId) ? (int) $rawId : 0;
            if ($id <= 0) {
                continue;
            }

            // Account-scoped resolution — a foreign/unknown ID is silently
            // dropped instead of erroring (structurally unfindable, same
            // posture as every other account-scoped chokepoint here).
            $board = $this->boards->findByIdForAccount($id, $accountId);
            if (is_array($board) && !in_array($id, $keepIds, true)) {
                $keepIds[] = $id;
            }
        }

        if (count($keepIds) > $limit) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'plan_limit_boards',
                    'message' => "Your current plan allows at most {$limit} active board(s) at a time.",
                ],
            ]);
        }

        $this->boards->setActiveBoards($accountId, $keepIds);

        $this->audit->log('board.active_set', [
            'account_id' => $accountId,
            'board_ids'  => $keepIds,
        ]);

        return $this->json($response, 200, ['ok' => true, 'active_board_ids' => $keepIds]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
