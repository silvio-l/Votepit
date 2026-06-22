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
 *
 * Der Transport wird **lazy** beim ersten `send()` gebaut (memoisiert), nicht
 * im Konstruktor: So scheitert das App-Booten nicht an einer leeren/ungültigen
 * SMTP-Konfiguration — nur ein tatsächlicher Versand verlangt gültige Werte.
 * Reine Seitenansichten (z. B. GET /login) brauchen keinen Mailer.
 */
final class SymfonyMailerAdapter implements Mailer
{
    private ?SymfonyMailer $mailer = null;

    public function __construct(private readonly SmtpConfig $smtp) {}

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $message = (new Email())
            ->from(new Address($this->smtp->fromEmail, $this->smtp->fromName))
            ->to($toEmail)
            ->subject($subject)
            ->text($textBody);

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

        return new SymfonyMailer(Transport::fromDsn($dsn));
    }
}
