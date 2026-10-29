<?php

declare(strict_types=1);

namespace Votepit\Update;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Engine and version label for the version report, e.g. "mariadb 10.11.6" or
 * "mysql 8.0.36". Never throws: anything unexpected becomes "unknown".
 */
final class DatabaseVersion
{
    public static function describe(Connection $conn): string
    {
        if (!$conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return 'unknown';
        }

        try {
            $raw = $conn->fetchOne('SELECT VERSION()');
        } catch (DbalException) {
            return 'unknown';
        }

        if (!is_string($raw) || preg_match('/^(\d+\.\d+\.\d+)([-\w.]*)$/', $raw, $m) !== 1) {
            return 'unknown';
        }

        $engine = stripos($m[2], 'mariadb') !== false ? 'mariadb' : 'mysql';

        return $engine . ' ' . $m[1];
    }
}
