<?php

declare(strict_types=1);

/**
 * Votepit — Front-Controller.
 *
 * `public/` ist der Webroot. Anwendungscode (src/) und Config liegen außerhalb;
 * auf Shared-Hosting ohne frei wählbaren Webroot schützt die .htaccess die
 * sensiblen Pfade (siehe README).
 *
 * Slim-4-App + PSR-15-Middleware-Pipeline werden in Votepit\Http\AppFactory
 * aufgebaut. Routing/Auth/Boards folgen in den Folge-Sprints (siehe .scratch/).
 */

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Votepit ist noch nicht konfiguriert. config/config.example.php nach config/config.php kopieren und ausfüllen.\n";
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = \Votepit\Config::fromArray(require $configPath);
} catch (\Votepit\ConfigException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Votepit: Konfiguration ungültig (" . $e->getMessage() . ").\n";
    exit;
}

\Votepit\Http\AppFactory::create($config)->run();
