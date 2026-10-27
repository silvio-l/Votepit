<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Persistence\BoardWidgetRepository;

/**
 * Single answer to "is the embeddable widget live for this board right
 * now?" (ADR 0005 §2/§6), shared by the widget page, identify and every
 * widget-token route so the rule cannot drift between them:
 *
 *  - the account's EFFECTIVE plan allows the widget
 *    (PlanPolicy::widgetAllowed(); Community = unrestricted, Cloud = Lite+);
 *  - the board has a widget row with at least one allowed origin.
 *
 * Anything else ⇒ null ⇒ the caller answers 404 (fail-secure). A plan
 * downgrade only flips the first condition: the stored settings stay.
 * The board itself must already have been resolved through an
 * account-scoped BoardRepository chokepoint by the caller.
 */
final readonly class WidgetGate
{
    public function __construct(
        private BoardWidgetRepository $widgets,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
    ) {}

    public function planAllows(int $accountId): bool
    {
        $plan    = $this->plans->forAccount($accountId, null);

        return $this->planPolicy->widgetAllowed($plan);
    }

    /**
     * @return array{board_id: int, allowed_origins: non-empty-list<string>, secret_encrypted: string|null, secret_rotated_at: string|null, last_seen_origin: string|null, last_seen_at: string|null}|null
     */
    public function activeConfig(int $accountId, int $boardId): ?array
    {
        $config = $this->widgets->findForBoard($accountId, $boardId);
        if ($config === null || $config['allowed_origins'] === [] || !$this->planAllows($accountId)) {
            return null;
        }

        return $config;
    }
}
