<?php

declare(strict_types=1);

namespace Votepit\Security;

use Votepit\Config;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\LoginTokenRepository;

/**
 * Shared "issue a password-reset link and mail it" logic — used by the
 * self-service POST /password/reset/request flow AND every admin/operator-
 * triggered reset (Owner/Admin for account members, Operator/Support for
 * any user). All of these funnel into the SAME mail-link flow — nobody ever
 * sets or reveals a plaintext password on the target's behalf.
 */
final readonly class PasswordResetMailer
{
    /**
     * TTL of the reset token: 30 minutes. Shorter than the 7-day invite TTL
     * (a credential-replacement capability is far more sensitive than an
     * invite) but longer than the 15-minute magic-link TTL — resetting a
     * password is a slower, more deliberate user action (open mail client,
     * come back, type + confirm a new password) than following a login link.
     */
    private const TOKEN_TTL_SECONDS = 1800;

    public function __construct(
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
    ) {}

    public function send(int $userId, string $email): void
    {
        // Same "no accumulation" convention as POST /login / InviteAction —
        // deletes ANY open token for this user (any purpose), so a fresh
        // reset request invalidates a stale pending magic-link/2FA/reset
        // token rather than piling up.
        $this->tokenRepo->deleteOpenForUser($userId);

        $pair      = $this->vault->generate();
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_SECONDS . ' seconds'))
            ->format('Y-m-d H:i:s');
        $this->tokenRepo->insert($userId, $pair['hash'], $expiresAt, 'password_reset');

        // No board context here (global/identity-scoped route, like invite
        // accept) — resolve(null) falls back to the installation-wide SMTP
        // config, same as InviteAction.
        $link      = $this->config->appUrl . '/password/reset/confirm?token=' . $pair['token'];
        $mailToUse = $this->mailer ?? $this->smtpResolver->buildMailer(null);

        $resetMail = MailTemplate::render(
            'Reset your password',
            [
                'Hello,',
                'you requested to reset your Votepit password. '
                . 'Click the following link to set a new password:',
            ],
            $link,
            'Set a new password',
            ['The link is valid for 30 minutes.', 'If this was not you, please ignore this email.'],
        );

        $mailToUse->send(
            $email,
            'Reset your Votepit password',
            $resetMail['text'],
            $resetMail['html'],
            $resetMail['image'],
        );
    }
}
