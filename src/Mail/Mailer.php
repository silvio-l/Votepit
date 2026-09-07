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
 */
interface Mailer
{
    public function send(
        string $toEmail,
        string $subject,
        string $textBody,
        ?string $htmlBody = null,
        ?InlineImage $inlineImage = null,
    ): void;
}
