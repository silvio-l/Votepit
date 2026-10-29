#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — DEV-ONLY demo data seed.
 *
 * Refuses to run unless config/config.php marks a local development
 * installation (env "dev", loopback database, self-host routing). Idempotent:
 * a second run changes nothing. Create the database first with
 * bin/dev-setup.sh.
 *
 * Usage: php bin/dev-seed.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Dev\DemoSeeder;
use Votepit\Dev\DevSeedRefusedException;
use Votepit\Persistence\ConnectionFactory;

$root       = dirname(__DIR__);
$configPath = $root . '/config/config.php';
$secretPath = $root . '/config/dev-seed.env';

if (!is_file($configPath)) {
    fwrite(STDERR, "dev-seed: config/config.php is missing. Run bin/dev-setup.sh first.\n");
    exit(1);
}

$secrets = is_file($secretPath) ? parse_ini_file($secretPath) : false;
if (!is_array($secrets) || !isset($secrets['OWNER_PASSWORD']) || !is_string($secrets['OWNER_PASSWORD'])) {
    fwrite(STDERR, "dev-seed: config/dev-seed.env with OWNER_PASSWORD is missing. Run bin/dev-setup.sh first.\n");
    exit(1);
}

try {
    $config = Config::fromArray(require $configPath);
    $conn   = ConnectionFactory::create($config);
    $lines  = (new DemoSeeder($config, $conn))->seed($secrets['OWNER_PASSWORD']);
} catch (DevSeedRefusedException $e) {
    fwrite(STDERR, 'dev-seed refused: ' . $e->getMessage() . "\n");
    exit(2);
} catch (ConfigException $e) {
    fwrite(STDERR, 'dev-seed: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

foreach ($lines as $line) {
    echo $line . "\n";
}
