<?php

declare(strict_types=1);

/**
 * Votepit — configuration template.
 *
 * Copy this file to `config/config.php` and fill it in.
 * `config/config.php` is excluded from the repo via .gitignore and must
 * NEVER be committed — it contains secrets (DB, SMTP, app key).
 */

return [
    // 'prod' for live operation, 'dev' for local development (shows error
    // details, no HSTS secure flag, Twig without cache).
    'env' => 'prod',

    // Public base URL of the installation (without trailing slash).
    'app_url' => 'https://feedback.example.com',

    // Random key for signing session cookies and magic links.
    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => '',

    // Random key for HMAC pseudonymization of email addresses
    // (ADR 0002). Deliberately separate from app_key (independent rotation).
    // Generate: php -r "echo bin2hex(random_bytes(32));"
    'identity_server_key' => '',

    // Validity period of a magic link in seconds (default: 15 minutes).
    'magic_link_ttl' => 60 * 15,

    // Database (MySQL/MariaDB) — prepared statements only (DBAL).
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'votepit',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // SMTP for magic-link sending (SMTP access from your host/mail provider).
    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'user'       => '',
        'pass'       => '',
        'encryption' => 'tls', // 'tls' | 'ssl'
        'from_email' => 'noreply@example.com',
        'from_name'  => 'Votepit',
    ],

    // Admin email addresses. is_admin is set on first magic-link login.
    'admin_emails' => [
        // 'you@example.com',
    ],

    // Session lifetime in seconds (default: 30 days).
    'session_lifetime' => 60 * 60 * 24 * 30,

    // Routing mode (cloud path routing):
    // 'self-host' (default) — exactly one account, paths stay `/{board}/...`.
    // 'cloud' — multiple accounts, account-/board-scoped routes carry a
    // leading `/{account}` segment (`/{account-slug}/{board-slug}`).
    // Self-hosters should leave this unchanged at 'self-host'.
    'routing_mode' => 'self-host',

    // Cloudflare proxy in front of the app origin:
    // false (default) — client IP is always REMOTE_ADDR. true — additionally
    // trusts the `CF-Connecting-IP` header (otherwise rate limiting/logging
    // only see Cloudflare's edge IP). Only enable this TOGETHER with the
    // origin lock (vHost/firewall only accepts Cloudflare's IP ranges) —
    // otherwise the header can be forged by anyone reaching the origin directly.
    'trust_cloudflare_ip' => false,

    // Error monitoring (Sentry). '' (default) — no monitoring, self-host
    // default, NullErrorReporter does nothing. Setting a real DSN (cloud
    // operation) enables Votepit\Monitoring\SentryErrorReporter — uncaught
    // exceptions are additionally reported on top of the existing
    // error_log logging, otherwise nothing changes.
    'sentry_dsn' => '',

    // Separate frontend DSN (SPA, @sentry/react in core/app/src/main.tsx) —
    // '' (default) disables it. Deliberately separate from sentry_dsn above:
    // a client DSN is public anyway (it's embedded in the built JS bundle,
    // only authorizes SENDING events, never reading), the backend DSN stays
    // server-side. /api/bootstrap delivers this value to the SPA.
    'sentry_dsn_frontend' => '',

    // Domain attribute of the session cookie. Empty/unset (default) = no
    // domain attribute, the cookie is host-only (self-host: correct, since
    // the installation itself is the only origin). A multi-tenant deployment
    // sets this explicitly to its app host, so the cookie is valid across
    // all tenant paths on the same origin. Secure/HttpOnly/SameSite remain
    // hard-coded in SessionService (not configurable).
    'session_cookie_domain' => '',

    // Rate limits (security.md §6). limit=0 disables an action.
    'rate_limits' => [
        'global:ip'       => ['limit' => 300, 'window' => 60],       // rough: 300/minute per IP (DoS brake)
        'magiclink:email' => ['limit' => 3,  'window' => 3600],      // 3/hour per email
        'magiclink:ip'    => ['limit' => 5,  'window' => 3600],      // 5/hour per IP
        // Per-action bucket convention: the config key is identical to the
        // action string that AppFactory looks up via $config->rateLimit(...).
        'idea:submit'     => ['limit' => 5,  'window' => 3600],      // 5 ideas/hour
        'idea:vote'       => ['limit' => 60, 'window' => 60],        // 60 votes/minute
        'comment:user'    => ['limit' => 10, 'window' => 3600],      // 10 comments/hour
        'dupsearch:user'  => ['limit' => 30, 'window' => 60],        // 30/minute duplicate search
        'smtp:test'       => ['limit' => 5,  'window' => 300],       // 5 test mails / 5 minutes
        'invite:send'     => ['limit' => 20, 'window' => 3600],      // 20 invites/hour per owner
        'apitoken:read'   => ['limit' => 120, 'window' => 60],       // 120/minute per token (agent API read access)
        'apitoken:write'  => ['limit' => 20, 'window' => 3600],      // 20 ideas/hour per token (agent API idea creation)
        'report:submit'   => ['limit' => 10, 'window' => 3600],      // 10 reports/hour per IP (DSA Art. 16 — public, unauthenticated report path)
        'login:password'  => ['limit' => 10, 'window' => 900],       // 10/15min per IP — brute-force protection on POST /login/password
        'login:2fa'       => ['limit' => 12, 'window' => 900],       // 12/15min per IP — TOTP has only 6 digits (10^6 space), must be tightly limited; shares the bucket with backup codes (POST /login/2fa). Bucket is reset on success (Login2faAction), so only actual failed attempts/retries count here — 8 turned out too tight (legitimate users saw 429 after a few typos/network retries before the correct code came through)
        'password:reset:email' => ['limit' => 3, 'window' => 3600],  // 3/hour per email — mirrors magiclink:email (POST /password/reset/request)
        'password:reset:ip'    => ['limit' => 5, 'window' => 3600],  // 5/hour per IP — mirrors magiclink:ip
        'avatar:upload'   => ['limit' => 10, 'window' => 3600],      // 10 avatar uploads/hour per user (profile-avatar-social)
        'sociallinks:update' => ['limit' => 20, 'window' => 3600],   // 20 social-links updates/hour per user (profile-avatar-social)
        'username:update' => ['limit' => 10, 'window' => 3600],      // 10 username changes/hour per user — enough for iterating on picking one, tight enough to slow squatting-by-cycling
        'account:delete'  => ['limit' => 3,  'window' => 86400],     // 3/day per owner (GDPR self-deletion)
        'account:delete:cancel' => ['limit' => 20, 'window' => 3600], // 20/hour per owner (revoke deletion — not destructive, more generous)
    ],

    // Extensions — code that is NOT part of the Community Edition (e.g. the
    // billing/operations layer of a hosted offering). A self-host
    // installation leaves this list empty. An extension package brings its
    // own autoloader, which must be loaded via `require` BEFORE the
    // `return` of this file; then register the extension class with its
    // options here (see Votepit\Extension\AppExtension):
    //
    //   require __DIR__ . '/../some-extension/autoload.php';
    //   ...
    //   'extensions' => [
    //       \Vendor\SomeExtension::class => [ /* extension options */ ],
    //   ],
    'extensions' => [],
];
