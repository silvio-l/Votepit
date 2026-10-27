<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\Premoderation;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Pre-moderation approval queue (broaden-use-cases issue 05).
 *
 * GET  /admin/boards/{slug}/queue          — pending ideas of the board + count.
 * POST /admin/boards/{slug}/queue/approve  — publish one or more pending ideas
 *                                            (`ids`: list of idea ids, max 100).
 *
 * AuthZ: accountModerate (owner/admin/moderator; anon → 401, other members
 * → 403) on both routes. Account scoping: the board is resolved via
 * findBySlugForAccount() — a board of a foreign account is a 404, and every
 * approval UPDATE binds that board's id, so an id of a foreign board/account
 * in `ids` is silently skipped (never approved, never echoed back).
 *
 * Reject is NOT a separate route: it is the existing reason-coded
 * moderation soft delete POST /{board}/ideas/{id}/moderate
 * (IdeaModerationAction), which accepts pending ideas — same reason codes,
 * author mail and appeal path as any other removal.
 *
 * Publish side effects fire here, once per idea and only when the
 * conditional UPDATE actually flipped the row (IdeaRepository::approve()),
 * so a double submit never fires twice: the `idea.created` webhook (same
 * payload as an immediately-published idea), the author's in-app
 * "idea approved" notice and the `idea.approved` audit entry. Counters
 * need no work — every count reads the published default scope.
 *
 * Guest ideas (ADR 0006): every queue item carries `author_type`
 * (`guest`|`member`) plus a `guest_count`, and
 * POST /admin/boards/{slug}/moderation/guest-reject-all (`confirm` required)
 * rejects every pending guest idea of THIS board in one transaction — the
 * same reason-coded moderation soft delete as a single reject
 * (`community_rule`), without strike or mail (the placeholder is many
 * people). Members' ideas are never touched.
 *
 * Trusted authors (broaden-use-cases issue 11):
 * POST /admin/boards/{slug}/queue/approve-and-trust (`id`: one pending idea)
 * publishes that idea exactly like approve() and marks its author as trusted
 * in this account (account_member_standing.trusted_at), so their next ideas
 * skip the queue. Refused with 422 before anything changes when the author
 * is a placeholder (`author_not_trustable`: the guest placeholder stands for
 * many people, the deleted-user placeholder for nobody) or has lost good
 * standing (`author_not_in_good_standing`: warned/suspended). Other pending
 * ideas of the same author stay in the queue. Audit: `member.trusted`
 * (reason `manual`) next to the usual `idea.approved`.
 */
