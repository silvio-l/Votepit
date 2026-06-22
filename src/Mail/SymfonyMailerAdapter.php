<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Votepit\SmtpConfig;

/**
 * Symfony-Mailer-Adapter (Sprint 2, arch.md §5).
 *
 * Baut den Symfony Mailer aus SmtpConfig (SMTP/TLS oder SMTPS) und versieht
 * jede Mail mit Absender-Name + -Adresse aus der Konfiguration. Nur Plaintext.
 * Wirft Symfony-Transport-Exceptions unverändert nach oben — keine Swallowing.
 */
final readonly class SymfonyMailerAdapter implements Mailer
{
    private SymfonyMailer $mailer;

    public function __construct(private SmtpConfig $smtp)
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

        $dsn          = "{$scheme}://{$auth}{$smtp->host}:{$smtp->port}";
        $this->mailer = new SymfonyMailer(Transport::fromDsn($dsn));
    }

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $message = (new Email())
            ->from(new Address($this->smtp->fromEmail, $this->smtp->fromName))
            ->to($toEmail)
            ->subject($subject)
            ->text($textBody);

        $this->mailer->send($message);
    }
}
