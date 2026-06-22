<?php

declare(strict_types=1);

namespace Votepit;

/**
 * Typisierter, validierender Config-Reader (Least Privilege: liefert nur
 * angeforderte Werte; keine globale Mutable-State).
 *
 * Quelle: config/config.php (gitignored, nicht im Repo).
 */
final readonly class Config
{
    /**
     * @param list<string>          $adminEmails
     * @param array<string, mixed>  $rateLimits
     */
    private function __construct(
        public string $env,
        public string $appUrl,
        public string $appKey,
        public DbConfig $db,
        public SmtpConfig $smtp,
        public array $adminEmails,
        public int $sessionLifetime,
        public int $magicLinkTtl,
        public array $rateLimits,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $appKey = trim((string) ($a['app_key'] ?? ''));
        $appUrl = rtrim((string) ($a['app_url'] ?? ''), '/');
        $env    = strtolower((string) ($a['env'] ?? 'prod'));

        if ($appKey === '') {
            throw new ConfigException('config: "app_key" fehlt — erzeugen mit: php -r "echo bin2hex(random_bytes(32));"');
        }
        if ($appUrl === '' || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigException('config: "app_url" fehlt oder ungültig');
        }
        if (!in_array($env, ['prod', 'dev'], true)) {
            throw new ConfigException('config: "env" muss "prod" oder "dev" sein');
        }

        return new self(
            env: $env,
            appUrl: $appUrl,
            appKey: $appKey,
            db: DbConfig::fromArray((array) ($a['db'] ?? [])),
            smtp: SmtpConfig::fromArray((array) ($a['smtp'] ?? [])),
            adminEmails: array_values(array_filter(
                array_map(strtolower(...), (array) ($a['admin_emails'] ?? [])),
                static fn (string $e): bool => $e !== '',
            )),
            sessionLifetime: (int) ($a['session_lifetime'] ?? 60 * 60 * 24 * 30),
            magicLinkTtl: (int) ($a['magic_link_ttl'] ?? 60 * 15),
            rateLimits: (array) ($a['rate_limits'] ?? []),
        );
    }

    public function isAdminEmail(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->adminEmails, true);
    }

    /**
     * Rate-Limit-Config für eine Aktion. Fallback auf ein hartes Default.
     *
     * @return array{limit:int, window:int}
     */
    public function rateLimit(string $action): array
    {
        $cfg = $this->rateLimits[$action] ?? [];
        return [
            'limit'  => (int) ($cfg['limit'] ?? 0),
            'window' => (int) ($cfg['window'] ?? 3600),
        ];
    }
}
