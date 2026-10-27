<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\IdeaSearchQuery;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\BoardBrandingView;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Security\BrandingValidator;
use Votepit\Support\Clock;

/**
 * GET /{board} — board home = idea list (sort, status filter, ?q= title
 * search, pagination).
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
 * This is the ONE public-facing read path for every branding field —
 * `intro`, `secondary_color`, `logo_url` and the "Powered by Votepit" badge
 * are each re-checked against the account's CURRENT effective plan
 * ($this->planPolicy->isBrandingFieldAllowed(), via AccountPlanResolver::forAccount())
 * before being exposed here, in addition to whatever BoardBrandingAction
 * already validated at write time. This is the downgrade safeguard: a stale
 * over-plan value sitting in the DB (e.g. after a Pro→Free downgrade) is
 * never force-cleared, but is also never publicly active while the account
 * is on a plan that no longer allows it — the field renders as unset (or
 * `show_badge` stays true) regardless of the stored value. `primary_color`
 * is ungated on every plan (mirrors BoardBrandingAction), so it is always
 * exposed as-is. Since this is an anonymous-visitor read path, the effective
 * plan is resolved via EffectivePlan's account-level bypass (is the platform
 * operator a MEMBER of this account?), not the request's own-user bypass —
 * there usually is no logged-in user at all here.
 *
 * `legal_operator_name`/`legal_operator_address`/`legal_operator_email`/
 * `legal_operator_notice` (board-operator-compliance issue 03, extended by
 * migrations/0064 for international legal-notice completeness) are NOT
 * plan-gated — they are a legal-compliance disclosure, not
 * a branding upsell, and render for every plan tier whenever set. Unlike
 * `show_badge`, they are also not tied to the Cloud/Community edition badge
 * (`core/app/src/lib/edition.ts`) — that answers "which software edition is
 * this," this answers "who legally operates this board," a different
 * question. Read straight from AccountRepository::findById() with no
 * additional validation: issue 02's BoardCreateAction/BoardBrandingAction
 * gate already prevents a public board from existing with an empty
 * `legal_operator_name`, but pre-gate legacy boards (self-host installs
 * upgraded from an older migration, or boards made public before issue 02
 * shipped) may still have it empty — this read path tolerates that and
 * simply omits the field (`null`) rather than erroring, since it is a pure
 * read path with no write-time enforcement of its own.
 */
