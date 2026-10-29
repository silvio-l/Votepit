<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\ResolvedPlan;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\BoardLinkValidator;

/**
 * PUT /admin/boards/{slug}/link-footer — back link to the customer's site and
 * the plaintext footer note (P-31, Lite part). AuthZ: accountAdmin, CSRF
 * globally enforced.
 *
 * Gate `back_link_footer` (paid plans). Setting a value on a plan without
 * the gate is REJECTED with a 422 field error, never silently dropped (same
 * fail-loud rule as BoardBrandingAction). Clearing is always allowed. After a
 * downgrade the stored values stay (kept, not applied): the public board
 * payload re-checks the current plan.
 *
 * Every value is validated by BoardLinkValidator before it is stored.
 */
final readonly class BoardLinkFooterAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed   = $request->getParsedBody();
        $rawUrl   = is_array($parsed) ? trim((string) ($parsed['back_link_url'] ?? '')) : '';
        $rawLabel = is_array($parsed) ? trim((string) ($parsed['back_link_label'] ?? '')) : '';
        $rawNote  = is_array($parsed) ? trim((string) ($parsed['footer_note'] ?? '')) : '';

        $fieldErrors = [];
        $url         = null;
        $label       = null;
        $note        = null;

        if ($rawUrl !== '') {
            $url = BoardLinkValidator::backLinkUrl($rawUrl);
            if ($url === null) {
                $fieldErrors['back_link_url'] = 'Enter a full https:// address without a port or user name.';
            }
            $label = BoardLinkValidator::backLinkLabel($rawLabel);
            if ($label === null) {
                $fieldErrors['back_link_label'] = 'Enter a link text of 1 to 40 characters, without < or >.';
            }
        } elseif ($rawLabel !== '') {
            $fieldErrors['back_link_url'] = 'Enter the address the link text should point to.';
        }

        if ($rawNote !== '') {
            $note = BoardLinkValidator::footerNote($rawNote);
            if ($note === null) {
                $fieldErrors['footer_note'] = 'Enter a note of up to 280 characters, without < or >.';
            }
        }

        $wantsAny = $rawUrl !== '' || $rawLabel !== '' || $rawNote !== '';
        $plan     = $this->planFor($accountId, $request);
        if ($wantsAny && !$this->planPolicy->isBrandingFieldAllowed($plan, 'back_link_footer')) {
            $fieldErrors['back_link_url'] = 'A back link and footer note are not available on your current plan. Please upgrade.';
        }

        if ($fieldErrors !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fieldErrors],
            ]);
        }

        $this->boardRepo->updateLinkFooter((int) $board['id'], $accountId, $url, $label, $note);
        $this->audit->log('board.link_footer_updated', ['board_id' => (int) $board['id']]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function planFor(int $accountId, ServerRequestInterface $request): ResolvedPlan
    {
        return $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
