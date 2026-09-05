#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Votepit — grants users.is_support = 1 to one account, identified by
 * email. The trusted-helper tier below is_operator (customer support:
 * tickets, FAQ — see AuthZMiddleware::support() class doc). Unlike
 * is_operator, more than one user may hold this at a time; there is no
 * DB-level singleton constraint for it.
 *
 * ADR 0002 (email pseudonymization): no plaintext email is ever persisted,
 * only email_hmac = HMAC-SHA256(email, identity_server_key). This script
 * runs the same hashing UserRepository/IdentityHasher use elsewhere to find
 * the matching row — same "no HTTP promotion path" posture as
 * grant-operator.php, only a direct DB UPDATE (or this script) sets it.
 *
 * Dry-run by default: prints the found user's current is_admin/is_operator/
 * is_support/2FA state and exits without writing. Pass --yes to actually
 * apply the UPDATE. Never logs/prints the plaintext email or the HMAC.
 *
 * Usage:
 *   php bin/grant-support.php user@example.com          # dry-run
 *   php bin/grant-support.php user@example.com --yes     # apply
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
    fwrite(STDERR, "Usage: php bin/grant-support.php <user@example.com> [--yes]\n");
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
    fwrite(STDERR, "grant-support: no user found for this email.\n");
    exit(1);
}

printf(
    "grant-support: found user id=%d is_admin=%d is_operator=%d is_support=%d totp_enabled=%s verified_at=%s\n",
    (int) $user['id'],
    (int) $user['is_admin'],
    (int) $user['is_operator'],
    (int) $user['is_support'],
    isset($user['totp_enabled_at']) && $user['totp_enabled_at'] !== null ? 'yes' : 'no',
    $user['verified_at'] ?? 'NULL',
);

if ((int) $user['is_support'] === 1) {
    echo "grant-support: already is_support=1, nothing to do.\n";
    exit(0);
}

if (!isset($user['totp_enabled_at']) || $user['totp_enabled_at'] === null) {
    echo "grant-support: WARNING — this user has no TOTP 2FA set up yet. AuthZMiddleware::support()\n"
       . "requires 2FA (mandatory for this tier) and will deny them 403 until they enable it in their\n"
       . "own profile (Security section). Granting anyway is fine, they just can't use it until then.\n";
}

if (!$apply) {
    echo "grant-support: dry-run only, re-run with --yes to apply.\n";
    exit(0);
}

$conn->executeStatement('UPDATE users SET is_support = 1 WHERE id = :id', ['id' => (int) $user['id']]);

echo "grant-support: done — is_support = 1 set.\n";
