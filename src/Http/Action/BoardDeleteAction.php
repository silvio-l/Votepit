<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;

/**
 * POST /admin/boards/{slug}/delete — owner-initiated, permanent board
 * deletion (AuthZ: accountOwner — same tier as active-set/invite mutations,
 * stricter than accountAdmin's create/branding/moderation routes: this is
 * the one board-scoped action a moderator must not be able to take alone).
 *
 * Hard-deletes immediately, no grace period (unlike AccountDeleteAction's
 * 48h GDPR Art. 17 window for the whole tenant relationship — a single
 * board is an ordinary data-management action within an ongoing account,
 * not the end of it) — reuses BoardRepository::deleteBoard(), the same
 * method OperatorBoardAction::delete() already calls, ON DELETE CASCADE
 * takes care of ideas/votes/comments/tokens/etc.
 *
 * The typed-slug confirmation the SPA collects is a UX affordance ONLY
 * (GitHub-style "type to confirm") — re-validated here server-side against
 * the board's ACTUAL slug, never trusted from the client alone, mirroring
 * AccountDeleteAction's confirm_slug check.
 *
 * Deliberately no "last board" / is_default guard: an account with zero
 * boards is a valid, unremarkable state (BoardsAdminPage already renders
 * an empty "create your first board" state for a brand-new account), so
 * there is nothing to protect here.
 *
 * Billing note: board-plan limits (PlanPolicy::boardLimit(), enforced in
 * BoardCreateAction) are checked against BoardRepository::countForAccount()
 * — a straight `COUNT(*) FROM boards`, not filtered by frozen_at. Deleting
 * a board removes the row and therefore frees a slot; merely freezing one
 * (active-set) does not — billing is by boards CREATED, never by how many
 * currently happen to be active.
 */
final readonly class BoardDeleteAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]);
        }

        $parsed      = $request->getParsedBody();
        $confirmSlug = is_array($parsed) ? trim((string) ($parsed['confirm_slug'] ?? '')) : '';

        // Re-validated server-side against the REAL slug — the client-typed
        // text is UX only (see class doc).
        if ($confirmSlug === '' || !hash_equals((string) $board['slug'], $confirmSlug)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'confirmation_mismatch',
                    'message' => 'To confirm, please enter the exact slug of this board.',
                ],
            ]);
        }

        $boardId = (int) $board['id'];
        $this->boardRepo->deleteBoard($boardId);

        $this->audit->log('board.deleted', ['board_id' => $boardId, 'account_id' => $accountId]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
