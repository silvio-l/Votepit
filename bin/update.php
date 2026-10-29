#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit Community — self-update from the signed GitHub release.
 *
 *   php bin/update.php --check          # is a newer release available?
 *   php bin/update.php                  # update to the latest release
 *   php bin/update.php --version 0.1.8  # update to a specific release
 *   php bin/update.php --yes            # scripted: skip the backup confirmation
 *
 * The archive is accepted only if its checksum matches AND its minisign
 * signature verifies against one of the two trusted release keys (primary or
 * emergency, see Votepit\Update\TrustedReleaseKeys). If a key is not well-formed
 * the updater refuses to run. Maintenance mode (storage/maintenance.flag)
 * answers 503 during the code swap and the migrations; config/, storage/ and
 * logs/ are never touched by the swap.
 *
 * There is deliberately no backup tooling here (owner decision). The updater
 * only asks you to confirm that you made one yourself.
 *
 * Exit codes: 0 done or nothing to do, 1 refused or failed, 2 usage.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "bin/update.php runs from the command line only.\n";
    exit(1);
}

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run this from an unpacked release.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

use Votepit\Update\CommunityEdition;
use Votepit\Update\CommunityReleaseClient;
use Votepit\Update\CurlReleaseHttp;
use Votepit\Update\InstalledVersion;
use Votepit\Update\SemanticVersion;
use Votepit\Update\TrustedReleaseKeys;
use Votepit\Update\UpdateException;
use Votepit\Update\UpdateRunner;

$usage   = "usage: php bin/update.php [--check] [--version X.Y.Z] [--yes]\n";
$options = ['check' => false, 'yes' => false, 'version' => null];
$argv    = $_SERVER['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = (string) $argv[$i];
    if ($arg === '--check') {
        $options['check'] = true;
    } elseif ($arg === '--yes') {
        $options['yes'] = true;
    } elseif ($arg === '--version' && isset($argv[$i + 1])) {
        $options['version'] = (string) $argv[++$i];
    } elseif (str_starts_with($arg, '--version=')) {
        $options['version'] = substr($arg, strlen('--version='));
    } else {
        fwrite(STDERR, $usage);
        exit(2);
    }
}

if ($options['version'] !== null && !SemanticVersion::isValid($options['version'])) {
    fwrite(STDERR, "--version must be MAJOR.MINOR.PATCH, for example 0.1.8.\n");
    exit(2);
}

$log = static function (string $line): void {
    echo $line . "\n";
};

try {
    $installed = InstalledVersion::fromRoot($root);
    $unusable  = TrustedReleaseKeys::unusable();
    if ($unusable !== []) {
        // Checked up front so the message is about keys, not about the network.
        throw new UpdateException(
            'The release signing keys are not configured yet (' . implode(', ', $unusable)
            . '). The updater refuses to run until the owner has generated and installed the keys.',
        );
    }

    $userAgent = 'Votepit-Community/' . $installed;
    $http      = new CurlReleaseHttp($userAgent);
    $releases  = new CommunityReleaseClient($http, $userAgent);

    $confirmBackup = static function () use ($options): bool {
        if ($options['yes']) {
            return true;
        }
        echo "Votepit does not back up your data. Make a backup of the database and of the files\n";
        echo "(storage/ and config/) with your own tooling before you continue.\n";
        echo "Type 'yes' once the backup is done: ";
        $answer = fgets(STDIN);

        return is_string($answer) && trim($answer) === 'yes';
    };

    $runMigrations = static function (string $tree): bool {
        $code = 1;
        passthru(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tree . '/bin/migrate.php') . ' --yes',
            $code,
        );

        return $code === 0;
    };

    $runner = new UpdateRunner(
        $root,
        $installed,
        $releases,
        $http,
        TrustedReleaseKeys::all(),
        $confirmBackup,
        $runMigrations,
        $log,
    );

    $status = $runner->run($options['version'], $options['check']);
} catch (UpdateException $e) {
    fwrite(STDERR, 'Update refused: ' . $e->getMessage() . "\n");
    exit(1);
}

switch ($status) {
    case UpdateRunner::STATUS_UP_TO_DATE:
        echo "Already up to date ({$installed}).\n";
        break;
    case UpdateRunner::STATUS_AVAILABLE:
        echo 'A newer release is available: ' . ($options['version'] ?? 'latest') . " (installed {$installed}). Run without --check to update.\n";
        break;
    case UpdateRunner::STATUS_ABORTED:
        echo "Aborted. Nothing was changed.\n";
        break;
    case UpdateRunner::STATUS_UPDATED:
        echo "Done. Check the site, then read the release notes: " . CommunityEdition::UPGRADE_DOCS_URL . "\n";
        break;
}

exit(0);