final readonly class IdeaModerationQueueAction
{
    private const MAX_BULK_IDS = IdeaRepository::QUEUE_PAGE_SIZE;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AuditLogger $audit,
        private PremoderationNotifier $notifier,
        private ?WebhookDispatcher $webhookDispatcher = null,
        private ?UserRepository $userRepo = null,
        private ?ModerationRepository $moderationRepo = null,
        private ?Connection $conn = null,
        private ?AccountMemberStandingRepository $standingRepo = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $boardId = (int) $board['id'];
        $slug    = (string) $board['slug'];

        $guestId = $this->userRepo?->guestPlaceholderId();
        $ideas   = array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'title'       => (string) $row['title'],
                'body'        => (string) $row['body'],
                'created_at'  => (string) $row['created_at'],
                'author_type' => $guestId !== null && (int) $row['author_id'] === $guestId ? 'guest' : 'member',
            ],
            $this->ideaRepo->listPendingByBoard($boardId),
        );

        return $this->json($response, 200, [
            'board' => [
                'slug'          => $slug,
                'name'          => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                'premoderation' => Premoderation::normalizeMode($board['premoderation'] ?? null),
            ],
            'count'       => $this->ideaRepo->countPendingByBoard($boardId),
            'guest_count' => $guestId !== null ? $this->ideaRepo->countPendingGuestIdeas($boardId, $guestId) : 0,
            'ideas'       => $ideas,
        ]);
    }

    /** @param array<string, mixed> $args */
    public function rejectAllGuest(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed  = $request->getParsedBody();
        $confirm = is_array($parsed) ? ($parsed['confirm'] ?? null) : null;
        if (!(in_array($confirm, [true, 1, '1', 'true'], true))) {
            return JsonError::respond($response, 422, 'confirmation_required', 'Confirm rejecting all waiting guest ideas.');
        }

        $userRepo       = $this->userRepo;
        $moderationRepo = $this->moderationRepo;
        $conn           = $this->conn;
        if (!$userRepo instanceof \Votepit\Persistence\UserRepository || !$moderationRepo instanceof \Votepit\Persistence\ModerationRepository || !$conn instanceof \Doctrine\DBAL\Connection) {
            throw new \LogicException('Bulk guest reject needs the user/moderation repositories and the connection.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId   = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $boardId   = (int) $board['id'];
        $guestId   = $userRepo->guestPlaceholderId();

        $rejected = 0;
        if ($guestId !== null) {
            $rejected = $conn->transactional(function () use ($moderationRepo, $boardId, $accountId, $actorId, $guestId): int {
                $count = 0;
                foreach ($this->ideaRepo->listPendingGuestIdeas($boardId, $guestId) as $idea) {
                    $actionId = $moderationRepo->recordAction(
                        'idea',
                        $idea['id'],
                        $boardId,
                        $accountId,
                        $actorId,
                        'board_moderator',
                        'community_rule',
                        null,
                        $idea['title'] . "\n\n" . $idea['body'],
                    );
                    if ($this->ideaRepo->softDelete($boardId, $idea['id'], $actionId)) {
                        $count++;
                    }
                }

                return $count;
            });
        }

        $this->audit->log('idea.guest_bulk_rejected', ['board_id' => $boardId, 'actor_id' => $actorId, 'count' => $rejected]);

        return $this->json($response, 200, ['ok' => true, 'rejected' => $rejected]);
    }

    /** @param array<string, mixed> $args */
    public function approve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed = $request->getParsedBody();
        $rawIds = is_array($parsed) ? ($parsed['ids'] ?? null) : null;
        $ids    = is_array($rawIds) ? array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $rawIds),
            static fn (int $id): bool => $id > 0,
        ))) : [];

        if ($ids === [] || count($ids) > self::MAX_BULK_IDS) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['ids' => 'Please select between 1 and ' . self::MAX_BULK_IDS . ' ideas.'],
                ],
            ]);
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId   = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $boardId   = (int) $board['id'];

        $approved = [];
        foreach ($ids as $ideaId) {
            $idea = $this->pendingIdea($boardId, $ideaId);
            if ($idea !== null && $this->publish($accountId, $board, $idea, $actorId)) {
                $approved[] = $ideaId;
            }
        }

        return $this->json($response, 200, ['ok' => true, 'approved' => $approved]);
    }

    /** @param array<string, mixed> $args */
    public function approveAndTrust(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $standingRepo = $this->standingRepo;
        $userRepo     = $this->userRepo;
        if (!$standingRepo instanceof AccountMemberStandingRepository || !$userRepo instanceof UserRepository) {
            throw new \LogicException('Approve-and-trust needs the standing and user repositories.');
        }

        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed = $request->getParsedBody();
        $rawId  = is_array($parsed) ? ($parsed['id'] ?? null) : null;
        $ideaId = is_int($rawId) || (is_string($rawId) && ctype_digit($rawId)) ? (int) $rawId : 0;
        if ($ideaId <= 0) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => ['id' => 'Please select one waiting idea.'],
                ],
            ]);
        }

        $boardId = (int) $board['id'];
        $idea    = $this->pendingIdea($boardId, $ideaId);
        if ($idea === null) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $authorId  = (int) $idea['author_id'];

        $author = $userRepo->findPublicProfileById($authorId);
        if (!is_array($author) || (int) ($author['is_guest_placeholder'] ?? 0) === 1 || (int) ($author['is_deleted_placeholder'] ?? 0) === 1) {
            return JsonError::respond($response, 422, 'author_not_trustable', 'Guest and deleted authors cannot be trusted.');
        }

        $standing = $standingRepo->findFor($accountId, $authorId);
        if ($standing !== null && !in_array($standing['state'] ?? null, Premoderation::TRUST_KEEPING_STATES, true)) {
            return JsonError::respond($response, 422, 'author_not_in_good_standing', 'This author is not in good standing and cannot be trusted.');
        }

        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        // Trust first: if another moderator approved the idea in the
        // meantime, the trust decision still stands.
        if ($standingRepo->trust($accountId, $authorId, $actorId)) {
            $this->audit->log('member.trusted', ['account_id' => $accountId, 'user_id' => $authorId, 'actor_id' => $actorId, 'reason' => 'manual']);
        }

        $approved = $this->publish($accountId, $board, $idea, $actorId) ? [$ideaId] : [];

        return $this->json($response, 200, ['ok' => true, 'approved' => $approved, 'trusted' => true]);
    }

    /** @return array<string, mixed>|null the idea when it is pending on this board */
    private function pendingIdea(int $boardId, int $ideaId): ?array
    {
        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId, null, includePending: true);

        return is_array($idea) && ($idea['moderation_state'] ?? null) === Premoderation::STATE_PENDING ? $idea : null;
    }

    /**
     * Publishes one pending idea and fires its side effects exactly once
     * (only when the conditional UPDATE flipped the row).
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $idea
     */
    private function publish(int $accountId, array $board, array $idea, int $actorId): bool
    {
        $boardId = (int) $board['id'];
        $slug    = (string) $board['slug'];
        $ideaId  = (int) $idea['id'];

        if (!$this->ideaRepo->approve($boardId, $ideaId, $actorId)) {
            return false;
        }

        $title = (string) $idea['title'];

        $this->audit->log('idea.approved', ['board_id' => $boardId, 'idea_id' => $ideaId, 'actor_id' => $actorId]);
        $this->notifier->notifyAuthorApproved($accountId, (int) $idea['author_id'], $title, "/{$slug}/idea/{$ideaId}");
        $this->webhookDispatcher?->dispatchAsync($boardId, $slug, 'idea.created', [
            'idea_id' => $ideaId,
            'title'   => $title,
            'status'  => (string) ($idea['status'] ?? 'open'),
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function board(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        return $this->boardRepo->findBySlugForAccount($slug, $accountId);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
