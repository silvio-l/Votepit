<?php

declare(strict_types=1);

namespace Votepit;

use Votepit\Security\IdentityHasher;

/**
 * Typed, validating config reader (least privilege: only returns
 * requested values; no global mutable state).
 *
 * Source: config/config.php (gitignored, not in the repo).
 *
 * admin_emails is converted to HMACs HERE immediately (ADR 0002) — plaintext
 * emails are not kept in the Config object, see adminEmailHmacs.
 */
final readonly class Config
{
    /**
     * routing_mode: 'self-host' (default) — exactly one account, paths stay
     * `/{board}/...` unchanged (no {account} segment). 'cloud' — multiple
     * accounts, account-/board-scoped routes carry a leading
     * `/{account}` path segment (AccountContextMiddleware resolves it,
     * AppFactory registers the routes prefixed accordingly). Invariant:
     * self-host stays completely unchanged at 'self-host' (default).
     *
     * trust_cloudflare_ip: false (default) — client IP is always REMOTE_ADDR.
     * true — Votepit\Security\ClientIp additionally trusts the
     * `CF-Connecting-IP` header. Only enable this together with an
     * origin-lock (vhost/firewall accepts connections only from Cloudflare's
     * published IP ranges) — otherwise the header is spoofable by anyone
     * reaching the origin directly.
     *
     * sentry_dsn: '' (default) — no error monitoring, self-host default.
     * Set a real Sentry DSN to enable Votepit\Monitoring\SentryErrorReporter
     * (uncaught exceptions get reported in addition to the existing
     * error_log logging; nothing else changes).
     *
     * sentry_dsn_frontend: '' (default) — separate DSN for the SPA's
     * @sentry/react integration (main.tsx). Deliberately a distinct config
     * key from sentry_dsn: a client-side DSN is meant to be public (it's
     * shipped in the built JS bundle and served over /api/bootstrap — Sentry
     * DSNs authorize *sending* events only, not reading account data), while
     * the backend DSN never leaves the server. Using the same value for both
     * would work but conflates two SDKs' event streams under one Sentry
     * project; keeping them separate (or pointing both at the same DSN only
     * if that's genuinely wanted) is an explicit operator choice.
     *
     * extensions: [] (default) — Community Edition runs without any
     * extension. A hosted-service operator can plug in additional code via
     * `'extensions' => [SomeExtension::class => [options]]` (see
     * Votepit\Extension\AppExtension); config/config.php must `require` the
     * extension package's autoloader before returning the array. Core itself
     * never references a concrete extension class.
     *
     * @param list<string>          $adminEmailHmacs
     * @param array<string, mixed>  $rateLimits
     * @param array<string, array<string, mixed>> $extensions
     */
    private function __construct(
        public string $env,
        public string $appUrl,
        public string $appKey,
        public string $identityServerKey,
        public DbConfig $db,
        public SmtpConfig $smtp,
        public array $adminEmailHmacs,
        public int $sessionLifetime,
        public int $magicLinkTtl,
        public array $rateLimits,
        public string $routingMode,
        public ?string $sessionCookieDomain,
        public bool $trustCloudflareIp,
        public string $sentryDsn,
        public string $sentryDsnFrontend,
        public array $extensions,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $appKey             = trim((string) ($a['app_key'] ?? ''));
        $identityServerKey  = trim((string) ($a['identity_server_key'] ?? ''));
        $appUrl             = rtrim((string) ($a['app_url'] ?? ''), '/');
        $env                = strtolower((string) ($a['env'] ?? 'prod'));

        if ($appKey === '') {
            throw new ConfigException('config: "app_key" is missing — generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        if ($identityServerKey === '') {
            throw new ConfigException('config: "identity_server_key" is missing — generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        if ($appUrl === '' || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigException('config: "app_url" is missing or invalid');
        }
        if (!in_array($env, ['prod', 'dev'], true)) {
            throw new ConfigException('config: "env" must be "prod" or "dev"');
        }

        $routingMode = strtolower((string) ($a['routing_mode'] ?? 'self-host'));
        if (!in_array($routingMode, ['self-host', 'cloud'], true)) {
            throw new ConfigException('config: "routing_mode" must be "self-host" or "cloud"');
        }

        $sessionCookieDomain = trim((string) ($a['session_cookie_domain'] ?? ''));

        /** @var array<string, array<string, mixed>> $extensions */
        $extensions = [];
        foreach ((array) ($a['extensions'] ?? []) as $class => $options) {
            if (!is_string($class) || trim($class) === '') {
                throw new ConfigException('config: "extensions" must be an array of class name => options array');
            }
            if (!is_array($options)) {
                throw new ConfigException("config: \"extensions\"[{$class}] must be an options array (empty array if there are no options)");
            }
            /** @var array<string, mixed> $options */
            $extensions[$class] = $options;
        }

        $hasher = new IdentityHasher($identityServerKey);

        $rawAdminEmails = array_values(array_filter(
            array_map(static fn (mixed $e): string => trim((string) $e), (array) ($a['admin_emails'] ?? [])),
            static fn (string $e): bool => $e !== '',
        ));

        return new self(
            env: $env,
            appUrl: $appUrl,
            appKey: $appKey,
            identityServerKey: $identityServerKey,
            db: DbConfig::fromArray((array) ($a['db'] ?? [])),
            smtp: SmtpConfig::fromArray((array) ($a['smtp'] ?? [])),
            adminEmailHmacs: array_map($hasher->hash(...), $rawAdminEmails),
            sessionLifetime: (int) ($a['session_lifetime'] ?? 60 * 60 * 24 * 30),
            magicLinkTtl: (int) ($a['magic_link_ttl'] ?? 60 * 15),
            rateLimits: (array) ($a['rate_limits'] ?? []),
            routingMode: $routingMode,
            sessionCookieDomain: $sessionCookieDomain !== '' ? $sessionCookieDomain : null,
            trustCloudflareIp: (bool) ($a['trust_cloudflare_ip'] ?? false),
            sentryDsn: trim((string) ($a['sentry_dsn'] ?? '')),
            sentryDsnFrontend: trim((string) ($a['sentry_dsn_frontend'] ?? '')),
            extensions: $extensions,
        );
    }

    public function isAdminEmailHmac(string $emailHmac): bool
    {
        foreach ($this->adminEmailHmacs as $candidate) {
            if (hash_equals($candidate, $emailHmac)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rate-limit config for an action. Falls back to a hard default.
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
