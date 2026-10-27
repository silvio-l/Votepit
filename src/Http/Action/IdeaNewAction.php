<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\TagSuggester;
use Votepit\Extension\TagSuggestionAvailability;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\BoardBrandingView;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Security\TimeTrapService;
use Votepit\Support\Clock;

/**
 * GET /{board}/ideas/new — SPA route: returns board info + auth status +
 * time-trap stamp (AuthZ: anon). The PRG redirect to login is dropped
 * server-side; the SPA evaluates is_authenticated. form_at must be echoed
 * back by the SPA as the _form_at field in the POST.
 *
 * findPublicBySlugForAccount() additionally gates on board visibility —
 * a 'private' board is structurally unfindable for a non-member → 404.
 */
final readonly class IdeaNewAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private TimeTrapService $timeTrap,
        private AccountMemberRepository $accountMembers,
        private Clock $clock,
        private ?AnonymousSubmissionGate $anonymousSubmissionGate = null,
        private ?TagRepository $tagRepo = null,
        private ?TagSuggester $tagSuggester = null,
        private ?PlanPolicy $planPolicy = null,
        private ?AccountPlanResolver $plans = null,
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

        $viewerIsMember = $this->viewerIsMember($request, $accountId);
        $board          = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        // Guest submission (ADR 0006): the form offers the guest variant
        // (Turnstile) only when a submit could succeed.
        $gate           = $this->anonymousSubmissionGate;
        $guestAvailable = $gate instanceof AnonymousSubmissionGate
            && ($board['visibility'] ?? 'private') !== 'private'
            && $gate->isAvailable($board, $accountId);
        $boardPayload = [
            'id'   => (int) $board['id'],
            'slug' => $slug,
            'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            // Optional submission window (migration 0082) — the form
            // shows the reason instead of a doomed submit.
            'phases' => BoardPhases::fromBoard($board, $this->clock->now())->toArray(),
            'anonymous_submission_available' => $guestAvailable,
            // P-34: the board's existing tags for the optional tag picker
            // (name-ordered, board-scoped; participants never create tags).
            'tags' => $this->tagRepo?->listByBoard((int) $board['id']) ?? [],
            // P-35: whether AI tag suggestions are offered while writing. Only
            // signed-in users are ever asked; guests always get `off`.
            'tag_suggestion' => [
                'availability' => $user !== null && $this->tagSuggester instanceof TagSuggester
                    ? $this->tagSuggester->availability($accountId)->value
                    : TagSuggestionAvailability::Off->value,
            ],
        ];
        // Pro branding wording for the submit texts (migration 0093), applied only
        // while the account's current plan includes custom_wording.
        if ($this->planPolicy instanceof \Votepit\Domain\PlanPolicy && $this->plans instanceof \Votepit\Domain\AccountPlanResolver) {
            $plan    = $this->plans->forAccount($accountId, $user);
            $boardPayload['branding_pro'] = BoardProBrandingView::applied($board, $this->planPolicy, $plan);
            // Colours and logo: the submit form is themed like the board (P-36 matrix).
            if ($this->brands instanceof \Votepit\Domain\BrandResolver) {
                $boardPayload = array_merge($boardPayload, BoardBrandingView::visual($board, $plan, $this->planPolicy, $this->brands));
            }
        }
        if ($guestAvailable) {
            // Public by design (it is embedded in every Turnstile widget).
            $boardPayload['turnstile_site_key'] = $gate->turnstileSiteKey();
        }
        $response->getBody()->write((string) json_encode([
            'board'            => $boardPayload,
            'is_authenticated' => $user !== null,
            'form_at'          => $this->timeTrap->stamp(),
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
