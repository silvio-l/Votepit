<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;

/**
 * A single, versioned schema migration (forward-only).
 *
 * version() uniquely identifies the migration (typically the filename
 * without extension, e.g. "0000_baseline") and is stored 1:1 as the
 * primary key in schema_migrations.
 */
interface Migration
{
    public function version(): string;

    public function up(Connection $conn): void;
}
