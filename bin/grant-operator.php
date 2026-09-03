#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — grants users.is_operator = 1 to one account, identified by
 * email.
 *
 * ADR 0002 (email pseudonymization): no plaintext email is ever persisted,
 * only email_hmac = HMAC-SHA256(email, identity_server_key). This script
 * runs the same hashing UserRepository/IdentityHasher use elsewhere to find
 * the matching row — there is deliberately no promoteOperator() method on
 * UserRepository (see its class doc): is_operator is one tier above
 * is_admin and NOT self-promotable, only settable this way, run by whoever
 * holds server access.
 *
 * Dry-run by default: prints the found user's current is_admin/is_operator
 * state and exits without writing. Pass --yes to actually apply the UPDATE.
 * Never logs/prints the plaintext email or the HMAC.
 *
 * Usage:
 *   php bin/grant-operator.php user@example.com          # dry-run
 *   php bin/grant-operator.php user@example.com --yes     # apply
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Votepit is not configured yet. Copy config/config.example.php to config/config.php and fill it in.\n");
    exit(1);
}

$email = $argv[1] ?? '';
$apply = in_array('--yes', $argv, true);

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/grant-operator.php <user@example.com> [--yes]\n");
    exit(2);
}

try {
    $config = \Votepit\Config::fromArray(require $configPath);
    $conn   = \Votepit\Persistence\ConnectionFactory::create($config);
} catch (\Votepit\ConfigException $e) {
    fwrite(STDERR, 'Votepit: invalid configuration (' . $e->getMessage() . ").\n");
    exit(1);
}

$hasher = new \Votepit\Security\IdentityHasher($config->identityServerKey);
$users  = new \Votepit\Persistence\UserRepository($conn);

$user = $users->findByEmailHmac($hasher->hash($email));

if ($user === null) {
    fwrite(STDERR, "grant-operator: no user found for this email.\n");
    exit(1);
}

printf(
    "grant-operator: found user id=%d is_admin=%d is_operator=%d verified_at=%s\n",
    (int) $user['id'],
    (int) $user['is_admin'],
    (int) $user['is_operator'],
    $user['verified_at'] ?? 'NULL',
);

if ((int) $user['is_operator'] === 1) {
    echo "grant-operator: already is_operator=1, nothing to do.\n";
    exit(0);
}

if (!$apply) {
    echo "grant-operator: dry-run only, re-run with --yes to apply.\n";
    exit(0);
}

$conn->executeStatement('UPDATE users SET is_operator = 1 WHERE id = :id', ['id' => (int) $user['id']]);

echo "grant-operator: done — is_operator = 1 set.\n";
