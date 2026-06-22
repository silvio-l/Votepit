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
    // Öffentliche Basis-URL der Installation (ohne abschließenden Slash).
    'app_url' => 'https://feedback.example.com',

    // Zufälliger Schlüssel zum Signieren von Session-Cookies.
    // Generieren z. B. mit: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => '',

    // Datenbank (MySQL/MariaDB) — ausschließlich Prepared Statements.
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
];
