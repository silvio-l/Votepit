<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\IssueTrackerWebhookMiddleware;
use Votepit\IssueTracker\IssueStatusSync;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\IssueTracker\IssueTrackerWebhook;
use Votepit\Logging\AuditLogger;

/**
 * POST /api/issue-tracker-webhooks/{provider}/{hook} — inbound status sync.
 * Runs only after IssueTrackerWebhookMiddleware verified the signature (and
 * Linear's replay window) against the addressed connection.
 *
 * Every authenticated delivery is acknowledged with 200 — also when it is
 * ignored (other event type, unlinked issue, plan without the feature) —
 * so the provider does not retry it. Only "issue completed/closed" moves
 * the linked idea to done (IssueStatusSync, account-scoped to the
 * connection's own account).
 */
final readonly class IssueTrackerWebhookAction
{
    public function __construct(
        private IssueTrackerProvider $provider,
        private IssueStatusSync $sync,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var array{id: int, account_id: int, target: string} $connection */
        $connection = $request->getAttribute(IssueTrackerWebhookMiddleware::ATTR_CONNECTION);
        $plan       = $this->plans->forAccount($connection['account_id'], null);
        /** @var array<array-key, mixed> $payload */
        $payload    = $request->getAttribute(IssueTrackerWebhookMiddleware::ATTR_PAYLOAD);

        $outcome    = 'ignored';
        $externalId = $this->planPolicy->issueTrackerAllowed($plan)
            ? IssueTrackerWebhook::completedIssueId($this->provider, $request->getHeaderLine('X-GitHub-Event'), $payload, $connection['target'])
            : null;
        if ($externalId !== null) {
            $outcome = $this->sync->markCompleted($connection['account_id'], $this->provider, $externalId);
            $this->audit->log('issue_tracker.webhook', [
                'account_id' => $connection['account_id'],
                'provider'   => $this->provider->value,
                'outcome'    => $outcome,
            ]);
        }

        $response->getBody()->write((string) json_encode(['ok' => true, 'outcome' => $outcome]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
