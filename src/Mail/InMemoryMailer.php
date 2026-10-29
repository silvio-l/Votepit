<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * In-memory mailer for tests.
 *
 * Stores all sent messages in memory. No SMTP, no network. Tests inspect
 * `$sent` directly to check recipient, subject, and body — without actually
 * sending email.
 */
final class InMemoryMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string, html: string|null, image: InlineImage|null, from_name: string|null, headers: array<string, string>}> */
    public array $sent = [];

    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
        ?string $fromName = null,
        array $headers = [],
    ): void {
        $this->sent[] = [
            'to'      => $toEmail,
            'subject' => $subject,
            'body'    => $textBody,
            'html'    => $htmlBody,
            'image'   => $inlineImage,
            'from_name' => $fromName,
            'headers' => $headers,
        ];
    }

    /** @return array{to: string, subject: string, body: string, html: string|null, image: InlineImage|null, from_name: string|null, headers: array<string, string>}|null */
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
