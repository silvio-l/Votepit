<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\BrandingValidator;

/**
 * GET /{board} — board home = idea list (newest, status filter, pagination).
 *
 * AuthZ: anon (reading is public). Unknown slug → 404. Uses
 * findPublicBySlugForAccount() — a board of a still-unconfirmed account
 * (confirm-before-public gate) is likewise unfindable here → 404.
 *
 * The same chokepoint method also checks board visibility — a 'private'
 * board is structurally unfindable for a non-member → 404. viewerIsMember
 * is determined HERE (before the board fetch, so the chokepoint call can
 * use it directly) via AccountMemberRepository::roleFor().
 *
 * This is the ONE public-facing read path for
 * `intro` and the "Powered by Votepit" badge — both are re-checked against
 * the account's CURRENT plan ($this->planPolicy->isBrandingFieldAllowed()) before
 * being exposed here, in addition to whatever BoardBrandingAction already
 * validated at write time. This is the downgrade safeguard: a stale
 * over-plan `intro`/`hide_badge` value sitting in the DB (e.g. after a
 * Pro→Free downgrade) is never force-cleared, but is also never publicly
 * active while the account is on a plan that no longer allows it —
 * `intro` renders empty and `show_badge` stays true regardless of the
 * stored `hide_badge` flag.
 */
final readonly class BoardHomeAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AccountMemberRepository $accountMembers,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $viewerIsMember = $this->viewerIsMember($request, $accountId);
        $board          = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $params = $request->getQueryParams();

        // Status filter: allow-list validation; invalid → null (show all).
        $rawStatus    = is_string($params['status'] ?? null) ? $params['status'] : null;
        $activeStatus = ($rawStatus !== null && in_array($rawStatus, IdeaRepository::ALLOWED_STATUSES, true))
            ? $rawStatus
            : null;

        // Pagination: ?page= (1-based, conservative page size).
        $rawPage = isset($params['page']) ? (int) $params['page'] : 1;
        $page    = max(1, $rawPage);
        $limit   = IdeaRepository::DEFAULT_PAGE_SIZE;
        $offset  = ($page - 1) * $limit;

        // Sort axis: validate ?sort= against the allow-list; unknown → DEFAULT_SORT.
        $rawSort    = is_string($params['sort'] ?? null) ? $params['sort'] : IdeaRepository::DEFAULT_SORT;
        $activeSort = array_key_exists($rawSort, IdeaRepository::SORT_AXES) ? $rawSort : IdeaRepository::DEFAULT_SORT;

        // Logged-in user → my_vote per idea via a set-based subquery.
        $currentUser   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $isAuth        = $currentUser !== null;
        $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

        $ideas = $this->ideaRepo->listByBoard((int) $board['id'], $activeStatus, $limit, $offset, $activeSort, $currentUserId);

        // Total count for pagination (only when needed: page > 1 or a full page).
        $totalPages = 1;
        if (count($ideas) === $limit || $page > 1) {
            $total      = $this->ideaRepo->countByBoard((int) $board['id'], $activeStatus);
            $totalPages = max(1, (int) ceil($total / $limit));
        }

        // "This week" aggregates for the FeaturedIdeaCard (board-scoped).
        $stats = $this->ideaRepo->boardStats((int) $board['id']);

        // Re-check `intro`/badge-hide against the
        // account's CURRENT plan — see class doc for the downgrade rationale.
        $account     = $this->accountRepo->findById($accountId);
        $plan        = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $rawIntro    = is_string($board['intro'] ?? null) ? $board['intro'] : '';
        $introText   = $rawIntro !== '' ? BrandingValidator::introText($rawIntro) : null;
        $introAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'intro');
        $badgeHideAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'hide_badge');
        $badgeHideRequested = (bool) ($board['hide_badge'] ?? false);

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'          => (int) $board['id'],
                'slug'        => $slug,
                'name'        => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                'intro'       => $introAllowed && $introText !== null ? $introText : '',
                'show_badge'  => !($badgeHideAllowed && $badgeHideRequested),
            ],
            'ideas'            => $ideas,
            'stats'            => $stats,
            'active_status'    => $activeStatus,
            'active_sort'      => $activeSort,
            'page'             => $page,
            'total_pages'      => $totalPages,
            'is_authenticated' => $isAuth,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function viewerIsMember(ServerRequestInterface $request, int $accountId): bool
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $userId > 0 && $this->accountMembers->roleFor($accountId, $userId) !== null;
    }
}
