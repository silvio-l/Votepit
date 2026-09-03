<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Votepit\SmtpConfig;

/**
 * Symfony Mailer adapter (arch.md §5).
 *
 * Builds the Symfony Mailer from SmtpConfig (SMTP/TLS or SMTPS) and attaches
 * a sender name + address from the configuration to every mail. Plaintext
 * always; an optional HTML body results in multipart/alternative (Symfony
 * assembles both parts automatically). An optional inline image (header
 * logo) is embedded as a CID attachment via `embedFromPath()` — the HTML
 * part references it as `cid:{name}`, Symfony links the two together when
 * sending (multipart/related).
 * Rethrows Symfony transport exceptions unchanged — no swallowing.
 *
 * The transport is built **lazily** on the first `send()` (memoized), not in
 * the constructor: this way app booting doesn't fail on an empty/invalid
 * SMTP configuration — only an actual send requires valid values. Pure page
 * views (e.g. GET /login) don't need a mailer.
 * Tests instead inject a MailerInterface double and inspect the finished
 * Email (including the embedded logo) without sending for real.
 */
final class SymfonyMailerAdapter implements Mailer
{
    public function __construct(
        private readonly SmtpConfig $smtp,
        private ?MailerInterface $mailer = null,
    ) {}

    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
    ): void {
        $message = (new Email())
            ->from(new Address($this->smtp->fromEmail, $this->smtp->fromName))
            ->to($toEmail)
            ->subject($subject)
            ->text($textBody);

        if ($htmlBody !== null) {
            $message->html($htmlBody);
            // Only meaningful together with an HTML part — plaintext cannot
            // reference an inline image.
            if ($inlineImage instanceof InlineImage) {
                $message->embedFromPath($inlineImage->path, $inlineImage->cid);
            }
        }

        $this->mailer ??= $this->buildMailer();
        $this->mailer->send($message);
    }

    private function buildMailer(): SymfonyMailer
    {
        $scheme = $this->smtp->encryption === 'ssl' ? 'smtps' : 'smtp';
        $auth   = '';
        if ($this->smtp->user !== '') {
            $auth = rawurlencode($this->smtp->user);
            if ($this->smtp->pass !== '') {
                $auth .= ':' . rawurlencode($this->smtp->pass);
            }

            $auth .= '@';
        }

        $dsn = "{$scheme}://{$auth}{$this->smtp->host}:{$this->smtp->port}";

        // Shared hosting with a wildcard cert (CN mismatch): TLS stays on, CN check off.
        if (!$this->smtp->verifyPeer) {
            $dsn .= '?verify_peer=0';
        }

        return new SymfonyMailer(Transport::fromDsn($dsn));
    }
}
