<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\NotificationEmailVerificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\TokenVault;

/**
 * GET    /account/notification-preferences   — read the per-event-type flags + confirmed notification_email (AuthZ: user).
 * PUT    /account/notification-preferences   — set the per-event-type flags (AuthZ: user, CSRF).
 * POST   /account/notification-email         — submit a candidate address, sends a confirm link (AuthZ: user, CSRF, rate-limited).
 * GET    /account/notification-email/confirm — confirm the pending address via the emailed token (AuthZ: user, GET → CSRF-exempt, analog /login/verify).
 * DELETE /account/notification-email         — remove the confirmed address (AuthZ: user, CSRF).
 *
 * User-scoped (NOT account-scoped, no /{account} prefix), same convention as
 * AccountProfileAction — preferences are global per user (PRD "Out of
 * Scope"), not per account/board.
 *
 * `notification_email` is a distinct plaintext PII field from the
 * HMAC-pseudonymized identity email (ADR 0002 Amendment §6) — never used
 * for login, set ONLY via the confirm-link flow, so "column is non-NULL"
 * always means "confirmed" (see UserRepository::findNotificationSettings()).
 */
final readonly class NotificationPreferencesAction
{
    /** 30 minutes — mirrors PasswordResetRequestAction's TOKEN_TTL_SECONDS. */
    private const TOKEN_TTL_SECONDS = 1800;

    public function __construct(
        private UserRepository $users,
        private NotificationEmailVerificationRepository $verifications,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
        private AuditLogger $audit,
    ) {}

    /** GET /account/notification-preferences — the caller's own preference flags + confirmed notification_email (or null). */
    public function getPreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId   = $this->currentUserId($request);
        $settings = $this->users->findNotificationSettings($userId);

        $response->getBody()->write((string) json_encode([
            'notification_email' => is_array($settings) && is_string($settings['notification_email'] ?? null)
                ? $settings['notification_email']
                : null,
            'idea_comment_inapp' => is_array($settings) && (bool) ($settings['notify_idea_comment_inapp'] ?? false),
            'idea_comment_email' => is_array($settings) && (bool) ($settings['notify_idea_comment_email'] ?? false),
            'thread_reply_inapp' => is_array($settings) && (bool) ($settings['notify_thread_reply_inapp'] ?? false),
            'thread_reply_email' => is_array($settings) && (bool) ($settings['notify_thread_reply_email'] ?? false),
            'idea_status_inapp'  => is_array($settings) && (bool) ($settings['notify_idea_status_inapp'] ?? false),
            'idea_status_email'  => is_array($settings) && (bool) ($settings['notify_idea_status_email'] ?? false),
            'abuse_report_inapp'   => is_array($settings) && (bool) ($settings['notify_abuse_report_inapp'] ?? false),
            'abuse_report_email'   => is_array($settings) && (bool) ($settings['notify_abuse_report_email'] ?? false),
            'support_ticket_inapp' => is_array($settings) && (bool) ($settings['notify_support_ticket_inapp'] ?? false),
            'support_ticket_email' => is_array($settings) && (bool) ($settings['notify_support_ticket_email'] ?? false),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** PUT /account/notification-preferences */
    public function putPreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        // A key the client omits keeps its current DB value rather than
        // being coerced to false — a stale/partial payload (e.g. an old
        // cached SPA bundle that doesn't know about a newer preference
        // column) must never silently disable an always-on-by-default
        // preference such as abuse-report notifications (security-review
        // finding, 2026-09-05).
        $current = $this->users->findNotificationSettings($userId);
        $field   = static function (array $body, string $bodyKey, string $dbColumn, ?array $current): bool {
            if (array_key_exists($bodyKey, $body)) {
                return (bool) $body[$bodyKey];
            }
            return is_array($current) && (bool) ($current[$dbColumn] ?? false);
        };

        $ideaCommentInApp = $field($body, 'idea_comment_inapp', 'notify_idea_comment_inapp', $current);
        $ideaCommentEmail = $field($body, 'idea_comment_email', 'notify_idea_comment_email', $current);
        $threadReplyInApp = $field($body, 'thread_reply_inapp', 'notify_thread_reply_inapp', $current);
        $threadReplyEmail = $field($body, 'thread_reply_email', 'notify_thread_reply_email', $current);
        $ideaStatusInApp  = $field($body, 'idea_status_inapp', 'notify_idea_status_inapp', $current);
        $ideaStatusEmail  = $field($body, 'idea_status_email', 'notify_idea_status_email', $current);
        $abuseReportInApp   = $field($body, 'abuse_report_inapp', 'notify_abuse_report_inapp', $current);
        $abuseReportEmail   = $field($body, 'abuse_report_email', 'notify_abuse_report_email', $current);
        $supportTicketInApp = $field($body, 'support_ticket_inapp', 'notify_support_ticket_inapp', $current);
        $supportTicketEmail = $field($body, 'support_ticket_email', 'notify_support_ticket_email', $current);

        $this->users->setNotificationPreferences(
            $userId,
            $ideaCommentInApp,
            $ideaCommentEmail,
            $threadReplyInApp,
            $threadReplyEmail,
            $ideaStatusInApp,
            $ideaStatusEmail,
            $abuseReportInApp,
            $abuseReportEmail,
            $supportTicketInApp,
            $supportTicketEmail,
        );
        $this->audit->log('user.notification_preferences_updated', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode([
            'ok'                  => true,
            'idea_comment_inapp'  => $ideaCommentInApp,
            'idea_comment_email'  => $ideaCommentEmail,
            'thread_reply_inapp'  => $threadReplyInApp,
            'thread_reply_email'  => $threadReplyEmail,
            'idea_status_inapp'   => $ideaStatusInApp,
            'idea_status_email'   => $ideaStatusEmail,
            'abuse_report_inapp'   => $abuseReportInApp,
            'abuse_report_email'   => $abuseReportEmail,
            'support_ticket_inapp' => $supportTicketInApp,
            'support_ticket_email' => $supportTicketEmail,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** POST /account/notification-email — body: { email } */
    public function requestEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->errorResponse($response, 422, 'invalid_email', 'Please enter a valid email address.');
        }

        $this->verifications->deleteForUser($userId);

        $pair      = $this->vault->generate();
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
        $this->verifications->insert($userId, $email, $pair['hash'], $expiresAt);

        // No board context (user-scoped, identity-level route, like
        // password reset) — resolve(null) falls back to the installation-
        // wide SMTP config.
        $link      = $this->config->appUrl . '/account/notification-email/confirm?token=' . $pair['token'];
        $mailToUse = $this->mailer ?? $this->smtpResolver->buildMailer(null);

        // Deliberately distinct subject/heading from the magic-link and
        // password-reset mails (PRD Story 13 — phishing resistance, clear
        // what this link is for).
        $confirmMail = MailTemplate::render(
            'Confirm your notification email',
            [
                'Hello,',
                'you asked to receive Votepit notifications at this address. '
                . 'Click the following link to confirm it:',
            ],
            $link,
            'Confirm email address',
            ['The link is valid for 30 minutes.', 'If this was not you, please ignore this email.'],
        );

        $mailToUse->send(
            $email,
            'Confirm your Votepit notification email',
            $confirmMail['text'],
            $confirmMail['html'],
            $confirmMail['image'],
        );

        $this->audit->log('user.notification_email_requested', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** GET /account/notification-email/confirm?token=<plaintext> */
    public function confirmEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $params = $request->getQueryParams();
        $token  = is_string($params['token'] ?? null) ? $params['token'] : '';

        $row = $token !== '' ? $this->verifications->findActiveByHash($this->vault->hash($token)) : null;

        // Fail-secure: no active token, hash mismatch, OR the token belongs
        // to a DIFFERENT user than the one currently logged in — never
        // confirm an address into the wrong account.
        if (!is_array($row) || !$this->vault->verify($token, (string) $row['token_hash']) || (int) $row['user_id'] !== $userId) {
            $this->audit->log('user.notification_email_confirm_failed', ['user_id' => $userId]);
            return $this->errorResponse($response, 400, 'invalid_token', 'The link is invalid or has expired.');
        }

        $email = (string) $row['email'];
        $this->users->setNotificationEmail($userId, $email);
        $this->verifications->deleteForUser($userId);

        $this->audit->log('user.notification_email_confirmed', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** DELETE /account/notification-email — Story 6/7: remove the address, keep in-app prefs untouched. */
    public function deleteEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);

        $this->users->clearNotificationEmail($userId);
        $this->verifications->deleteForUser($userId);

        $this->audit->log('user.notification_email_removed', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function errorResponse(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
