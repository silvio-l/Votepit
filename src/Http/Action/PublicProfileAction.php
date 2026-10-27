<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\PlatformRoles;
use Votepit\Domain\StatusService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\BoardBrandingView;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\UserSocialLinkRepository;
use Votepit\Persistence\VoteRepository;

/**
 * GET {account}/members/{userId}/profile — reads another user's public
 * profile (profile-visibility feature). AuthZ: anon — mirrors how idea/
 * comment reads are already public (AuthZMiddleware::anon()); a profile
 * attached to a publicly readable idea/comment carries the same trust level.
 *
 * Account-scoped (unlike AccountProfileAction, which is user-scoped) purely
 * to resolve the target's role (owner|admin|moderator|null) within THIS account
 * via AccountMemberRepository — a user's global profile fields themselves
 * (avatar, social links, visibility) are the same across every account they
 * belong to (ADR 0001 §2c), the role badge is not.
 *
 * profile_visible = false (the default, migration 0021) is fail-secure here:
 * only `id`, `is_admin`/`is_operator` role-badge material, and a `visible:
 * false` flag are ever returned — never avatar_url, any social link, or the
 * contribution stats below, no matter what the caller requests.
 *
 * Contribution stats (social-features issue 06): `ideas_submitted`,
 * `ideas_shipped` (status StatusService::STATUS_DONE), `votes_cast` are pure
 * live COUNT reads via IdeaRepository/VoteRepository — no new table, no
 * written cache — and are strictly scoped to $accountId (the account from
 * THIS request's context, see AccountContextMiddleware), never aggregated
 * platform-wide across accounts.
 */
final readonly class PublicProfileAction
{
    public function __construct(
        private UserRepository $users,
        private UserSocialLinkRepository $socialLinks,
        private AccountMemberRepository $accountMembers,
        private IdeaRepository $ideas,
        private VoteRepository $votes,
        private ?BoardRepository $boards = null,
        private ?AccountPlanResolver $plans = null,
        private ?PlanPolicy $planPolicy = null,
        private ?BrandResolver $brands = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = is_string($args['userId'] ?? null) ? (int) $args['userId'] : 0;
        $user   = $userId > 0 ? $this->users->findPublicProfileById($userId) : null;

        if (!is_array($user)) {
            $response->getBody()->write((string) json_encode(['error' => ['key' => 'not_found', 'message' => 'User not found.']]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $role      = $this->accountMembers->roleFor($accountId, $userId);

        // The shared "deleted user" placeholder (decision 2b, see
        // UserErasureRepository) — content of erased users points at it; the
        // SPA renders it as "Deleted user" instead of the generic "Voter".
        $deleted = (bool) ($user['is_deleted_placeholder'] ?? false);
        // The guest-author placeholder (ADR 0006 §1): never a visible
        // profile, never stats (they would aggregate unrelated guests).
        $guest   = (bool) ($user['is_guest_placeholder'] ?? false);
        $visible = !$deleted && !$guest && (bool) ($user['profile_visible'] ?? false);
        $payload = [
            'id'          => $userId,
            'visible'     => $visible,
            'deleted'     => $deleted,
            'is_admin'    => (bool) ($user['is_admin'] ?? false),
            'is_operator' => PlatformRoles::isOperator($user),
            'role'        => $role,
        ];
        if ($guest) {
            // Additive, guest only — every other profile payload is unchanged.
            $payload['guest'] = true;
        }

        if ($visible) {
            $avatarFilename = is_string($user['avatar_filename'] ?? null) ? $user['avatar_filename'] : null;
            $links          = $this->socialLinks->getForUser($userId);

            $payload['avatar_url']      = $avatarFilename !== null ? '/avatar/' . $avatarFilename : null;
            $payload['username']        = is_string($user['username'] ?? null) ? $user['username'] : null;
            $payload['website_domain']  = $links['website_domain'];
            $payload['x_handle']        = $links['x_handle'];
            $payload['youtube_handle']  = $links['youtube_handle'];
            $payload['github_username'] = $links['github_username'];

            // Contribution stats (social-features issue 06): pure live
            // COUNT reads, strictly scoped to $accountId (the account from
            // the request context) — never aggregated platform-wide.
            $payload['ideas_submitted'] = $this->ideas->countByAuthorForAccount($accountId, $userId);
            $payload['ideas_shipped']   = $this->ideas->countByAuthorForAccount($accountId, $userId, StatusService::STATUS_DONE);
            $payload['votes_cast']      = $this->votes->countByUserForAccount($accountId, $userId);
        }

        // Board context (optional `?board=`): the profile is reached from a board, so it
        // takes that board's look. Same visibility gate and account scope as the board
        // pages; an unknown or hidden board adds nothing, the profile renders as before.
        $board = $this->boardContext($request, $accountId);
        if ($board !== null) {
            $payload['board'] = $board;
        }

        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function boardContext(ServerRequestInterface $request, int $accountId): ?array
    {
        $slug = $request->getQueryParams()['board'] ?? null;
        if (!is_string($slug) || $slug === '' || !$this->boards instanceof \Votepit\Persistence\BoardRepository || !$this->plans instanceof \Votepit\Domain\AccountPlanResolver || !$this->planPolicy instanceof \Votepit\Domain\PlanPolicy || !$this->brands instanceof \Votepit\Domain\BrandResolver) {
            return null;
        }

        $currentUser    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $viewerId       = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : 0;
        $viewerIsMember = $viewerId > 0 && $this->accountMembers->roleFor($accountId, $viewerId) !== null;
        $board          = $this->boards->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            return null;
        }

        $plan    = $this->plans->forAccount($accountId, $currentUser);

        return [
            // The row's own slug, never the request value.
            'slug'         => is_string($board['slug'] ?? null) ? $board['slug'] : $slug,
            'name'         => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            'branding_pro' => BoardProBrandingView::applied($board, $this->planPolicy, $plan),
            ...BoardBrandingView::visual($board, $plan, $this->planPolicy, $this->brands),
        ];
    }
}
