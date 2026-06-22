<?php

declare(strict_types=1);

/**
 * Votepit — Front-Controller.
 *
 * `public/` ist der Webroot. Anwendungscode (src/) und Config liegen
 * außerhalb davon; auf Shared-Hosting ohne frei wählbaren Webroot schützt
 * die mitgelieferte .htaccess die sensiblen Pfade (siehe README).
 *
 * Routing, Auth und Boards folgen im Security-Foundation-Sprint: Slim 4
 * App, PSR-15-Middleware-Pipeline, Twig-View. Siehe .scratch/.
 */

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Votepit ist noch nicht konfiguriert. config/config.example.php nach config/config.php kopieren und ausfüllen.\n";
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require $configPath;

// TODO(security-foundation): Slim-App + Middleware-Pipeline + Twig-View initialisieren.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "Votepit — Setup ok. Routing folgt im Security-Foundation-Sprint.\n";
