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
        // Absolute path to a CA bundle for the app<->DB connection. ''
        // (default) — unencrypted, the common case when `host` is a bare
        // Unix-socket "localhost" or a same-network Docker-Compose service
        // with no untrusted hop in between. Set this when DB traffic
        // crosses a network segment you don't fully trust (e.g. a separate
        // managed/remote MySQL host): it enables BOTH encryption AND server
        // certificate verification together (never one without the
        // other — see ConnectionFactory::createForDb()).
        'ssl_ca'  => '',
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

    // OAuth2 login (Google/GitHub) — additive to magic-link/password/TOTP,
    // never a replacement. '' (default, both empty/missing) — no button
    // shown, GET /login/oauth/{provider}/start fail-secure 404s. Only
    // 'google'/'github' keys are recognized; anything else is ignored.
    // Register the redirect URI in each provider's console EXACTLY as:
    //   {app_url}/login/oauth/google/callback
    //   {app_url}/login/oauth/github/callback
    'oauth_providers' => [
        'google' => [
            'client_id'     => '',
            'client_secret' => '',
        ],
        'github' => [
            'client_id'     => '',
            'client_secret' => '',
        ],
    ],

    // On-demand translation of idea/comment content. '' (default) — no
    // provider configured, the "Translate" affordance never appears (fail-
    // secure: no config change here = no external call ever attempted, no
    // cost). Only 'google' is recognized. Enabling it adds Google as a new
    // subprocessor — update your privacy documentation before switching
    // this to 'google' in production.
    'translation' => [
        'provider' => '',
        'google'   => [
            // GCP project ID that owns the Cloud Translation API.
            'project_id' => '',
            // Absolute path to a GCP service-account JSON key file with the
            // Cloud Translation API role, kept OUTSIDE the repo/webroot like
            // every other secret here — never commit the key file itself.
            'service_account_key' => '',
            // Multi-regional endpoint selector for Cloud Translation
            // Advanced (v3) — 'eu' keeps text in transit/at rest inside the
            // EU (translate-eu.googleapis.com). Cloud always ships with
            // 'eu'; a self-hoster in a different residency situation may
            // choose their own GCP project's region here.
            'region' => 'eu',
        ],
    ],

    // Session lifetime in seconds (default: 30 days) — the absolute,
    // non-extendable expiry.
    'session_lifetime' => 60 * 60 * 24 * 30,

    // Rolling inactivity timeout in seconds (default: 14 days) — a session
    // not used within this window is rejected even if session_lifetime
    // hasn't elapsed yet. Refreshed automatically on active use.
    'session_inactivity_window' => 60 * 60 * 24 * 14,

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

    // GET /ready answers 503 when the cron manager (bin/votepit-cron.php)
    // has not started a tick for longer than this many minutes. The manager
    // ticks every minute; default 5.
    'readiness_cron_max_age_minutes' => 5,

    // How bin/votepit-cron.php runs jobs: 'inline' (default) waits for each
    // job's worker inside the tick, bounded to ~50 s; 'detached' lets workers
    // outlive the tick. Use 'detached' only after the host test in
    // documentation/operations.md confirmed the scheduler keeps them alive.
    'cron_dispatch' => 'inline',

    // Separate frontend DSN (SPA, @sentry/react in core/app/src/main.tsx) —
    // '' (default) disables it. Deliberately separate from sentry_dsn above:
    // a client DSN is public anyway (it's embedded in the built JS bundle,
    // only authorizes SENDING events, never reading), the backend DSN stays
    // server-side. /api/bootstrap delivers this value to the SPA.
    'sentry_dsn_frontend' => '',

    // Optional own analytics (Matomo). '' (default) — no tracker loaded.
    // Set both together to load a cookieless Matomo tracker in the SPA (see
    // documentation/configuration.md#analytics). Separate from Community
    // Edition's own opt-out product telemetry (Votepit\Telemetry\CommunityTelemetry),
    // which is not config-driven.
    'matomo_url'     => '',
    'matomo_site_id' => '',

    // Domain attribute of the session cookie. Empty/unset (default) = no
    // domain attribute, the cookie is host-only (self-host: correct, since
    // the installation itself is the only origin). A multi-tenant deployment
    // sets this explicitly to its app host, so the cookie is valid across
    // all tenant paths on the same origin. Secure/HttpOnly/SameSite remain
    // hard-coded in SessionService (not configurable).
    'session_cookie_domain' => '',

    // Cross-origin session badge (GET /api/v1/session/badge): exact origins
    // of a separate web origin (e.g. a marketing site) that may read the
    // visitor's signed-in identity WITH credentials (the session cookie).
    // Each entry: https://host[:port] only — no path, no trailing slash, no
    // wildcard. [] (default) = CORS off for every origin (self-host). The
    // Cloud value is documented in documentation/configuration.md. The
    // endpoint itself is anonymous-safe; the allowlist is the only gate.
    'cors_badge_origins' => [],

    // Rate limits (security.md §6). limit=0 for an action listed here
    // explicitly disables it. An action key NOT listed here at all fails
    // closed instead: Config::rateLimit() returns a safe non-zero default
    // (30/60s) rather than treating a missing entry as unlimited.
    'rate_limits' => [
        'global:ip'       => ['limit' => 300, 'window' => 60],       // rough: 300/minute per IP (DoS brake)
        'magiclink:email' => ['limit' => 3,  'window' => 3600],      // 3/hour per email
        'magiclink:ip'    => ['limit' => 5,  'window' => 3600],      // 5/hour per IP
        // Per-action bucket convention: the config key is identical to the
        // action string that AppFactory looks up via $config->rateLimit(...).
        'idea:submit'     => ['limit' => 5,  'window' => 3600],      // 5 ideas/hour
        'idea:vote'       => ['limit' => 60, 'window' => 60],        // 60 votes/minute
        'idea:follow'     => ['limit' => 60, 'window' => 60],        // 60 follow/unfollow toggles/minute per user (POST/DELETE {board}/ideas/{id}/follow)
        'comment:user'    => ['limit' => 10, 'window' => 3600],      // 10 comments/hour
        'comment:react'   => ['limit' => 60, 'window' => 60],        // 60 reaction toggles/minute (as light as idea:vote)
        'widget:identify' => ['limit' => 30, 'window' => 60],        // 30/minute per IP — embeddable widget: signed-JWT exchange (POST {board}/widget/identify)
        'widget:read'     => ['limit' => 120, 'window' => 60],       // 120/minute per user — embeddable widget: personalised idea list (GET {board}/widget/ideas); widget writes share idea:submit/idea:vote/comment:user
        'dupsearch:user'  => ['limit' => 30, 'window' => 60],        // 30/minute duplicate search
        'ai:tag-suggest'  => ['limit' => 20, 'window' => 60],        // 20/minute AI tag suggestions (P-35)
        'session:badge'   => ['limit' => 120, 'window' => 60],       // 120/minute per IP — GET /api/v1/session/badge (header badge, one call per page view)
        // Guest idea submission (ADR 0006 §4) — only used on boards with
        // "submit without an account" on. Mandatory: a missing key or a
        // limit of 0 falls back to these values, never to "unlimited".
        'idea:submit:anon:ip'         => ['limit' => 10, 'window' => 3600], // per IP (IPv6 grouped by /64)
        'idea:submit:anon:token'      => ['limit' => 3,  'window' => 3600], // per guest device cookie
        'idea:submit:anon:board'      => ['limit' => 30, 'window' => 3600], // per board, independent of IP rotation
        'idea:dupes:anon:ip'          => ['limit' => 30, 'window' => 3600], // guest duplicate hints
        'idea:guest-withdraw:token'   => ['limit' => 10, 'window' => 3600], // guest withdraws per device
        'smtp:test'       => ['limit' => 5,  'window' => 300],       // 5 test mails / 5 minutes
        'invite:send'     => ['limit' => 20, 'window' => 3600],      // 20 invites/hour per owner
        'apitoken:read'   => ['limit' => 120, 'window' => 60],       // 120/minute per token (agent API read access)
        'apitoken:write'  => ['limit' => 20, 'window' => 3600],      // 20 ideas/hour per token (agent API idea creation)
        'report:submit'   => ['limit' => 10, 'window' => 3600],      // 10 reports/hour per IP (DSA Art. 16 — public, unauthenticated report path)
        'appeal:submit'   => ['limit' => 5,  'window' => 3600],      // 5 appeals/hour per user (board-operator-compliance issue 09)
        'login:password'  => ['limit' => 10, 'window' => 900],       // 10/15min per IP — brute-force protection on POST /login/password
        'login:password:email' => ['limit' => 10, 'window' => 900], // 10/15min per email-HMAC — second dimension so IP rotation alone can't brute-force one known account (issue 05); same window as login:password, independent bucket
        'login:2fa'       => ['limit' => 12, 'window' => 900],       // 12/15min per IP — TOTP has only 6 digits (10^6 space), must be tightly limited; shares the bucket with backup codes (POST /login/2fa). Bucket is reset on success (Login2faAction), so only actual failed attempts/retries count here — 8 turned out too tight (legitimate users saw 429 after a few typos/network retries before the correct code came through)
        'login:2fa:token' => ['limit' => 12, 'window' => 900],       // 12/15min per pending-token-hash — second dimension so IP rotation alone can't brute-force the 6-digit code of one pending login (issue 05); same window as login:2fa, independent bucket
        'login:oauth'     => ['limit' => 20, 'window' => 900],       // 20/15min per IP — GET /login/oauth/{provider}/callback (state/replay/provider-error retries)
        'password:reset:email' => ['limit' => 3, 'window' => 3600],  // 3/hour per email — mirrors magiclink:email (POST /password/reset/request)
        'password:reset:ip'    => ['limit' => 5, 'window' => 3600],  // 5/hour per IP — mirrors magiclink:ip
        'avatar:upload'   => ['limit' => 10, 'window' => 3600],      // 10 avatar uploads/hour per user (profile-avatar-social)
        'sociallinks:update' => ['limit' => 20, 'window' => 3600],   // 20 social-links updates/hour per user (profile-avatar-social)
        'username:update' => ['limit' => 10, 'window' => 3600],      // 10 username changes/hour per user — enough for iterating on picking one, tight enough to slow squatting-by-cycling
        'account:delete'  => ['limit' => 3,  'window' => 86400],     // 3/day per owner (GDPR self-deletion)
        'account:delete:cancel' => ['limit' => 20, 'window' => 3600], // 20/hour per owner (revoke deletion — not destructive, more generous)
        'account:export'  => ['limit' => 10, 'window' => 3600],      // 10/hour per owner (GDPR Art. 20 self-export — expensive full-account query, guards against a compromised/scripted owner session triggering unlimited exports)
        'board:asset-terms' => ['limit' => 20, 'window' => 3600],    // 20/hour per admin (upload clause consent, POST /admin/boards/{slug}/assets/terms/accept)
        'board:statuses'  => ['limit' => 60, 'window' => 3600],      // 60/hour per admin (status categories, POST /admin/boards/{slug}/statuses)
        'idea:status'     => ['limit' => 120, 'window' => 3600],     // 120/hour per moderator (status changes)
        'idea:pin'        => ['limit' => 60, 'window' => 3600],      // 60/hour per moderator (pin/unpin)
        'idea:merge'      => ['limit' => 60, 'window' => 3600],      // 60/hour per moderator (merge + merge-candidates lookup)
        'user:block'      => ['limit' => 30, 'window' => 3600],      // 30/hour per admin (block/unblock voters)
        'board:export'    => ['limit' => 30, 'window' => 3600],      // 30/hour per admin (board results CSV, GET /admin/boards/{slug}/export — one unpaginated board read per hit)
        'user:export'     => ['limit' => 10, 'window' => 3600],      // 10/hour per user (GDPR Art. 15/20 own-data export, GET /account/export)
        'user:delete'     => ['limit' => 5,  'window' => 3600],      // 5/hour per user (self-deletion request — the typed-email confirmation must not be guessable by brute force)
        'user:delete:cancel' => ['limit' => 20, 'window' => 3600],   // 20/hour per user (undo a pending self-deletion — not destructive, more generous)
        'board:import'    => ['limit' => 20, 'window' => 3600],      // 20/hour per admin — board import (dry-run previews count too; each parses an upload of up to 2 MiB)
        'issue-tracker:connect' => ['limit' => 10,  'window' => 3600], // 10/hour per admin — Linear/GitHub connect (each verifies the token against the provider API)
        'issue-tracker:create'  => ['limit' => 30,  'window' => 3600], // 30/hour per moderator — "create issue from idea" (one outbound API call each)
        'issue-tracker:webhook' => ['limit' => 120, 'window' => 60],   // 120/min per IP — inbound Linear/GitHub status-sync webhooks (before the signature check)
        'oauth:register'        => ['limit' => 10,  'window' => 3600], // 10/hour per IP — MCP OAuth dynamic client registration (POST /oauth/register, anonymous by protocol)
        'oauth:register:global' => ['limit' => 200, 'window' => 3600], // 200/hour GLOBAL — caps oauth_clients growth even under a distributed registration flood
        'oauth:token'           => ['limit' => 60,  'window' => 60],   // 60/min per IP — MCP OAuth token endpoint (code exchange + refresh)
        'oauth:revoke'          => ['limit' => 30,  'window' => 60],   // 30/min per IP — MCP OAuth token revocation (RFC 7009)
        'oauth:consent'         => ['limit' => 30,  'window' => 3600], // 30/hour per user — MCP OAuth consent decisions (each approval issues an authorization code)
        'notification-email:request' => ['limit' => 5, 'window' => 3600], // 5/hour per user (notification-preferences: POST /account/notification-email)
        'notification-mail:global'   => ['limit' => 200, 'window' => 3600], // 200/hour GLOBAL (not per-user/IP) — protects your SMTP provider's send limit that time-critical magic-link mail depends on from a comment storm
        'digest:unsubscribe'         => ['limit' => 20, 'window' => 3600], // 20/hour per IP — weekly digest one-click unsubscribe (POST /digest/unsubscribe, no login, signed token)
        'operator-nudge:unsubscribe' => ['limit' => 20, 'window' => 3600], // 20/hour per IP — operator nudge one-click unsubscribe (POST /operator-nudges/unsubscribe, no login, signed token)
        'translation:idea'    => ['limit' => 90, 'window' => 600],   // 90/10min per user — raised from 60 (2026-09-08, no capacity-exhaustion data yet, headroom for viewport-lazy board-list auto-translate plus normal browsing) while staying disruptive for enumerating every idea × every locale
        'translation:comment' => ['limit' => 30, 'window' => 600],   // 30/10min per user — raised from 20 (2026-09-08); still one hit per request regardless of comment count (bulk endpoint)
        'translation:board_intro' => ['limit' => 30, 'window' => 600], // 30/10min per user — single-item like translation:idea, but an intro is read far less often than the idea list, so kept at the lower comment-style limit
        'translation:notification' => ['limit' => 30, 'window' => 600], // 30/10min per user — one hit per request regardless of batch size (bulk endpoint, mirrors translation:comment)
        'translation:support_message' => ['limit' => 30, 'window' => 600], // 30/10min per user — one hit per request regardless of thread length (bulk endpoint, mirrors translation:comment)
    ],

    // Repeat-offender escalation thresholds (board-operator-compliance
    // issue 10) — violation_count values (content_moderation_actions rows
    // against a member) at which account_member_standing.state escalates
    // one step further (normal → flagged → warned → suspended). Omit to
    // use the built-in defaults (Config::moderationEscalation()): flagged
    // at 1, warned at 3, suspended at 5.
    'moderation_escalation' => [
        'flagged_at'   => 1,
        'warned_at'    => 3,
        'suspended_at' => 5,
    ],

    // Avatar upload limits (OWASP/BSI audit remediation issue 17) — omit
    // entirely to use the built-in defaults (Config::avatarMaxUploadBytes()/
    // avatarAllowedImageTypes()): 5 MB, jpeg/png/gif/webp.
    'avatar_upload' => [
        'max_bytes'     => 5 * 1024 * 1024,
        'allowed_types' => ['jpeg', 'png', 'gif', 'webp'],
    ],

    // Cloudflare Turnstile anti-automation challenge (OWASP/BSI audit
    // remediation issue 20) — OPTIONAL. Leave site_key/secret_key empty
    // (the default) to disable the feature entirely: no widget is ever
    // shown, idea/vote/comment submission behaves exactly as without this
    // config block. Get a site key + secret key from the Cloudflare
    // dashboard (Turnstile) to enable it. suspicion_threshold/
    // suspicion_window_seconds tune how many times the same IP must trip
    // the existing rate limits within the window before it's challenged —
    // see Config::turnstileSuspicionThreshold()/turnstileSuspicionWindowSeconds()
    // for the defaults (3 hits / 1 hour) used when omitted.
    // Ideas without an account (ADR 0006) — installation-wide kill switch
    // above every board flag and plan. Omitted, it defaults to true for
    // routing_mode 'self-host' and to false for 'cloud' (a hosted
    // installation must opt in explicitly). Guests additionally need
    // Turnstile (below) and a plan that includes the feature.
    // 'guest_submission_enabled' => true,

    // Installation-wide kill switches (release freeze). Each one is true when
    // omitted, so the installation behaves as before; set one to false to turn
    // that area off everywhere at once, without touching any board. Must be a
    // boolean. See documentation/configuration.md ("Installation kill switches").
    // 'board_uploads'     => true,  // board logo/cover uploads refused; uploaded images not served
    // 'participant_mails' => true,  // no participant, digest, new-ideas or nudge mails sent or queued
    // 'vote_reasons'      => true,  // reasons for votes: toggle cannot be switched on, reasons refused

    'turnstile' => [
        'site_key'                => '',
        'secret_key'              => '',
        'suspicion_threshold'     => 3,
        'suspicion_window_seconds' => 60 * 60,
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
