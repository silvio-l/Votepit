<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Votepit\Config;

/**
 * Builds a Doctrine DBAL connection (utf8mb4, exception mode, prepared
 * statements via QueryBuilder/parameter binding).
 *
 * The connection is created once per request. In a test context,
 * an alternative config (test DB) can be passed in.
 */
final class ConnectionFactory
{
    /** @throws Exception */
    public static function create(Config $config): Connection
    {
        return self::createForDb($config->db);
    }

    /**
     * Same connection recipe as create(), but parameterized directly on a
     * DbConfig instead of a full Config — used by
     * bin/verify-backup-restore.php, which connects to a
     * throwaway restore-target database that is deliberately NOT the
     * connection described by the app's own config/config.php.
     *
     * @throws Exception
     */
    public static function createForDb(\Votepit\DbConfig $db): Connection
    {
        return DriverManager::getConnection([
            'dbname'          => $db->name,
            'user'            => $db->user,
            'password'        => $db->pass,
            'host'            => $db->host,
            'port'            => $db->port,
            'driver'          => 'pdo_mysql',
            'charset'         => $db->charset,
            'driverOptions'   => [
                // Strict error mode, emulated prepared statements off.
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        ]);
    }
}
