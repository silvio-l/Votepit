<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\DuplicateCandidateFinder;
use Votepit\Domain\IdeaSearchQuery;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\EncryptionService;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Merging duplicate ideas (competitor-parity issue 10), all three routes
 * accountModerate (owner|admin|moderator) in IdeaRoutes, CSRF/BlockCheck
 * global on the POSTs:
 *
 *   POST /{board}/ideas/{id}/merge  {target_id} — fold idea {id} into target.
 *   POST /{board}/ideas/{id}/unmerge             — undo, lossless.
 *   GET  /{board}/ideas/{id}/merge-candidates?q= — picker: deterministic
 *        suggestions (DuplicateCandidateFinder, optionally confirmed by an
 *        extension's DuplicateConfirmer — issue 11, `confirmation` field) or,
 *        with q, a title search.
 *
 * Account- and board-scoped: the board is resolved via
 * findBySlugForAccount(), both ideas via findInBoard() on that board — a
 * target on another board/account is indistinguishable from an unknown id
 * (422, nothing touched). Rules: never into itself, the target must not be
 * merged itself (no chains), the source must not have merged children.
 *
 * Votes are not moved or copied: the target's score/up/down become the
 * group aggregate, one vote per voter, the vote on the canonical idea wins
 * (VoteRepository::recomputeMergeGroup()), in the same transaction as the
 * merge and the notification rows. The duplicate's author, voters and
 * commenters are notified through the existing idea-status notification
 * path (type idea_status_changed, notify_idea_status_* preferences, plus
 * anonymous voters who left an e-mail) — no new notification type.
 * Afterwards: audit entry and the `idea.merged` board webhook (unmerge is
 * audited, no webhook).
 */
final readonly class IdeaMergeAction
{
    /** Title of the merge notification (in-app and e-mail). */
    public const NOTIFY_TITLE = 'Idea merged';

    /** Picker size for deterministic suggestions and title search. */
    private const CANDIDATE_LIMIT = 10;

    public function __construct(
        private Connection $conn,
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
        private CommentRepository $commentRepo,
        private DuplicateCandidateFinder $duplicateFinder,
        private AuditLogger $audit,
        private NotificationRepository $notifications,
        private UserRepository $userRepo,
        private CommentNotificationMailer $notificationMailer,
        private EncryptionService $anonEmailEncryption,
        private AccountMailLinks $mailLinks,
        private ?BlockRepository $blockRepo = null,
        private ?WebhookDispatcher $webhookDispatcher = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function merge(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $slug    = (string) $board['slug'];
        $source  = $this->ideaRepo->findInBoard($boardId, (int) ($args['id'] ?? 0));
        if (!is_array($source)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }
        $sourceId = (int) $source['id'];

        $parsed   = $request->getParsedBody();
        $targetId = is_array($parsed) && is_numeric($parsed['target_id'] ?? null) ? (int) $parsed['target_id'] : 0;
        $target   = $targetId !== $sourceId ? $this->ideaRepo->findInBoard($boardId, $targetId) : null;
        if (!is_array($target)) {
            return JsonError::respond($response, 422, 'merge_invalid_target', 'Choose another idea on this board to merge into.');
        }
        if ($this->ideaRepo->hasMergedChildren($boardId, $sourceId)) {
            return JsonError::respond($response, 422, 'merge_has_merged_children', 'Other ideas are merged into this idea. Merge them into the target directly.');
        }

        $actorId     = $this->actorId($request);
        $accountId   = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $sourceTitle = (string) $source['title'];
        $targetTitle = (string) $target['title'];
        $linkPath    = "/{$slug}/idea/{$targetId}";
        // In-app notifications keep the relative path; mails need the account segment in cloud mode.
        $mailLinkPath = $this->mailLinks->path($accountId, $linkPath);
        $body        = self::mergedNotifyBody($sourceTitle, $targetTitle);

        /** @var list<array{email: string, title: string, body: string, link_path: string}> $emailCandidates */
        $emailCandidates = [];
        $merged = $this->conn->transactional(function () use ($boardId, $sourceId, $targetId, $actorId, $accountId, $source, $body, $linkPath, &$emailCandidates): bool {
            if (!$this->ideaRepo->merge($boardId, $sourceId, $targetId, $actorId)) {
                return false;
            }
            $this->voteRepo->recomputeMergeGroup($boardId, $targetId);

            $recipientIds = array_unique(array_merge(
                [(int) $source['author_id']],
                $this->voteRepo->distinctVoterIds($sourceId),
                $this->commentRepo->distinctPriorAuthorIds($sourceId, []),
            ));
            foreach ($recipientIds as $recipientId) {
                if ($recipientId <= 0 || $recipientId === $actorId) {
                    continue;
                }
                if ($this->blockRepo instanceof BlockRepository && $this->blockRepo->isBlocked($accountId, $recipientId, $boardId)) {
                    continue;
                }
                $this->notifyRecipient($accountId, $recipientId, $body, $linkPath, $emailCandidates);
            }

            return true;
        });
        if (!$merged) {
            // Lost a race against a concurrent merge/moderation of the source.
            return JsonError::respond($response, 422, 'merge_invalid_target', 'Choose another idea on this board to merge into.');
        }

        $this->collectAnonymousVoterEmails($sourceId, $body, $mailLinkPath, $emailCandidates);
        foreach ($emailCandidates as $candidate) {
            $this->notificationMailer->send($candidate['email'], $candidate['title'], $candidate['body'], $candidate['link_path'], $boardId);
        }

        $this->audit->log('idea.merged', [
            'board_id'       => $boardId,
            'idea_id'        => $sourceId,
            'merged_into_id' => $targetId,
            'actor_id'       => $actorId,
        ]);

        // Payload contract consumed by the chat-notification forwarding.
        $this->webhookDispatcher?->dispatchAsync($boardId, $slug, 'idea.merged', [
            'idea_id'           => $sourceId,
            'title'             => $sourceTitle,
            'merged_into_id'    => $targetId,
            'merged_into_title' => $targetTitle,
        ]);

        return $this->json($response, [
            'ok'          => true,
            'idea_id'     => $sourceId,
            'merged_into' => ['id' => $targetId, 'title' => $targetTitle],
        ]);
    }

    /** @param array<string, mixed> $args */
    public function unmerge(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        $formerTarget = $this->conn->transactional(function () use ($boardId, $ideaId): ?int {
            $targetId = $this->ideaRepo->unmerge($boardId, $ideaId);
            if ($targetId !== null) {
                $this->voteRepo->recomputeMergeGroup($boardId, $targetId);
            }

            return $targetId;
        });
        if ($formerTarget === null) {
            return JsonError::respond($response, 404, 'not_found', 'Merged idea not found.');
        }

        $this->audit->log('idea.unmerged', [
            'board_id'       => $boardId,
            'idea_id'        => $ideaId,
            'merged_into_id' => $formerTarget,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->json($response, ['ok' => true, 'idea_id' => $ideaId]);
    }

    /** @param array<string, mixed> $args */
    public function candidates(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $boardId = (int) $board['id'];
        $source  = $this->ideaRepo->findInBoard($boardId, (int) ($args['id'] ?? 0));
        if (!is_array($source)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }
        $sourceId = (int) $source['id'];

        $query  = $request->getQueryParams()['q'] ?? null;
        $search = IdeaSearchQuery::fromRaw(is_string($query) ? $query : null);

        // Suggestions only (never a manual title search): the optional
        // confirmation step of an extension (issue 11) and its availability,
        // so the dialog can mark confirmed suggestions or show a plan hint.
        $confirmation = null;
        if ($search instanceof IdeaSearchQuery) {
            $rows = array_values(array_filter(
                $this->ideaRepo->listByBoard($boardId, limit: self::CANDIDATE_LIMIT + 1, search: $search),
                static fn (array $row): bool => (int) $row['id'] !== $sourceId,
            ));
        } else {
            $accountId    = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $confirmation = $this->duplicateFinder->confirmationAvailability($accountId);
            $rows         = $this->duplicateFinder->find(
                $boardId,
                (string) $source['title'],
                self::CANDIDATE_LIMIT,
                excludeIdeaId: $sourceId,
                confirmForAccountId: $confirmation === DuplicateConfirmationAvailability::Available ? $accountId : null,
            );
        }

        $candidates = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'status'     => (string) $row['status'],
            'up_count'   => (int) ($row['up_count'] ?? 0),
            'down_count' => (int) ($row['down_count'] ?? 0),
            'similarity' => isset($row['similarity']) ? (float) $row['similarity'] : null,
            'confirmed'  => is_bool($row['confirmed'] ?? null) ? $row['confirmed'] : null,
        ], array_slice($rows, 0, self::CANDIDATE_LIMIT));

        return $this->json($response, ['candidates' => $candidates, 'confirmation' => $confirmation?->value]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function board(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return null;
        }
        $board['slug'] = $slug;

        return $board;
    }

    private function actorId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /**
     * Body of the merge notification, shared with bin/send-mail-previews.php.
     */
    public static function mergedNotifyBody(string $sourceTitle, string $targetTitle): string
    {
        return "\"{$sourceTitle}\" was merged into \"{$targetTitle}\".";
    }

    /**
     * Same independent in-app/e-mail channels as IdeaStatusAction (the
     * idea-status notification preferences cover "something happened to an
     * idea you follow").
     *
     * @param list<array{email: string, title: string, body: string, link_path: string}> $emailCandidates
     */
    private function notifyRecipient(int $accountId, int $recipientId, string $body, string $linkPath, array &$emailCandidates): void
    {
        $settings = $this->userRepo->findNotificationSettings($recipientId);
        if (!is_array($settings)) {
            return;
        }

        $title = self::NOTIFY_TITLE;
        if ((bool) ($settings['notify_idea_status_inapp'] ?? false)) {
            $this->notifications->createForUser($accountId, $recipientId, 'idea_status_changed', $title, $body, $linkPath);
        }

        $notificationEmail = is_string($settings['notification_email'] ?? null) ? $settings['notification_email'] : null;
        if ($notificationEmail !== null && (bool) ($settings['notify_idea_status_email'] ?? false)) {
            $emailCandidates[] = ['email' => $notificationEmail, 'title' => $title, 'body' => $body, 'link_path' => $this->mailLinks->path($accountId, $linkPath)];
        }
    }

    /**
     * Anonymous voters on the duplicate who left a notification e-mail (ADR
     * 0004) — decrypted outside the transaction, fail-secure (an undecryptable
     * blob is skipped), deduplicated by normalized address.
     *
     * @param list<array{email: string, title: string, body: string, link_path: string}> $emailCandidates
     */
    private function collectAnonymousVoterEmails(int $ideaId, string $body, string $linkPath, array &$emailCandidates): void
    {
        /** @var array<string, true> $seen */
        $seen = [];
        foreach ($this->voteRepo->distinctAnonymousVoterEmailsEnc($ideaId) as $encrypted) {
            $email = $this->anonEmailEncryption->decrypt($encrypted);
            if ($email === null || $email === '') {
                continue;
            }
            $normalized = strtolower(trim($email));
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $emailCandidates[] = ['email' => $email, 'title' => self::NOTIFY_TITLE, 'body' => $body, 'link_path' => $linkPath];
        }
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
