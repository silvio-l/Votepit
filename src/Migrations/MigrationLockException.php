<?php

declare(strict_types=1);

namespace Votepit\Migrations;

/**
 * Thrown by MigrationRunner::migrate() when the MySQL advisory lock cannot be
 * acquired — another migration run holds it (or the lock call errored).
 * Nothing has been applied when this is thrown.
 */
final class MigrationLockException extends \RuntimeException {}
