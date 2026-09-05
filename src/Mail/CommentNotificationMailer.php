<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Config;
use Votepit\Security\RateLimiter;

/**
 * Sends the actual `idea_comment`/`thread_reply` notification email
 * (notification-preferences PRD, issue 02) — called by CommentCreateAction
 * AFTER its transaction commits, once per eligible recipient (recipient
 * already has notification_email confirmed and the matching `*_email` flag
 * on; see CommentCreateAction::notifyRecipient()).
 *
 * Rate-limited through a SINGLE GLOBAL bucket `notification-mail:global`
 * (not per-user/per-IP, unlike every other RateLimiter caller) — a comment
 * storm must not eat into the shared hosting provider's send limit that
 * time-critical magic-link mail depends on. On
 * exhaustion the mail is silently SKIPPED, never queued/delayed (no queue
 * system) — the in-app notification (already committed by the caller) is
 * unaffected either way.
 */
final readonly class CommentNotificationMailer
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
        private int $rateLimit,
        private int $rateWindow,
    ) {}

    public function send(string $toEmail, string $title, string $bodyText, string $linkPath, ?int $boardId): void
    {
        if (!$this->rateLimiter->hit('notification-mail:global', $this->rateLimit, $this->rateWindow)) {
            return;
        }

        // Board-bound (comment events always originate on a board) — same
        // board-override → installation-default precedence as board mail
        // (PRD "Mailer-Wiederverwendung").
        $mailToUse = $this->mailer ?? $this->smtpResolver->buildMailer($boardId);

        $rendered = MailTemplate::render(
            $title,
            [$bodyText],
            $this->config->appUrl . $linkPath,
            'View on Votepit',
        );

        $mailToUse->send($toEmail, $title, $rendered['text'], $rendered['html'], $rendered['image']);
    }
}
