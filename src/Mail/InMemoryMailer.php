<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * In-Memory-Mailer für Tests (Sprint 2).
 *
 * Speichert alle versendeten Nachrichten im Arbeitsspeicher. Kein SMTP,
 * kein Netzwerk. Tests inspizieren `$sent` direkt, um Empfänger, Betreff
 * und Body zu prüfen — ohne echten E-Mail-Versand.
 */
final class InMemoryMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
    }

    /** @return array{to: string, subject: string, body: string}|null */
    public function lastSent(): ?array
    {
        if ($this->sent === []) {
            return null;
        }

        return $this->sent[count($this->sent) - 1];
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
