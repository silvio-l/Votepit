<?php

declare(strict_types=1);

namespace Votepit\Backup;

/**
 * Thrown by DatabaseBackup/RestoreRehearsal on any failure (missing binary,
 * non-zero exit code, safety-check violation). Deliberately a plain,
 * catchable exception type — bin/*.php scripts catch it at the top level and
 * translate it into a STDERR message + non-zero exit code, same pattern as
 * Votepit\ConfigException in bin/migrate.php.
 */
final class BackupException extends \RuntimeException {}
