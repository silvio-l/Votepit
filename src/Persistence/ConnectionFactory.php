<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Votepit\Config;

/**
 * Baut eine Doctrine-DBAL-Connection (utf8mb4, Exception-Mode, Prepared
 * Statements via QueryBuilder/Parameter-Binding).
 *
 * Die Connection wird einmal pro Request erzeugt (Sprint 0). Im Test-Kontext
 * kann eine alternative Config (Test-DB) übergeben werden.
 */
final class ConnectionFactory
{
    /** @throws Exception */
    public static function create(Config $config): Connection
    {
        $db = $config->db;
        return DriverManager::getConnection([
            'dbname'          => $db->name,
            'user'            => $db->user,
            'password'        => $db->pass,
            'host'            => $db->host,
            'port'            => $db->port,
            'driver'          => 'pdo_mysql',
            'charset'         => $db->charset,
            'driverOptions'   => [
                // Strikte Fehlermeldung, emulierte Prepared Statements aus.
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ]);
    }
}
