<?php

declare(strict_types=1);

namespace Votepit\Dev;

use Votepit\Config;

/**
 * Fail-closed gate for the dev-only demo seed. Every condition must hold;
 * anything else (including an unknown or empty value) refuses the run.
 *
 * Seeding writes fake accounts, boards and votes, so it must never reach a
 * production or staging database: env must be "dev", the database and the
 * public app URL must point at loopback, routing must be self-host, and SMTP
 * must not point at a remote relay (the seed never sends mail, but a remote
 * relay would be a misconfiguration worth refusing for).
 */
final class DevSeedGuard
{
    /** @var list<string> */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** @throws DevSeedRefusedException */
    public static function assertLocalDevelopment(Config $config): void
    {
        if ($config->env !== 'dev') {
            throw new DevSeedRefusedException('config "env" must be "dev" (is "' . $config->env . '").');
        }

        if (!self::isLocal($config->db->host)) {
            throw new DevSeedRefusedException('config db.host must be localhost or 127.0.0.1 (refusing to seed a remote database).');
        }

        $appHost = parse_url($config->appUrl, PHP_URL_HOST);
        if (!is_string($appHost) || !self::isLocal($appHost)) {
            throw new DevSeedRefusedException('config "app_url" must point at localhost (refusing to seed a non-local installation).');
        }

        if ($config->routingMode !== 'self-host') {
            throw new DevSeedRefusedException('config "routing_mode" must be "self-host" for the demo seed.');
        }

        if ($config->smtp->host !== '' && !self::isLocal($config->smtp->host)) {
            throw new DevSeedRefusedException('config smtp.host must be empty or local (the demo seed must not reach a remote mail relay).');
        }
    }

    private static function isLocal(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), self::LOCAL_HOSTS, true);
    }
}
