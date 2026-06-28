<?php

declare(strict_types=1);

/**
 * Votepit — Konfigurationsvorlage.
 *
 * Diese Datei nach `config/config.php` kopieren und ausfüllen.
 * `config/config.php` ist per .gitignore vom Repo ausgeschlossen und darf
 * NIE eingecheckt werden — sie enthält Secrets (DB, SMTP, App-Key).
 */

return [
    // 'prod' für Live-Betrieb, 'dev' für lokale Entwicklung (zeigt Fehler-
    // details, kein HSTS-Secure-Flag, Twig ohne Cache).
    'env' => 'prod',

    // Öffentliche Basis-URL der Installation (ohne abschließenden Slash).
    'app_url' => 'https://feedback.example.com',

    // Zufälliger Schlüssel zum Signieren von Session-Cookies und Magic-Links.
    // Generieren: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => '',

    // Gültigkeitsdauer eines Magic-Links in Sekunden (Default: 15 Minuten).
    'magic_link_ttl' => 60 * 15,

    // Datenbank (MySQL/MariaDB) — ausschließlich Prepared Statements (DBAL).
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'votepit',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // SMTP für Magic-Link-Versand (SMTP-Zugang deines Hosters/Mailproviders).
    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'user'       => '',
        'pass'       => '',
        'encryption' => 'tls', // 'tls' | 'ssl'
        'from_email' => 'noreply@example.com',
        'from_name'  => 'Votepit',
    ],

    // Admin-E-Mail-Adressen. Beim ersten Magic-Link-Login wird is_admin gesetzt.
    'admin_emails' => [
        // 'du@example.com',
    ],

    // Session-Lebensdauer in Sekunden (Default: 30 Tage).
    'session_lifetime' => 60 * 60 * 24 * 30,

    // Rate-Limits (security.md §6). limit=0 deaktiviert eine Aktion.
    'rate_limits' => [
        'global:ip'       => ['limit' => 300, 'window' => 60],       // grob: 300/Minute pro IP (DoS-Bremse)
        'magiclink:email' => ['limit' => 3,  'window' => 3600],      // 3/Stunde pro E-Mail
        'magiclink:ip'    => ['limit' => 5,  'window' => 3600],      // 5/Stunde pro IP
        // Per-Action-Bucket-Konvention: der Config-Schlüssel ist identisch mit dem
        // Action-String, den AppFactory via $config->rateLimit(...) nachschlägt.
        'idea:submit'     => ['limit' => 5,  'window' => 3600],      // 5 Ideen/Stunde
        'idea:vote'       => ['limit' => 60, 'window' => 60],        // 60 Votes/Minute
        'comment:user'    => ['limit' => 10, 'window' => 3600],      // 10 Kommentare/Stunde (Sprint 6)
        'dupsearch:user'  => ['limit' => 30, 'window' => 60],        // 30/Minute Duplikat-Suche (Sprint 5)
        'smtp:test'       => ['limit' => 5,  'window' => 300],       // 5 Test-Mails / 5 Minuten
    ],
];
