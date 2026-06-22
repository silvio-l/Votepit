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

    // SMTP für Magic-Link-Versand (z. B. your mail provider).
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
        'magiclink:email' => ['limit' => 3,  'window' => 3600],      // 3/Stunde pro E-Mail
        'magiclink:ip'    => ['limit' => 5,  'window' => 3600],      // 5/Stunde pro IP
        'submit:user'     => ['limit' => 5,  'window' => 3600],      // 5 Ideen/Stunde
        'comment:user'    => ['limit' => 10, 'window' => 3600],      // 10 Kommentare/Stunde
        'dupsearch:user'  => ['limit' => 30, 'window' => 60],        // 30/Minute Duplikat-Suche
        'vote:user'       => ['limit' => 60, 'window' => 60],        // 60 Votes/Minute
    ],
];
