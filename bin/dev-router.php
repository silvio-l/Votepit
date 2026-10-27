<?php

declare(strict_types=1);

/**
 * Router for the built-in PHP server (bin/dev-server.sh). Real files inside
 * the document root (public/) are served as they are; everything else goes
 * through the front controller, the same way public/.htaccess does on Apache.
 */

$docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__) . '/public');
$path    = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$file    = realpath($docroot . '/' . ltrim($path, '/'));

if ($file !== false && str_starts_with($file, realpath($docroot) . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $docroot . '/index.php';
