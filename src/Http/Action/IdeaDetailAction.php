<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\Premoderation;
use Votepit\Domain\TranslationGate;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\BoardBrandingView;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IdeaStatusDecisionRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Security\IdeaViewTracker;
use Votepit\Support\Clock;

/**
 * GET /{board}/ideas/{id} — idea detail view.
 *
 * AuthZ: anon (reading is public). Unknown slug or idea → 404.
 * Cross-board leak prevented by board-scoped findInBoard().
 *
 * Also returns the flat comment list of the idea (`comments`) —
 * CommentRepository::listByIdea() is idea-scoped, the idea itself has
 * already been loaded board-scoped at this point. Each comment is
 * additionally annotated with its `reactions` summary (per-emoji counts +
 * the viewer's own reaction, or null when anon) via
 * CommentReactionRepository::summaryForComments() — one batched query,
 * not N+1 per comment. `idea.tags` likewise (TagRepository::tagsForIdea(),
 * idea-scoped).
 *
 * findPublicBySlugForAccount() additionally gates on board visibility —
 * a 'private' board is structurally unfindable for a non-member → 404.
 *
 * Also carries the account's `legal_operator_name`/`legal_operator_address`
 * (board-operator-compliance issue 03) on the `board` block, same as
 * BoardHomeAction — a voter can land here directly (shared idea link)
 * without ever seeing the board home page, so the operator disclosure needs
 * to repeat here too.
 *
 * Pre-moderation (issue 05): a pending idea is loaded via the explicit
 * findInBoard(..., includePending: true) opt-in and then shown ONLY to its
 * author and the account's owner/admin/moderators (with
 * `idea.moderation_state = "pending"` for the "waiting for approval"
 * banner). Everyone else gets the exact 404 body of a non-existent id —
 * never a 403, so pending ids cannot be enumerated. A pending view is not
 * counted. Published ideas: payload unchanged (no moderation_state key).
 * The role lookup is the one viewerIsMember() already did — no extra query.
 *
 * Reasoned decisions (P-01, migration 0091): `idea.decision` is the latest
 * status change (null when none was ever recorded) and `idea.decision_history`
 * the newest-first history (public columns only, no acting user). Both are
 * board-scoped via the same board_id + idea_id pair as the idea itself.
 */
