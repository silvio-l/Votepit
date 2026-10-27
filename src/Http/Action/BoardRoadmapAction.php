<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\AccountRoles;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\BoardBrandingView;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * GET /{board}/roadmap — board-scoped roadmap.
 *
 * Trust level: anon (public roadmap, no login required). Returns only
 * aggregates (score, consensus, comment count) — no voter PII. Ideas are
 * returned grouped by status (planned / in_progress / done); open and
 * declined don't appear. Uses idx_ideas_board_status.
 *
 * findPublicBySlugForAccount() additionally gates on board visibility —
 * a 'private' board is structurally unfindable for a non-member → 404.
 *
 * `roadmap_gantt_allowed` (PlanPolicy::roadmapGanttAllowed(), resolved
 * against the BOARD'S account plan via AccountPlanResolver::forAccount() — never the
 * viewer's own plan) tells the SPA whether to offer the Gantt view; each
 * idea additionally carries `start_date`/`target_date` (null unless a board
 * admin set them) so the Gantt can draw bars once that's true.
 */
final readonly class BoardRoadmapAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AccountMemberRepository $accountMembers,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private BoardStatusRepository $statusRepo,
        private BrandResolver $brands,
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

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $plan = $this->plans->forAccount($accountId, $user);

        // Pro branding (migration 0093): a board owner may hide the roadmap. The
        // public route then answers 404 like an unknown board, but owners and
        // admins of the account keep access (never locked out).
        $branding = BoardProBrandingView::applied($board, $this->planPolicy, $plan);
        if ($branding['roadmap_hidden'] && !$this->viewerManagesAccount($request, $accountId)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $grouped = $this->ideaRepo->roadmapByBoard((int) $board['id']);

        // The same branding payload as the board home, idea, submit and profile pages
        // (accent, logo for the topbar, cover, footer extras), so the roadmap looks like the board.
        $visual   = BoardBrandingView::visual($board, $plan, $this->planPolicy, $this->brands);
        $assets = BoardBrandingView::assetPayload($board, $plan, $this->planPolicy, $this->boardAssets);

        $response->getBody()->write((string) json_encode([
            'board' => [
                'id'                 => (int) $board['id'],
                'slug'               => $slug,
                'name'               => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                'translation_enabled' => (bool) ($board['translation_enabled'] ?? false),
                // Status registry (migration 0073): labels + which roadmap
                // columns still have a visible status or ideas.
                'statuses'            => $this->statusRepo->registryFor($board)->toArray(),
                'branding_pro'        => $branding,
                ...$visual,
                'assets'              => $assets['ids'],
                'asset_ink'           => $assets['ink'],
            ],
            'groups'                => $grouped,
            'roadmap_gantt_allowed' => $this->planPolicy->roadmapGanttAllowed($plan),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function viewerManagesAccount(ServerRequestInterface $request, int $accountId): bool
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $role   = $userId > 0 ? $this->accountMembers->roleFor($accountId, $userId) : null;

        return AccountRoles::canAdminister($role);
    }

    private function viewerIsMember(ServerRequestInterface $request, int $accountId): bool
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $userId > 0 && $this->accountMembers->roleFor($accountId, $userId) !== null;
    }
}
