<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;
use Votepit\Config;

/**
 * Extension for migrations that need access to the app config (e.g.
 * secrets) in addition to the connection — e.g. to encrypt app_key-derived
 * values during a data migration (EncryptionService).
 *
 * Deliberately NOT implemented by SqlFileMigration (pure DDL files don't
 * need config). The first actual user follows later — this only lays down
 * the contract.
 */
interface ConfigAwareMigration extends Migration
{
    public function upWithConfig(Connection $conn, Config $config): void;
}
