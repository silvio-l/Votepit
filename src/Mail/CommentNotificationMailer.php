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
        private ?BoardMailBranding $branding = null,
    ) {}

    /**
     * @param array<string, string>               $headers     RFC 8058 unsubscribe headers (participant mails); empty for other callers
     * @param array{label: string, url: string}|null $unsubscribe one-click unsubscribe link in the footer (participant mails)
     * @param string                                 $cta         button label, already in the recipient's language
     * @param string|null                            $buttonFallback line above the copyable link, already in the recipient's language (null = English)
     */
    public function send(
        string $toEmail,
        string $title,
        string $bodyText,
        string $linkPath,
        ?int $boardId,
        array $headers = [],
        ?array $unsubscribe = null,
        string $cta = 'View on Votepit',
        ?string $buttonFallback = null,
    ): void {
        if (!$this->rateLimiter->hit('notification-mail:global', $this->rateLimit, $this->rateWindow)) {
            return;
        }

        // Board-bound (comment events always originate on a board) — same
        // board-override → installation-default precedence as board mail
        // (PRD "Mailer-Wiederverwendung").
        $mailToUse = $this->mailer ?? $this->smtpResolver->buildMailer($boardId);

        // P-32: branded only for Pro boards (branded_mails); everything else is the
        // unchanged Votepit mail. The envelope address never changes.
        $branded = $boardId !== null && $this->branding instanceof \Votepit\Mail\BoardMailBranding ? $this->branding->forBoard($boardId) : null;

        $rendered = self::compose($this->config->appUrl, $title, $bodyText, $linkPath, $branded['brand'] ?? null, $unsubscribe, $cta, $buttonFallback);

        $mailToUse->send(
            $toEmail,
            $title,
            $rendered['text'],
            $rendered['html'],
            $rendered['image'],
            $branded['sender_name'] ?? null,
            $headers,
        );
    }

    /**
     * The notification mail body (shared with bin/send-mail-previews.php, so the
     * preview is the exact production rendering).
     *
     * @param array{label: string, url: string}|null $unsubscribe
     * @return array{text: string, html: string, image: InlineImage}
     */
    public static function compose(string $appUrl, string $title, string $bodyText, string $linkPath, ?MailBrand $brand, ?array $unsubscribe = null, string $cta = 'View on Votepit', ?string $buttonFallback = null): array
    {
        return MailTemplate::render(
            $title,
            [$bodyText],
            $appUrl . $linkPath,
            $cta,
            footerLink: $unsubscribe,
            brand: $brand,
            buttonFallback: $buttonFallback,
        );
    }
}
