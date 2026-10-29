<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Doctrine\DBAL\Connection;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IdeaStatusDecisionRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\EncryptionService;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Applies ONE already-validated idea status change and everything that
 * hangs off it — shared by the moderator status route (IdeaStatusAction)
 * and the inbound issue-tracker status sync (IssueTracker\IssueStatusSync),
 * so a status closed from Linear/GitHub notifies voters exactly like a
 * manual change.
 *
 * Callers own validation (known key, allowed transition, from !== to) and
 * board/account scoping; this class only executes:
 *
 *  - status update + 'idea_status_changed' in-app notification fan-out to the
 *    author + every DISTINCT voter + every DISTINCT commenter + every
 *    follower (deduplicated, excluding the actor and blocked members) in ONE
 *    transaction;
 *  - the e-mail channel per recipient, decided by ParticipantMailRouter
 *    inside that same transaction (close-the-loop slice 03): the author gets
 *    the mail at once by default, everyone else (voters, commenters,
 *    followers) gets it in the batched participant digest, unless their
 *    category cadence says otherwise. Anonymous voters with a stored
 *    notification e-mail (ADR 0004) are queued for the digest as well, with
 *    a one-click unsubscribe;
 *  - afterwards (network I/O outside the transaction) the instant mails,
 *    through the shared global mail bucket;
 *  - the masked audit entry (ids and status keys only, no PII);
 *  - the deferred board-webhook fan-out.
 */
