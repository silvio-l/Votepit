<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * Mailer-Seam (Sprint 2, arch.md §5).
 *
 * Schlankes Interface: Produktions-Impl nutzt Symfony Mailer über SMTP/TLS;
 * Tests injizieren InMemoryMailer (kein echter Versand). Nur Plaintext-Mail —
 * kein HTML. Token-Klartext darf NICHT im Body erscheinen (Logging-Gefahr);
 * der Body enthält nur den fertig zusammengesetzten Link (URL).
 */
interface Mailer
{
    public function send(string $toEmail, string $subject, string $textBody): void;
}
