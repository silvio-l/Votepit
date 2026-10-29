<?php

declare(strict_types=1);

/**
 * SMTP smoke test — proves that Votepit mail sending works with the given
 * SMTP credentials. Provider-neutral (Outlook, Gmail, hoster, Mailpit …) —
 * it uses exactly the same mailer code as magic-link sending
 * (Votepit\Mail\SymfonyMailerAdapter), so success here is real proof of
 * the production path.
 *
 * Configuration comes from environment variables (see config/smtp-test.env.example):
 *   SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_ENCRYPTION,
 *   SMTP_FROM_EMAIL, SMTP_FROM_NAME
 *
 * Usage:
 *   set -a; source config/smtp-test.env; set +a
 *   php bin/send-test-mail.php recipient@example.com
 */

require __DIR__ . '/../vendor/autoload.php';

use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

$to = $argv[1] ?? '';
if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/send-test-mail.php <recipient@example.com>\n");
    exit(2);
}

// Unset or empty environment variables fall back to the default.
$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
};

$smtp = SmtpConfig::fromArray([
    'host'       => $env('SMTP_HOST', ''),
    'port'       => (int) $env('SMTP_PORT', '587'),
    'user'       => $env('SMTP_USER', ''),
    'pass'       => $env('SMTP_PASS', ''),
    'encryption' => getenv('SMTP_ENCRYPTION') !== false ? getenv('SMTP_ENCRYPTION') : 'tls',
    'from_email' => $env('SMTP_FROM_EMAIL', 'noreply@example.com'),
    'from_name'  => $env('SMTP_FROM_NAME', 'Votepit'),
]);

printf(
    "→ Sending test mail via %s:%d (encryption=%s, user=%s) to %s …\n",
    $smtp->host,
    $smtp->port,
    $smtp->encryption === '' ? 'none' : $smtp->encryption,
    $smtp->user === '' ? '(no auth)' : $smtp->user,
    $to,
);

try {
    (new SymfonyMailerAdapter($smtp))->send(
        $to,
        'Votepit — SMTP test ✓',
        "This test mail proves that Votepit magic-link sending works.\n"
        . 'Sent: ' . date('Y-m-d H:i:s') . "\n",
    );
    echo "✓ Sending succeeded — mail was accepted by the SMTP server.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Sending failed: " . $e->getMessage() . "\n");
    exit(1);
}