final readonly class BoardHomeAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AccountMemberRepository $accountMembers,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private TagRepository $tagRepo,
        private BoardStatusRepository $statusRepo,
        private Clock $clock,
        private BrandResolver $brands,
        private ?AnonymousSubmissionGate $anonymousSubmissionGate = null,
        private ?BoardAssetRepository $boardAssets = null,
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

        // Exact-status filter (broaden-use-cases issue 03): ?status_key= picks
        // one status of the board; it wins over the category filter above.
        // Unknown key → ignored (show all), same as an invalid ?status=.
        $statuses        = $this->statusRepo->registryFor($board);
        $rawStatusKey    = is_string($params['status_key'] ?? null) ? $params['status_key'] : null;
        $activeStatusKey = $rawStatusKey !== null && $statuses->find($rawStatusKey) instanceof \Votepit\Domain\BoardStatus ? $rawStatusKey : null;
        if ($activeStatusKey !== null) {
            $activeStatus = null;
        }

        // Pagination: ?page= (1-based, conservative page size).
        $rawPage = isset($params['page']) ? (int) $params['page'] : 1;
        $page    = max(1, $rawPage);
        $limit   = IdeaRepository::DEFAULT_PAGE_SIZE;
        $offset  = ($page - 1) * $limit;

        // Sort axis: validate ?sort= against the allow-list; unknown → DEFAULT_SORT.
        $rawSort    = is_string($params['sort'] ?? null) ? $params['sort'] : IdeaRepository::DEFAULT_SORT;
        $activeSort = array_key_exists($rawSort, IdeaRepository::SORT_AXES) ? $rawSort : IdeaRepository::DEFAULT_SORT;

        // Free-text title search (?q=, competitor-parity H6). Non-string or
        // term-less input (only punctuation/wildcards) → null = no search.
        // Combines with status, sort and pagination; scoping is unchanged —
        // the query only ever narrows this (account-resolved) board's list.
        $search = IdeaSearchQuery::fromRaw(is_string($params['q'] ?? null) ? $params['q'] : null);

        // Logged-in user → my_vote per idea via a set-based subquery.
        $currentUser   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $isAuth        = $currentUser !== null;
        $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

        // Tag filter (G-09): ?tag_id= must be a numeric id; the repository only matches tags of this board.
        $rawTagId = is_string($params['tag_id'] ?? null) && ctype_digit($params['tag_id']) ? (int) $params['tag_id'] : null;

        // Active list (owner change): ?exclude_closed=1 drops done/declined/archived; a status filter wins over it.
        $excludeClosed = ($params['exclude_closed'] ?? null) === '1' && $activeStatus === null && $activeStatusKey === null;

        $ideas = $this->ideaRepo->listByBoard((int) $board['id'], $activeStatus, $limit, $offset, $activeSort, $currentUserId, $search, $activeStatusKey, $rawTagId, $excludeClosed);

        // Batch-attach tags (one query for the whole page, no N+1).
        $ideaIds     = array_map(static fn (array $idea): int => (int) $idea['id'], $ideas);
        $tagsByIdea  = $this->tagRepo->tagsForIdeas($ideaIds);
        foreach ($ideas as &$idea) {
            $idea['tags'] = $tagsByIdea[(int) $idea['id']] ?? [];
        }
        unset($idea);

        // Total of ALL matching ideas (G-09: shown as the result count and per group). countListed()
        // mirrors listByBoard()'s WHERE (status key/category, archived, search, tag).
        // A page that is not full and is the first one already holds every match: no extra count query.
        $total = (count($ideas) < $limit && $page === 1)
            ? count($ideas)
            : $this->ideaRepo->countListed((int) $board['id'], $activeStatus, $search, $activeStatusKey, $rawTagId, $excludeClosed);
        $totalPages = max(1, (int) ceil($total / $limit));

        // Board tags with the number of ideas each one lists in the default scope (G-09 rail/menu).
        $tags = $this->ideaRepo->tagsWithCountsByBoard((int) $board['id']);

        // "This week" aggregates for the board's featured idea (board-scoped).
        $stats = $this->ideaRepo->boardStats((int) $board['id']);

        // Re-check every staged branding field against the account's CURRENT
        // effective plan — see class doc for the downgrade rationale and the
        // operator-owned-account bypass.
        $account     = $this->accountRepo->findById($accountId);
        $plan        = $this->plans->forAccount($accountId, $currentUser, is_array($account) ? $account : null);

        $rawIntro     = is_string($board['intro'] ?? null) ? $board['intro'] : '';
        $introText    = $rawIntro !== '' ? BrandingValidator::introText($rawIntro) : null;
        $introAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'intro');

        // P-32 / P-28 / P-31: colours, logo, badge, back link and footer note are
        // one shared view (BoardBrandingView) used by every board-scoped page.
        // Format-only validation on read: contrast is a WRITE-time gate, so an
        // already-saved value is never silently nulled on read.
        $visual = BoardBrandingView::visual($board, $plan, $this->planPolicy, $this->brands);

        // P-28 / P-31 (Lite): imagery, back link and footer note. Re-checked against the
        // CURRENT plan: after a downgrade the stored values are kept but not applied, a
        // re-upgrade restores them. Public ids only; the image bytes come from the
        // gated public endpoint (BoardAssetAction::serve).
        $assets = BoardBrandingView::assetPayload($board, $plan, $this->planPolicy, $this->boardAssets);

        // Legal board-operator identity (issue 03) — not plan-gated, see class doc.
        $rawOperatorName    = is_string($account['legal_operator_name'] ?? null) ? $account['legal_operator_name'] : '';
        $operatorName       = $rawOperatorName !== '' ? $rawOperatorName : null;
        $rawOperatorAddress = is_string($account['legal_operator_address'] ?? null) ? $account['legal_operator_address'] : '';
        $operatorAddress    = $rawOperatorAddress !== '' ? $rawOperatorAddress : null;
        $rawOperatorEmail   = is_string($account['legal_operator_email'] ?? null) ? $account['legal_operator_email'] : '';
        $operatorEmail      = $rawOperatorEmail !== '' ? $rawOperatorEmail : null;
        $rawOperatorNotice  = is_string($account['legal_operator_notice'] ?? null) ? $account['legal_operator_notice'] : '';
        $operatorNotice     = $rawOperatorNotice !== '' ? $rawOperatorNotice : null;

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'              => (int) $board['id'],
                'slug'            => $slug,
                'name'            => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                'intro'           => $introAllowed && $introText !== null ? $introText : '',
                'show_badge'      => $visual['show_badge'],
                'primary_color'   => $visual['primary_color'],
                'secondary_color' => $visual['secondary_color'],
                // Dogfood v0.1.5: highlight colour, dark-mode values and logo plate (Lite+, applied only when the plan includes them).
                'accent_color'        => $visual['accent_color'],
                'primary_color_dark'  => $visual['primary_color_dark'],
                'accent_color_dark'   => $visual['accent_color_dark'],
                'logo_plate'          => $visual['logo_plate'],
                'logo_ring_hidden'    => $visual['logo_ring_hidden'],
                'logo_url'        => $visual['logo_url'],
                // Uploaded logo public id (also in assets.logo); the SPA prefers it over logo_url.
                'logo_public_id'  => $visual['logo_public_id'],
                'logo_allowed'    => $visual['logo_allowed'],
                'wordmark_public_id' => $visual['wordmark_public_id'],
                'wordmark_ink'    => $visual['wordmark_ink'],
                'wordmark_light_public_id' => $visual['wordmark_light_public_id'],
                'wordmark_light_ink'       => $visual['wordmark_light_ink'],
                // P-28c: selected cover pattern (null = default), plan-gated.
                'cover_pattern'   => $visual['cover_pattern'],
                // P-26: display mode (system | light | dark), not plan-gated.
                'theme_mode'      => $visual['theme_mode'],
                // P-28 variant => public id (cover_wide, cover_phone, favicon, og, logo), plan-gated.
                'assets'          => $assets['ids'],
                'asset_ink'       => $assets['ink'],
                'back_link'       => $visual['back_link'],
                'footer_note'     => $visual['footer_note'],
                'translation_enabled' => (bool) ($board['translation_enabled'] ?? false),
                'anonymous_voting_enabled' => (bool) ($board['anonymous_voting_enabled'] ?? false),
                'vote_reasons_enabled' => (bool) ($board['vote_reasons_enabled'] ?? false),
                // Guest idea submission (ADR 0006 §8): flag ∧ Turnstile ∧ plan.
                'anonymous_submission_available' => ($board['visibility'] ?? 'private') !== 'private'
                    && $this->anonymousSubmissionGate?->isAvailable($board, $accountId) === true,
                // Status registry (migration 0073) — six defaults unless customised.
                'statuses'               => $statuses->toArray(),
                // Optional deadlines (migration 0082): server-computed phase
                // state, so the SPA never gates on the client clock.
                'phases'                 => BoardPhases::fromBoard($board, $this->clock->now())->toArray(),
                'legal_operator_name'    => $operatorName,
                'legal_operator_address' => $operatorAddress,
                'legal_operator_email'   => $operatorEmail,
                'legal_operator_notice'  => $operatorNotice,
                // Pro branding (migration 0093): only the values the current plan includes.
                'branding_pro'           => BoardProBrandingView::applied($board, $this->planPolicy, $plan),
            ],
            'ideas'            => $ideas,
            'total'            => $total,
            'tags'             => $tags,
            'stats'            => $stats,
            'active_status'    => $activeStatus,
            'active_status_key' => $activeStatusKey,
            'active_sort'      => $activeSort,
            'active_query'     => $search?->text(),
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