final readonly class IdeaDetailAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private CommentReactionRepository $reactionRepo,
        private AccountMemberRepository $accountMembers,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private IdeaViewTracker $viewTracker,
        private TagRepository $tagRepo,
        private BoardStatusRepository $statusRepo,
        private Clock $clock,
        private IdeaStatusDecisionRepository $statusDecisions,
        private ?PlanPolicy $planPolicy = null,
        private ?BrandResolver $brands = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $viewerRole     = $this->viewerRole($request, $accountId);
        $viewerIsMember = $viewerRole !== null;
        $board          = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $ideaId      = (int) ($args['id'] ?? 0);
        $currentUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        // my_vote via a set-based subquery when logged in.
        $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

        // A merged duplicate (issue 10) still resolves, so old links never
        // 404: it carries `merged_into` (the canonical idea, if visible) and
        // the SPA shows it read-only with a link there.
        $idea = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId, $currentUserId, includePending: true, includeMerged: true);
        $isPending = is_array($idea) && ($idea['moderation_state'] ?? null) !== Premoderation::STATE_PUBLISHED;
        if (is_array($idea) && $isPending
            && !Premoderation::canModerate($viewerRole)
            && ($currentUserId === null || (int) $idea['author_id'] !== $currentUserId)) {
            $idea = null;
        }
        if (is_array($idea) && !$isPending) {
            unset($idea['moderation_state']);
        }
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $mergedIntoId         = is_int($idea['merged_into_id'] ?? null) ? $idea['merged_into_id'] : null;
        $idea['merged_into']  = null;
        $idea['merged_ideas'] = [];
        if ($mergedIntoId !== null) {
            $target              = $this->ideaRepo->findInBoard((int) $board['id'], $mergedIntoId);
            $idea['merged_into'] = is_array($target) ? ['id' => $mergedIntoId, 'title' => (string) $target['title']] : null;
        } elseif (!$isPending) {
            // Recorded after the idea is confirmed to exist, before the
            // response is built — the response reflects the count as of this
            // read, i.e. one view behind on a visitor's own first request
            // (see class doc). Not for a pending idea or a merged duplicate.
            $this->viewTracker->recordView($request, $ideaId);

            // Moderators see which duplicates were merged here (undo entry
            // point); one extra query only for them.
            if (Premoderation::canModerate($viewerRole)) {
                $idea['merged_ideas'] = $this->ideaRepo->listMergedInto((int) $board['id'], $ideaId);
            }
        }

        $comments   = $this->commentRepo->listByIdea($ideaId);
        $idea['tags'] = $this->tagRepo->tagsForIdea($ideaId);

        // Status registry (migration 0073): the server owns the transition
        // graph — the SPA offers exactly `allowed_next`, never mirrors it.
        $statuses             = $this->statusRepo->registryFor($board);
        $idea['allowed_next'] = $statuses->allowedNext((string) $idea['status_key']);

        // decision_count comes with the idea row (findInBoard), so the history
        // query only runs for ideas that were ever decided — no extra query otherwise.
        $decisionCount        = (int) ($idea['decision_count'] ?? 0);
        unset($idea['decision_count']);
        $decisionHistory      = $decisionCount > 0 ? $this->statusDecisions->listForIdea((int) $board['id'], $ideaId) : [];
        $idea['decision']         = $decisionHistory[0] ?? null;
        $idea['decision_history'] = $decisionHistory;

        $commentIds = array_map(static fn (array $c): int => (int) $c['id'], $comments);
        $reactionSummary = $this->reactionRepo->summaryForComments($commentIds, $currentUserId);
        $comments = array_map(
            static function (array $c) use ($reactionSummary): array {
                $c['reactions'] = $reactionSummary[(int) $c['id']] ?? ['my_reaction' => null, 'counts' => []];

                return $c;
            },
            $comments,
        );

        // Legal board-operator identity (board-operator-compliance issue 03) —
        // not plan-gated, see BoardHomeAction's class doc for the full
        // rationale (same account, same tolerate-empty-for-legacy-boards
        // read path).
        $account            = $this->accountRepo->findById($accountId);
        $rawOperatorName    = is_array($account) && is_string($account['legal_operator_name'] ?? null)
            ? $account['legal_operator_name'] : '';
        $rawOperatorAddress = is_array($account) && is_string($account['legal_operator_address'] ?? null)
            ? $account['legal_operator_address'] : '';
        $rawOperatorEmail   = is_array($account) && is_string($account['legal_operator_email'] ?? null)
            ? $account['legal_operator_email'] : '';
        $rawOperatorNotice  = is_array($account) && is_string($account['legal_operator_notice'] ?? null)
            ? $account['legal_operator_notice'] : '';

        // Pro branding (migration 0093): the owner's wording and header links apply
        // on the idea page too, only while the account's plan includes them.
        // Colours, logo, badge and footer (P-36/P-28/P-31) follow the same plan as the board home.
        $branding = null;
        $visual   = null;
        // Without a plan policy nothing can grant the translation feature (fail-secure).
        $translationEnabled = false;
        if ($this->planPolicy instanceof \Votepit\Domain\PlanPolicy) {
            $plan     = $this->plans->forAccount($accountId, $currentUser, is_array($account) ? $account : null);
            $translationEnabled = (bool) ($board['translation_enabled'] ?? false)
                && TranslationGate::planAllows($this->planPolicy, $plan);
            $branding = BoardProBrandingView::applied($board, $this->planPolicy, $plan);
            if ($this->brands instanceof \Votepit\Domain\BrandResolver) {
                $visual = BoardBrandingView::visual($board, $plan, $this->planPolicy, $this->brands);
            }
        }

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'                  => (int) $board['id'],
                'slug'                => $slug,
                'name'                => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                'translation_enabled' => $translationEnabled,
                'anonymous_voting_enabled' => (bool) ($board['anonymous_voting_enabled'] ?? false),
                'vote_reasons_enabled' => (bool) ($board['vote_reasons_enabled'] ?? false),
                // Status changes need a reason on this board (migration 0115) — the SPA's decision form.
                'status_reason_required' => (bool) ($board['status_reason_required'] ?? false),
                'statuses'                 => $statuses->toArray(),
                // Optional deadlines (migration 0082), server-computed.
                'phases'                   => BoardPhases::fromBoard($board, $this->clock->now())->toArray(),
                'legal_operator_name'    => $rawOperatorName !== '' ? $rawOperatorName : null,
                'legal_operator_address' => $rawOperatorAddress !== '' ? $rawOperatorAddress : null,
                'legal_operator_email'   => $rawOperatorEmail !== '' ? $rawOperatorEmail : null,
                'legal_operator_notice'  => $rawOperatorNotice !== '' ? $rawOperatorNotice : null,
                'branding_pro'           => $branding,
                ...($visual ?? []),
            ],
            'idea'             => $idea,
            'comments'         => $comments,
            'is_authenticated' => $currentUser !== null,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function viewerRole(ServerRequestInterface $request, int $accountId): ?string
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $userId > 0 ? $this->accountMembers->roleFor($accountId, $userId) : null;
    }
}