final readonly class IdeaStatusChanger
{
    /** Title of the idea_status_changed notification (in-app and e-mail). */
    public const NOTIFY_TITLE = 'Idea status updated';

    public function __construct(
        private Connection $conn,
        private IdeaRepository $ideaRepo,
        private IdeaStatusDecisionRepository $decisions,
        private AuditLogger $audit,
        private NotificationRepository $notifications,
        private UserRepository $userRepo,
        private VoteRepository $voteRepo,
        private CommentRepository $commentRepo,
        private CommentNotificationMailer $notificationMailer,
        private EncryptionService $anonEmailEncryption,
        private IdeaFollowRepository $follows,
        private ParticipantMailRouter $participantMail,
        private AccountMailLinks $mailLinks,
        private ?BlockRepository $blockRepo = null,
        private ?WebhookDispatcher $webhookDispatcher = null,
    ) {}

    /**
     * @param array<string, mixed> $idea      row from IdeaRepository::findInBoard()
     * @param int                  $actorId   user who triggered the change (0 = system, e.g. an issue-tracker sync)
     * @param array<string, mixed> $auditExtra additional non-PII audit fields
     * @param ?StatusDecision      $decision  optional public reasoning (P-01); null = no reason, the change is still recorded in the history
     */
    public function apply(
        int $accountId,
        int $boardId,
        string $boardSlug,
        array $idea,
        BoardStatuses $statuses,
        string $to,
        int $actorId,
        array $auditExtra = [],
        ?StatusDecision $decision = null,
    ): void {
        $decision ??= StatusDecision::none();
        $ideaId       = (int) $idea['id'];
        $ideaAuthorId = (int) $idea['author_id'];
        $from         = (string) ($idea['status_key'] ?? 'open');
        $toCategory   = (string) $statuses->categoryOf($to);
        $fromCategory = $statuses->categoryOf($from) ?? (string) ($idea['status'] ?? 'open');
        $ideaTitle    = (string) ($idea['title'] ?? '');
        $linkPath     = "/{$boardSlug}/idea/{$ideaId}";
        $toLabel      = $statuses->notificationLabel($to);
        // Plaintext reason, appended to the status text (mail bodies are HTML-escaped by MailTemplate).
        $notifyBody   = self::statusNotifyBody($ideaTitle, $toLabel, $decision->reason);
        $details = ['title' => $ideaTitle, 'status' => $toLabel, 'reason' => $decision->reason];
        $now     = new \DateTimeImmutable();
        // In-app notifications keep the relative path (the SPA prefixes it);
        // e-mails need the full path, which carries the account in cloud mode.
        $mailLinkPath = $this->mailLinks->path($accountId, $linkPath);

        /** @var list<array{email: string, title: string, body: string, link_path: string, headers: array<string, string>, unsubscribe: array{label: string, url: string}}> $instantCandidates */
        $instantCandidates = [];
        $this->conn->transactional(function () use (
            $accountId,
            $boardId,
            $ideaId,
            $ideaAuthorId,
            $to,
            $actorId,
            $linkPath,
            $mailLinkPath,
            $notifyBody,
            $details,
            $decision,
            $from,
            $now,
            &$instantCandidates,
        ): void {
            $this->ideaRepo->updateStatus($boardId, $ideaId, $to);
            // History row in the same transaction: status and decision commit together.
            $this->decisions->insert($boardId, $ideaId, $from, $to, $decision, $actorId);

            // Recipients: idea author + every DISTINCT voter + every DISTINCT
            // commenter + every follower, deduplicated via array_unique (a user
            // in multiple roles gets exactly one notification row).
            $recipientIds = [$ideaAuthorId];
            $recipientIds = array_merge($recipientIds, $this->voteRepo->distinctVoterIds($ideaId));
            $recipientIds = array_merge($recipientIds, $this->commentRepo->distinctPriorAuthorIds($ideaId, []));
            $recipientIds = array_merge($recipientIds, $this->follows->followerIds($ideaId));
            $recipientIds = array_unique($recipientIds);

            foreach ($recipientIds as $recipientId) {
                // Never notify the moderator who triggered the change themselves.
                if ($recipientId === $actorId) {
                    continue;
                }

                if ($this->blockRepo instanceof BlockRepository && $this->blockRepo->isBlocked($accountId, $recipientId, $boardId)) {
                    continue;
                }

                $this->notifyRecipient(
                    $accountId,
                    $boardId,
                    $ideaId,
                    $recipientId === $ideaAuthorId ? ParticipantMailCategory::MY_IDEAS : ParticipantMailCategory::PARTICIPATED,
                    $recipientId,
                    $notifyBody,
                    $details,
                    $linkPath,
                    $mailLinkPath,
                    $now,
                    $instantCandidates,
                );
            }

            // Anonymous voters who left an optional notification email. They
            // are always digest-only (no account, no instant channel). The
            // address is decrypted here, deduplicated by normalized address —
            // the ciphertext itself can't be deduped (nonce-randomized).
            /** @var array<string, true> $seenAnonEmails */
            $seenAnonEmails = [];
            foreach ($this->voteRepo->distinctAnonymousVoterEmailsEnc($ideaId) as $encrypted) {
                $email = $this->anonEmailEncryption->decrypt($encrypted);
                if ($email === null || $email === '') {
                    continue;
                }

                $normalized = strtolower(trim($email));
                if (isset($seenAnonEmails[$normalized])) {
                    continue;
                }
                $seenAnonEmails[$normalized] = true;

                $this->participantMail->routeGuest($email, $boardId, $ideaId, ParticipantMailRouter::EVENT_STATUS, $details, $mailLinkPath, $now);
            }
        });

        foreach ($instantCandidates as $candidate) {
            $this->notificationMailer->send(
                $candidate['email'],
                $candidate['title'],
                $candidate['body'],
                $candidate['link_path'],
                $boardId,
                $candidate['headers'],
                $candidate['unsubscribe'],
            );
        }

        // --- Masked audit: board, idea, from→to, actor ID — no PII ---
        $this->audit->log('idea.status.changed', [
            'board_id'        => $boardId,
            'idea_id'         => $ideaId,
            'status_from'     => $fromCategory,
            'status_to'       => $toCategory,
            'status_key_from' => $from,
            'status_key_to'   => $to,
            'actor_id'        => $actorId,
            ...$auditExtra,
        ]);

        // board-webhooks fan-out: best-effort, deferred (see WebhookDispatcher
        // class doc) — never delays or can fail the caller.
        $this->webhookDispatcher?->dispatchAsync($boardId, $boardSlug, 'idea.status_changed', [
            'idea_id'         => $ideaId,
            'title'           => $ideaTitle,
            'status_from'     => $fromCategory,
            'status_to'       => $toCategory,
            'status_key_from' => $from,
            'status_key_to'   => $to,
        ]);
    }

    /**
     * Body of the status-change notification, shared with
     * bin/send-mail-previews.php. The plaintext reason (if any) is appended.
     */
    public static function statusNotifyBody(string $ideaTitle, string $toLabel, ?string $reason): string
    {
        $body = "\"{$ideaTitle}\" is now {$toLabel}.";
        if ($reason !== null) {
            $body .= ' Reason: ' . $reason;
        }

        return $body;
    }

    /**
     * Creates the in-app notification row (only if the recipient's
     * notify_idea_status_inapp flag is on) and decides the e-mail channel
     * through ParticipantMailRouter: an instant mail is collected for after the
     * commit, a batched one is already queued inside the transaction.
     *
     * @param array{title: string, status: string, reason: ?string} $details
     * @param list<array{email: string, title: string, body: string, link_path: string, headers: array<string, string>, unsubscribe: array{label: string, url: string}}> $instantCandidates
     */
    private function notifyRecipient(
        int $accountId,
        int $boardId,
        int $ideaId,
        string $category,
        int $recipientId,
        string $body,
        array $details,
        string $linkPath,
        string $mailLinkPath,
        \DateTimeImmutable $now,
        array &$instantCandidates,
    ): void {
        $settings = $this->userRepo->findNotificationSettings($recipientId);
        if (!is_array($settings)) {
            return;
        }

        $title = self::NOTIFY_TITLE;

        if ((bool) ($settings['notify_idea_status_inapp'] ?? false)) {
            $this->notifications->createForUser($accountId, $recipientId, 'idea_status_changed', $title, $body, $linkPath);
        }

        $notificationEmail = is_string($settings['notification_email'] ?? null) ? $settings['notification_email'] : null;
        if ($notificationEmail === null) {
            return;
        }

        $result = $this->participantMail->routeUser($recipientId, $category, $boardId, $ideaId, ParticipantMailRouter::EVENT_STATUS, $details, $mailLinkPath, $now);
        if ($result !== ParticipantMailRouter::RESULT_INSTANT) {
            return;
        }

        $instantCandidates[] = [
            'email'       => $notificationEmail,
            'title'       => $title,
            'body'        => $body,
            'link_path'   => $mailLinkPath,
            'headers'     => $this->participantMail->unsubscribeHeaders($recipientId, null, $category),
            'unsubscribe' => ['label' => 'Unsubscribe from these updates', 'url' => $this->participantMail->unsubscribePageUrl($recipientId, null, $category)],
        ];
    }
}
