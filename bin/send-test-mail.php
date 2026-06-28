<?php

declare(strict_types=1);

/**
 * SMTP-Smoke-Test — beweist, dass der Votepit-Mailversand mit den gegebenen
 * SMTP-Zugangsdaten funktioniert. Provider-neutral (Outlook, Gmail, Hoster,
 * Mailpit …) — es wird genau derselbe Mailer-Code wie im Magic-Link-Versand
 * benutzt (Votepit\Mail\SymfonyMailerAdapter), also ist ein Erfolg hier ein
 * echter Beweis für den Produktiv-Pfad.
 *
 * Konfiguration kommt aus Umgebungsvariablen (siehe config/smtp-test.env.example):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME
 *
 * Aufruf:
 *   set -a; source config/smtp-test.env; set +a
 *   php bin/send-test-mail.php empfaenger@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

$to = $argv[1] ?? '';
if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/send-test-mail.php <empfaenger@example.com>\n");
    exit(2);
}

$smtp = SmtpConfig::fromArray([
    'host'       => getenv('SMTP_HOST') ?: '',
    'port'       => (int) (getenv('SMTP_PORT') ?: 587),
    'user'       => getenv('SMTP_USER') ?: '',
    'pass'       => getenv('SMTP_PASS') ?: '',
    'encryption' => getenv('SMTP_ENCRYPTION') !== false ? getenv('SMTP_ENCRYPTION') : 'tls',
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'noreply@example.com',
    'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Votepit',
]);

printf(
    "→ Sende Testmail über %s:%d (encryption=%s, user=%s) an %s …\n",
    $smtp->host,
    $smtp->port,
    $smtp->encryption === '' ? 'none' : $smtp->encryption,
    $smtp->user === '' ? '(keine Auth)' : $smtp->user,
    $to,
);

try {
    (new SymfonyMailerAdapter($smtp))->send(
        $to,
        'Votepit — SMTP-Test ✓',
        "Diese Testmail beweist, dass der Votepit-Magic-Link-Versand funktioniert.\n"
        . 'Gesendet: ' . date('Y-m-d H:i:s') . "\n",
    );
    echo "✓ Versand erfolgreich — Mail wurde vom SMTP-Server angenommen.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Versand fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}
