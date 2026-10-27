<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;

/**
 * In-app notifications of the pre-moderation flow (issue 05).
 *
 *  - notifyModerators(): ONE aggregated "ideas are waiting for approval"
 *    notice per moderator (owner/admin/moderator) — a new one is only
 *    created once the previous one was read or dismissed, so a busy board
 *    never floods the inbox. In-app only, never mail. The notice carries
 *    the board name only, never the pending idea's content.
 *  - notifyAuthorApproved(): the author's "your idea is now public" notice,
 *    gated by the same notify_idea_status_inapp preference as status
 *    changes. Rejection reuses the existing moderation-removal mail
 *    (IdeaModerationAction), unchanged.
 */
final readonly class PremoderationNotifier
{
    public const TYPE_PENDING  = 'ideas_pending_approval';
    public const TYPE_APPROVED = 'idea_approved';

    public function __construct(
        private AccountMemberRepository $members,
        private NotificationRepository $notifications,
        private ?UserRepository $users = null,
    ) {}

    public static function queuePath(string $boardSlug): string
    {
        return '/admin/boards/' . $boardSlug . '/queue';
    }

    public function notifyModerators(int $accountId, string $boardSlug, string $boardName, int $excludeUserId): void
    {
        $linkPath = self::queuePath($boardSlug);

        foreach ($this->members->listForAccount($accountId) as $member) {
            $userId = (int) ($member['user_id'] ?? 0);
            if ($userId === $excludeUserId || !Premoderation::canModerate(is_string($member['role'] ?? null) ? $member['role'] : null)) {
                continue;
            }
            if ($this->notifications->hasUnreadForUser($userId, self::TYPE_PENDING, $linkPath)) {
                continue;
            }

            $this->notifications->createForUser(
                $accountId,
                $userId,
                self::TYPE_PENDING,
                'Ideas awaiting approval',
                "New ideas on \"{$boardName}\" are waiting for your approval.",
                $linkPath,
            );
        }
    }

    public function notifyAuthorApproved(int $accountId, int $authorId, string $ideaTitle, string $linkPath): void
    {
        // Guest ideas (ADR 0006 §1): the placeholder is no inbox — the
        // submitting device sees the state via guest-mine instead.
        if ($this->users?->isGuestPlaceholder($authorId) === true) {
            return;
        }

        $settings = $this->users?->findNotificationSettings($authorId);
        if (is_array($settings) && !(bool) ($settings['notify_idea_status_inapp'] ?? true)) {
            return;
        }

        $this->notifications->createForUser(
            $accountId,
            $authorId,
            self::TYPE_APPROVED,
            'Your idea is now public',
            "\"{$ideaTitle}\" was approved and is now visible on the board.",
            $linkPath,
        );
    }
}
