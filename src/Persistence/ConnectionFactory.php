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
     * DbConfig instead of a full Config — lets a caller connect to a
     * database other than the one in config/config.php.
     *
     * @throws Exception
     */
    public static function createForDb(\Votepit\DbConfig $db): Connection
    {
        $driverOptions = [
            // Strict error mode, emulated prepared statements off.
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // TLS for the app<->DB connection (OWASP/BSI audit remediation issue
        // 08). Opt-in via config.db.ssl_ca (a CA bundle path) rather than
        // unconditional: `host` here can legitimately be a bare "localhost"
        // (Unix socket, no network segment to encrypt at all — the common
        // self-host/local-dev case) or a host whose operator hasn't supplied
        // a CA yet. Encryption is only ever enabled together with server
        // certificate verification — never SSL_CA without
        // SSL_VERIFY_SERVER_CERT, which would encrypt the channel without
        // authenticating the peer (silently vulnerable to a
        // network-position MITM despite looking "encrypted").
        if ($db->sslCa !== null) {
            $driverOptions[\PDO::MYSQL_ATTR_SSL_CA]                = $db->sslCa;
            $driverOptions[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return DriverManager::getConnection([
            'dbname'          => $db->name,
            'user'            => $db->user,
            'password'        => $db->pass,
            'host'            => $db->host,
            'port'            => $db->port,
            'driver'          => 'pdo_mysql',
            'charset'         => $db->charset,
            'driverOptions'   => $driverOptions,
        ]);
    }
}
