<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
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
 * An optional sender display name (branded board mails) replaces only the
 * display name, and only when MailSenderName::isSafe() accepts it. The
 * envelope/From address stays the installation's, so SPF/DKIM/DMARC alignment
 * is unchanged.
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
    /**
     * The only extra headers a caller may set (RFC 8058 one-click
     * unsubscribe). Anything else is refused, so a value can never smuggle in
     * another header; a value with CR/LF is refused too (header injection).
     */
    private const ALLOWED_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

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
        ?string $fromName = null,
        array $headers = [],
    ): void {
        foreach ($headers as $name => $value) {
            if (!in_array($name, self::ALLOWED_HEADERS, true) || preg_match('/[\r\n]/', $value) === 1) {
                throw new \InvalidArgumentException('Mail header not allowed: ' . $name);
            }
        }

        // Only a display name that passes MailSenderName::isSafe() is used; anything
        // else (header-injection attempt, impersonation shape) falls back to the
        // installation's own sender name. The envelope address is never changed.
        $displayName = $fromName !== null && MailSenderName::isSafe($fromName)
            ? $fromName
            : $this->smtp->fromName;

        $message = (new Email())
            ->from(new Address($this->smtp->fromEmail, $displayName))
            ->to($toEmail)
            ->subject($subject)
            ->text($textBody);

        foreach ($headers as $name => $value) {
            $message->getHeaders()->addTextHeader($name, $value);
        }

        if ($htmlBody !== null) {
            $message->html($htmlBody);
            // Only meaningful together with an HTML part — plaintext cannot
            // reference an inline image.
            if ($inlineImage instanceof InlineImage) {
                $message->embedFromPath($inlineImage->path, $inlineImage->cid);
            }
        }

        $this->mailer ??= $this->buildMailer();
        $this->mailer->send($this->maybeSign($message));
    }

    /**
     * Signs with DKIM (RFC 6376) when configured — no-op otherwise, so
     * installs without a configured key/selector keep sending exactly as
     * before. The signing domain is always the From-address's own domain
     * (SmtpConfig enforces a valid from_email), which is what DMARC
     * alignment requires.
     */
    private function maybeSign(Email $message): Email|Message
    {
        if ($this->smtp->dkimPrivateKey === '' || $this->smtp->dkimSelector === '') {
            return $message;
        }

        $atPosition = strrchr($this->smtp->fromEmail, '@');
        $domain = $atPosition === false ? '' : substr($atPosition, 1);
        $signer = new DkimSigner($this->smtp->dkimPrivateKey, $domain, $this->smtp->dkimSelector);

        return $signer->sign($message);
    }

    private function buildMailer(): SymfonyMailer
    {
        return new SymfonyMailer(Transport::fromDsn(self::transportDsn($this->smtp)));
    }

    /**
     * SMTP DSN for the configured relay. The EHLO name is the From-address's
     * domain: without it Symfony announces "[127.0.0.1]" whenever SERVER_NAME
     * is unset (CLI: cron digests, nudges, previews), which spam filters score
     * against the sender.
     */
    public static function transportDsn(SmtpConfig $smtp): string
    {
        $scheme = $smtp->encryption === 'ssl' ? 'smtps' : 'smtp';
        $auth   = '';
        if ($smtp->user !== '') {
            $auth = rawurlencode($smtp->user);
            if ($smtp->pass !== '') {
                $auth .= ':' . rawurlencode($smtp->pass);
            }

            $auth .= '@';
        }

        $options    = [];
        $atPosition = strrchr($smtp->fromEmail, '@');
        $fromDomain = $atPosition === false ? '' : substr($atPosition, 1);
        if (preg_match('/^[a-z0-9.-]+$/iD', $fromDomain) === 1) {
            $options['local_domain'] = $fromDomain;
        }

        // Shared hosting with a wildcard cert (CN mismatch): TLS stays on, CN check off.
        if (!$smtp->verifyPeer) {
            $options['verify_peer'] = '0';
        }

        $dsn = "{$scheme}://{$auth}{$smtp->host}:{$smtp->port}";

        return $options === [] ? $dsn : $dsn . '?' . http_build_query($options);
    }
}
