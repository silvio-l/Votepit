<?php

declare(strict_types=1);

namespace Votepit;

use Votepit\Security\IdentityHasher;
use Votepit\Security\WidgetOriginValidator;

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
     * matomo_url / matomo_site_id: '' (default) — this installation's OWN
     * optional analytics, entirely separate from the product-improvement
     * telemetry below. An operator (self-host or the Cloud instance itself)
     * points these at their OWN Matomo to measure their OWN visitors; the SPA
     * loads the Matomo tracker cookielessly (disableCookies) only when both
     * are non-empty. Same "public, authorizes sending only" reasoning as
     * sentry_dsn_frontend — safe to expose via /api/bootstrap.
     *
     * Product-improvement telemetry (self-host only, see
     * Votepit\Telemetry\CommunityTelemetry): a fixed, hardcoded target
     * (Votepit's own aggregate Matomo site for the Community Edition),
     * intentionally NOT operator-configurable here — it is gated purely by
     * the per-account `telemetry_opted_in` consent flag (Setup Wizard),
     * never by config.php. Cloud never uses this path; it isn't a
     * third-party self-host reporting home, it's this repo reporting
     * anonymized, aggregate Community usage back to its own maintainer, and
     * only after explicit, equally-easy-to-decline opt-in.
     *
     * oauth_providers: [] (default) — OAuth2 login (Google/GitHub), additive
     * to magic-link/password/TOTP, never a replacement. Shape:
     * `['google' => ['client_id' => ..., 'client_secret' => ...], 'github' => [...]]`.
     * A provider missing from this array, or present with an empty
     * client_id/client_secret, is simply NOT configured — oauthProviderConfigured()
     * returns false, GET /login/oauth/{provider}/start 404s (fail-secure,
     * never throws), and /api/bootstrap omits it from `oauth_providers` so the
     * SPA renders no button for it. Only 'google'/'github' are ever
     * recognized (OAuthProviderRegistry allowlist) — an unknown key here is
     * silently ignored, never treated as configured.
     *
     * translation: provider '' (default) = disabled — fail-secure, the app
     * wires in Votepit\Translation\NullTranslationProvider whenever
     * translationConfigured() is false (fresh checkout, no config change at
     * all). Only 'google' is ever recognized. Shape:
     * `['provider' => 'google', 'google' => ['project_id' => ..., 'service_account_key' => '/abs/path/key.json', 'region' => 'eu']]`.
     * service_account_key is an ABSOLUTE path to a GCP service-account JSON
     * key file kept outside the repo/webroot, like every other secret here
     * — never the key material itself inline in config. region defaults to
     * 'eu' (Cloud Translation Advanced v3's EU multi-regional endpoint,
     * the only residency this codebase's Google integration ever targets —
     * a deliberate data-residency choice).
     *
     * @param list<string>          $adminEmailHmacs
     * @param array<string, mixed>  $rateLimits
     * @param array<string, mixed>  $moderationEscalation
     * @param array<string, mixed>  $avatarUpload
     * @param array<string, mixed>  $turnstile
     * @param array<string, array<string, mixed>> $extensions
     * @param array<string, array{client_id: string, client_secret: string}> $oauthProviders
     * @param array{provider: string, google: array{project_id: string, service_account_key: string, region: string}} $translation
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
        public int $sessionInactivityWindow,
        public int $magicLinkTtl,
        public array $rateLimits,
        public array $moderationEscalation,
        public array $avatarUpload,
        public array $turnstile,
        public string $routingMode,
        public ?string $sessionCookieDomain,
        public bool $trustCloudflareIp,
        public string $sentryDsn,
        public string $sentryDsnFrontend,
        public string $matomoUrl,
        public string $matomoSiteId,
        public array $extensions,
        public array $oauthProviders,
        public array $translation,
        // GET /ready: maximum age of the cron manager's start heartbeat
        // ('cron-manager', written at the start of every tick of
        // bin/votepit-cron.php) before the installation reports "not ready".
        // The manager ticks every minute; the default leaves room for jitter.
        public int $readinessCronMaxAgeMinutes,
        // Installation-wide kill switch for guest idea submission (ADR 0006),
        // above every board flag and plan. Defaults: on for self-host, OFF for
        // routing_mode 'cloud' — a hosted installation must opt in explicitly
        // (`guest_submission_enabled => true`), so a missing key fails secure.
        public bool $guestSubmissionEnabled,
        // GET /api/v1/session/badge: exact origins allowed to read the
        // signed-in badge cross-origin WITH credentials. [] (default) =
        // CORS off for everyone (self-host). See config.example.php.
        /** @var list<string> */
        public array $corsBadgeOrigins = [],
        // Product-news / promotion consent (migration 0112, W1): the signup checkbox,
        // the profile toggle and the consent endpoints exist only when a hosted
        // installation enables marketing (`marketing_consent => true`). Default off:
        // a self-hosted installation never shows or stores any of it.
        public bool $marketingConsentEnabled = false,
        // Installation-wide kill switches for released areas (release freeze).
        // Each defaults to true, so an absent key keeps today's behaviour; the
        // operator turns one off here at any time. See documentation/configuration.md.
        // Board logo/cover uploads: refused, and uploaded images are not served.
        public bool $boardUploadsEnabled = true,
        // Participant mails (instant, digest, new-ideas, operator and plan-limit
        // nudges): nothing is sent or queued while off.
        public bool $participantMailsEnabled = true,
        // Reasons for votes: the per-board toggle cannot be switched on and
        // reasons are refused while off.
        public bool $voteReasonsEnabled = true,
        // Community product telemetry target (Matomo). '' (default) = off: nothing
        // is ever sent, the consent step is not shown and the CSP gets no tracker
        // origin. Both must be set for telemetry to be possible at all. See
        // documentation/installation.md#product-telemetry.
        public string $telemetryMatomoUrl = '',
        public string $telemetryMatomoSiteId = '',
        // Community update check (daily lookup of the latest public release on
        // GitHub) and version report (daily anonymous version event to Votepit's
        // own Matomo). Both default ON, both only ever run on routing_mode
        // 'self-host' (the cron job is registered only there, see
        // Votepit\Cron\CronJobRegistry and bin/community-update-check.php).
        // Off ⇒ nothing leaves the installation. See documentation/configuration.md.
        public bool $updateCheckEnabled = true,
        public bool $versionReportEnabled = true,
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

        $updateCheck   = $a['update_check'] ?? true;
        $versionReport = $a['version_report'] ?? true;
        if (!is_bool($updateCheck)) {
            throw new ConfigException('config: "update_check" must be true or false');
        }
        if (!is_bool($versionReport)) {
            throw new ConfigException('config: "version_report" must be true or false');
        }

        $guestSubmissionEnabled = $a['guest_submission_enabled'] ?? ($routingMode === 'self-host');
        if (!is_bool($guestSubmissionEnabled)) {
            throw new ConfigException('config: "guest_submission_enabled" must be true or false');
        }

        // Kill switches (see the constructor): true unless explicitly set to false.
        $killSwitches = [];
        foreach (['board_uploads', 'participant_mails', 'vote_reasons'] as $key) {
            $value = $a[$key] ?? true;
            if (!is_bool($value)) {
                throw new ConfigException("config: \"{$key}\" must be true or false");
            }
            $killSwitches[$key] = $value;
        }

        $readinessCronMaxAge = $a['readiness_cron_max_age_minutes'] ?? 5;
        if (!is_int($readinessCronMaxAge) || $readinessCronMaxAge < 1) {
            throw new ConfigException('config: "readiness_cron_max_age_minutes" must be a positive integer');
        }

        /** @var array<string, array<string, mixed>> $extensions */
        $extensions = [];
        foreach ((array) ($a['extensions'] ?? []) as $class => $options) {
            if (!is_string($class) || trim($class) === '') {
                throw new ConfigException('config: "extensions" must be an array of class name => options array');
            }
            if (!is_array($options)) {
                throw new ConfigException("config: \"extensions\"[{$class}] must be an options array (empty array if there are no options)");
            }
            // Normalize to the SomeExtension::class form ('\\Vendor\\...' is what a
            // var_export()-rewritten config holds) — consumers look keys up by
            // ::class, so a leading backslash silently made them "not configured".
            $class = ltrim($class, '\\');
            if (array_key_exists($class, $extensions)) {
                throw new ConfigException("config: \"extensions\"[{$class}] is listed more than once");
            }
            /** @var array<string, mixed> $options */
            $extensions[$class] = $options;
        }

        /** @var array<string, array{client_id: string, client_secret: string}> $oauthProviders */
        $oauthProviders = [];
        foreach ((array) ($a['oauth_providers'] ?? []) as $provider => $cfg) {
            if (!is_string($provider) || !is_array($cfg)) {
                continue;
            }
            $oauthProviders[$provider] = [
                'client_id'     => trim((string) ($cfg['client_id'] ?? '')),
                'client_secret' => trim((string) ($cfg['client_secret'] ?? '')),
            ];
        }

        /** @var array<string, mixed> $rawTranslation */
        $rawTranslation = (array) ($a['translation'] ?? []);
        /** @var array<string, mixed> $rawTranslationGoogle */
        $rawTranslationGoogle = (array) ($rawTranslation['google'] ?? []);
        $translation = [
            'provider' => trim((string) ($rawTranslation['provider'] ?? '')),
            'google'   => [
                'project_id'          => trim((string) ($rawTranslationGoogle['project_id'] ?? '')),
                'service_account_key' => trim((string) ($rawTranslationGoogle['service_account_key'] ?? '')),
                'region'              => trim((string) ($rawTranslationGoogle['region'] ?? 'eu')),
            ],
        ];

        // Cross-origin badge reads (with credentials): every entry must be one
        // plain https origin (WidgetOriginValidator: scheme://host[:port], no
        // path, no wildcard, no trailing slash). Anything else fails boot
        // instead of being silently dropped — a typo must not open or close
        // the allowlist unnoticed.
        $corsBadgeOrigins = [];
        foreach ((array) ($a['cors_badge_origins'] ?? []) as $origin) {
            if (!is_string($origin) || !WidgetOriginValidator::isValid($origin)) {
                throw new ConfigException('config: "cors_badge_origins" must be a list of exact https origins (scheme://host[:port], lower-case, no path, no wildcard)');
            }
            $corsBadgeOrigins[] = $origin;
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
            sessionInactivityWindow: (int) ($a['session_inactivity_window'] ?? 60 * 60 * 24 * 14),
            magicLinkTtl: (int) ($a['magic_link_ttl'] ?? 60 * 15),
            rateLimits: (array) ($a['rate_limits'] ?? []),
            moderationEscalation: (array) ($a['moderation_escalation'] ?? []),
            avatarUpload: (array) ($a['avatar_upload'] ?? []),
            turnstile: (array) ($a['turnstile'] ?? []),
            routingMode: $routingMode,
            sessionCookieDomain: $sessionCookieDomain !== '' ? $sessionCookieDomain : null,
            trustCloudflareIp: (bool) ($a['trust_cloudflare_ip'] ?? false),
            sentryDsn: trim((string) ($a['sentry_dsn'] ?? '')),
            sentryDsnFrontend: trim((string) ($a['sentry_dsn_frontend'] ?? '')),
            matomoUrl: rtrim(trim((string) ($a['matomo_url'] ?? '')), '/'),
            matomoSiteId: trim((string) ($a['matomo_site_id'] ?? '')),
            extensions: $extensions,
            oauthProviders: $oauthProviders,
            translation: $translation,
            readinessCronMaxAgeMinutes: $readinessCronMaxAge,
            guestSubmissionEnabled: $guestSubmissionEnabled,
            corsBadgeOrigins: array_values(array_unique($corsBadgeOrigins)),
            marketingConsentEnabled: (bool) ($a['marketing_consent'] ?? false),
            boardUploadsEnabled: $killSwitches['board_uploads'],
            participantMailsEnabled: $killSwitches['participant_mails'],
            voteReasonsEnabled: $killSwitches['vote_reasons'],
            telemetryMatomoUrl: rtrim(trim((string) ($a['telemetry_matomo_url'] ?? '')), '/'),
            telemetryMatomoSiteId: trim((string) ($a['telemetry_matomo_site_id'] ?? '')),
            updateCheckEnabled: $updateCheck,
            versionReportEnabled: $versionReport,
        );
    }

    /**
     * Origins of the Matomo instances this installation is configured to load
     * a tracker from (own analytics and/or product telemetry). These are the
     * only third-party origins the CSP allows, and only when configured: a
     * default install has none. A target counts only when both its URL and its
     * site ID are set.
     *
     * @return list<string> e.g. ['https://analytics.example.org']
     */
    public function trackerOrigins(): array
    {
        $origins = [];
        foreach ([[$this->matomoUrl, $this->matomoSiteId], [$this->telemetryMatomoUrl, $this->telemetryMatomoSiteId]] as [$url, $siteId]) {
            if ($url === '' || $siteId === '') {
                continue;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                continue;
            }
            $origins[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return array_values(array_unique($origins));
    }

    /**
     * Fail-secure: only 'google'/'github' (the only providers this codebase
     * implements — Security\OAuth\OAuthProviderRegistry) are ever accepted,
     * and only when BOTH client_id/client_secret are non-empty. Anything
     * else (unknown provider key, missing config, empty secret) is treated
     * as "not configured", never as an error.
     */
    public function oauthProviderConfigured(string $provider): bool
    {
        if (!\Votepit\Security\OAuth\OAuthProviderRegistry::isKnown($provider)) {
            return false;
        }

        $cfg = $this->oauthProviders[$provider] ?? null;
        return is_array($cfg)
            && $cfg['client_id'] !== ''
            && $cfg['client_secret'] !== '';
    }

    /**
     * Caller must check oauthProviderConfigured() first — this returns an
     * empty client_id/client_secret pair rather than throwing for an
     * unconfigured provider (mirrors the fail-secure posture of the OAuth
     * routes themselves: a 404 before this is ever called for real).
     *
     * @return array{client_id: string, client_secret: string}
     */
    public function oauthProvider(string $provider): array
    {
        return $this->oauthProviders[$provider] ?? ['client_id' => '', 'client_secret' => ''];
    }

    /**
     * The subset of the known provider allowlist that is actually usable —
     * exposed via GET /api/bootstrap so the SPA renders a button only for a
     * provider an operator has actually configured (requirement: graceful
     * absence, never an error).
     *
     * @return list<string>
     */
    public function configuredOAuthProviders(): array
    {
        return array_values(array_filter(
            \Votepit\Security\OAuth\OAuthProviderRegistry::PROVIDERS,
            $this->oauthProviderConfigured(...),
        ));
    }

    /**
     * Fail-secure: only 'google' (the only provider this codebase
     * implements — Votepit\Translation\GoogleTranslateProvider) is ever
     * accepted, and only when project_id AND service_account_key are both
     * non-empty. Anything else (empty provider, unknown provider,
     * incomplete Google config) is treated as "not configured", never as
     * an error — a fresh checkout with zero config changes returns false
     * here, which is what makes the NullTranslationProvider wiring in
     * AppFactory the actual code path, not just an assumption.
     */
    public function translationConfigured(): bool
    {
        if ($this->translation['provider'] !== 'google') {
            return false;
        }

        $google = $this->translation['google'];
        return $google['project_id'] !== '' && $google['service_account_key'] !== '';
    }

    /**
     * The configured provider name ('google') or '' if none is configured
     * — caller must check translationConfigured() first for any decision
     * beyond display/logging.
     */
    public function translationProvider(): string
    {
        return $this->translationConfigured() ? $this->translation['provider'] : '';
    }

    /**
     * @return array{project_id: string, service_account_key: string, region: string}
     */
    public function translationGoogleConfig(): array
    {
        return $this->translation['google'];
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
     * Fail-closed default for an action key with no `rate_limits` entry at
     * all (as opposed to one explicitly configured with `limit => 0`, which
     * stays honored as an intentional "disabled" — see rateLimit() below). A
     * future sensitive route added without a config entry must not
     * accidentally ship unlimited (review 2026-09-04, item 13).
     */
    private const UNCONFIGURED_ACTION_LIMIT  = 30;
    private const UNCONFIGURED_ACTION_WINDOW = 60;

    /**
     * Rate-limit config for an action.
     *
     * @return array{limit:int, window:int}
     */
    public function rateLimit(string $action): array
    {
        if (!array_key_exists($action, $this->rateLimits)) {
            return ['limit' => self::UNCONFIGURED_ACTION_LIMIT, 'window' => self::UNCONFIGURED_ACTION_WINDOW];
        }

        $cfg = (array) $this->rateLimits[$action];
        return [
            'limit'  => (int) ($cfg['limit'] ?? 0),
            'window' => (int) ($cfg['window'] ?? 3600),
        ];
    }

    /**
     * Rate limit for a mandatory guest bucket (ADR 0006 §4): like
     * rateLimit(), but an unconfigured key falls back to the given default
     * instead of the generic 30/60s (far looser than the guest values), and
     * a non-positive limit cannot switch the protection off.
     *
     * @return array{limit:int, window:int}
     */
    public function mandatoryRateLimit(string $action, int $defaultLimit, int $defaultWindow): array
    {
        if (!array_key_exists($action, $this->rateLimits)) {
            return ['limit' => $defaultLimit, 'window' => $defaultWindow];
        }

        $cfg = $this->rateLimit($action);
        if ($cfg['limit'] <= 0 || $cfg['window'] <= 0) {
            return ['limit' => $defaultLimit, 'window' => $defaultWindow];
        }

        return $cfg;
    }

    /**
     * Repeat-offender escalation thresholds (board-operator-compliance
     * issue 10, migrations/0062_create_account_member_standing.sql) —
     * violation_count values at which Domain\ModerationStandingService
     * escalates a member's account_member_standing.state one step
     * further. Config-driven, same `config/config.php` override pattern
     * as rateLimit() above (key: 'moderation_escalation' => ['flagged_at'
     * => ..., 'warned_at' => ..., 'suspended_at' => ...]).
     *
     * Defaults (not specified by the PRD, chosen here): flagged at the
     * 1st violation (any reason-coded removal is worth surfacing to a
     * moderator immediately), warned at the 3rd (a pattern, not a single
     * mistake), suspended at the 5th (repeated disregard for two prior
     * warnings). Deliberately NOT symmetric/evenly spaced — the gap
     * before the first step is short (visibility is cheap) while the
     * gaps thereafter widen slightly, giving a member real room to
     * correct course between "warned" and "suspended" before losing
     * submission access entirely.
     *
     * @return array{flagged_at:int, warned_at:int, suspended_at:int}
     */
    public function moderationEscalation(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->moderationEscalation;

        return [
            'flagged_at'   => (int) ($cfg['flagged_at'] ?? 1),
            'warned_at'    => (int) ($cfg['warned_at'] ?? 3),
            'suspended_at' => (int) ($cfg['suspended_at'] ?? 5),
        ];
    }

    /**
     * Avatar-upload limit, config-driven (OWASP/BSI audit remediation issue
     * 17) — same `config/config.php` override pattern as rateLimit()/
     * moderationEscalation() above (key: 'avatar_upload' => ['max_bytes' =>
     * ...]). Default stays the previously hardcoded 5 MB when unconfigured,
     * so a fresh checkout with zero config changes behaves exactly as
     * before.
     */
    public function avatarMaxUploadBytes(): int
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->avatarUpload;

        return (int) ($cfg['max_bytes'] ?? 5 * 1024 * 1024);
    }

    private const AVATAR_IMAGE_TYPE_NAMES = [
        'jpeg' => IMAGETYPE_JPEG,
        'png'  => IMAGETYPE_PNG,
        'gif'  => IMAGETYPE_GIF,
        'webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Allowed avatar image types, config-driven via `avatar_upload.allowed_types`
     * (list of the string keys above, e.g. `['jpeg', 'png']`). Unknown names
     * are ignored (fail-secure: never widens acceptance to a type this
     * codebase doesn't recognize). Defaults to all four when unconfigured.
     *
     * @return list<int>
     */
    public function avatarAllowedImageTypes(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->avatarUpload;

        if (!array_key_exists('allowed_types', $cfg)) {
            return array_values(self::AVATAR_IMAGE_TYPE_NAMES);
        }

        $names = array_map(static fn (mixed $n): string => strtolower(trim((string) $n)), (array) $cfg['allowed_types']);

        return array_values(array_filter(
            array_map(static fn (string $n): ?int => self::AVATAR_IMAGE_TYPE_NAMES[$n] ?? null, $names),
            static fn (?int $t): bool => $t !== null,
        ));
    }

    /**
     * Cloudflare Turnstile anti-automation challenge (OWASP/BSI audit
     * remediation issue 20), config-driven via `turnstile` (keys: `site_key`,
     * `secret_key`, `suspicion_threshold`, `suspicion_window_seconds`).
     * Fail-secure-by-absence: an unconfigured (empty) site_key/secret_key
     * means the feature is entirely off — no widget is ever shown, no
     * requests are ever rejected for missing a Turnstile token — exactly
     * like Config::translationConfigured()/sentryDsn above. A self-host
     * operator who never sets these up gets identical behavior to before
     * this issue existed.
     */
    public function turnstileConfigured(): bool
    {
        return $this->turnstileSiteKey() !== '' && $this->turnstileSecretKey() !== '';
    }

    public function turnstileSiteKey(): string
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->turnstile;

        return trim((string) ($cfg['site_key'] ?? ''));
    }

    public function turnstileSecretKey(): string
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->turnstile;

        return trim((string) ($cfg['secret_key'] ?? ''));
    }

    /**
     * Number of rate-limit hits (across the guarded actions, see
     * TurnstileGuard) from the same IP within suspicionWindowSeconds()
     * before a Turnstile challenge is required. Default 3.
     */
    public function turnstileSuspicionThreshold(): int
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->turnstile;

        return (int) ($cfg['suspicion_threshold'] ?? 3);
    }

    /** Rolling window for the suspicion counter above. Default 1 hour. */
    public function turnstileSuspicionWindowSeconds(): int
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->turnstile;

        return (int) ($cfg['suspicion_window_seconds'] ?? 60 * 60);
    }
}
