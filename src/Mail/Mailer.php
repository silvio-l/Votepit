<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Mailer seam (arch.md §5).
 *
 * Slim interface: the production impl uses Symfony Mailer over SMTP/TLS;
 * tests inject InMemoryMailer (no actual sending). Plaintext is mandatory;
 * optionally every mail additionally carries an HTML body
 * (multipart/alternative, rendered via MailTemplate — purely
 * server-authorized content, never user/tenant input). The HTML body
 * optionally comes with an inline image (CID embedding, e.g. the header
 * logo) that the adapter embeds when sending. Plaintext tokens must NOT
 * appear in the body (logging risk); the body contains only the fully
 * assembled link (URL).
 *
 * `$fromName` is an optional sender DISPLAY NAME (branded board mails, see
 * MailSenderName). Implementations use it only when it passes
 * MailSenderName::isSafe(); the envelope address is never overridable.
 *
 * `$headers` are optional extra mail headers, restricted to the one-click
 * unsubscribe pair (RFC 8058: `List-Unsubscribe`, `List-Unsubscribe-Post`)
 * that participant mails carry — see SymfonyMailerAdapter::ALLOWED_HEADERS.
 * Empty by default, so every existing caller is unaffected.
 */
interface Mailer
{
    /** @param array<string, string> $headers */
    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
        ?string $fromName = null,
        array $headers = [],
    ): void;
}
