<?php

declare(strict_types=1);

namespace Votepit\Http;

use Doctrine\DBAL\Connection;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Cleanup\BoardAssetCleanup;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\IdeaStatusChanger;
use Votepit\Domain\JaroWinklerSimilarity;
use Votepit\Domain\ModerationStandingService;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TitleNormalizer;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Extension\AppExtension;
use Votepit\Extension\ExtensionContext;
use Votepit\Extension\ExtensionLoader;
use Votepit\Extension\NullAccountDeletionPrecondition;
use Votepit\Extension\NullBoardComplianceEnforcer;
use Votepit\Extension\NullBoardOperatorAcknowledgmentGate;
use Votepit\Extension\NullTermsAcceptanceGate;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\BlockCheckMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Http\Routes\AccountAdminRoutes;
use Votepit\Http\Routes\AccountSecurityRoutes;
use Votepit\Http\Routes\ApiTokenAdminRoutes;
use Votepit\Http\Routes\ApiV1Routes;
use Votepit\Http\Routes\AuthRoutes;
use Votepit\Http\Routes\BoardAssetRoutes;
use Votepit\Http\Routes\BoardImportRoutes;
use Votepit\Http\Routes\BoardIntegrationRoutes;
use Votepit\Http\Routes\BoardRoutes;
use Votepit\Http\Routes\BoardSettingsRoutes;
use Votepit\Http\Routes\BootstrapRoutes;
use Votepit\Http\Routes\CommentRoutes;
use Votepit\Http\Routes\DiscoveryRoutes;
use Votepit\Http\Routes\IdeaRoutes;
use Votepit\Http\Routes\InviteAndSignupRoutes;
use Votepit\Http\Routes\IssueTrackerRoutes;
use Votepit\Http\Routes\MemberRoutes;
use Votepit\Http\Routes\NotificationRoutes;
use Votepit\Http\Routes\OAuthServerRoutes;
use Votepit\Http\Routes\OperatorRoutes;
use Votepit\Http\Routes\ParticipantMailRoutes;
use Votepit\Http\Routes\ProfileRoutes;
use Votepit\Http\Routes\RoadmapRoutes;
use Votepit\Http\Routes\SessionRoutes;
use Votepit\Http\Routes\SupportRoutes;
use Votepit\Http\Routes\SystemRoutes;
use Votepit\Http\Routes\TagRoutes;
use Votepit\Http\Routes\TranslationRoutes;
use Votepit\Http\Routes\WidgetRoutes;
use Votepit\Http\Routes\WorklistRoutes;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\IssueTracker\CurlIssueTrackerHttpClient;
use Votepit\IssueTracker\IssueStatusSync;
use Votepit\IssueTracker\IssueTrackerApi;
use Votepit\IssueTracker\IssueTrackerHttpClient;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Migrations\MigrationRunner;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Monitoring\NullErrorReporter;
use Votepit\Monitoring\ReadinessCheck;
use Votepit\Monitoring\SentryErrorReporter;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\BoardWebhookRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaIssueLinkRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IdeaStatusDecisionRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\IssueTrackerConnectionRepository;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Resilience\CircuitBreaker;
use Votepit\Security\ApiTokenAuthenticator;
use Votepit\Security\AuditIpHasher;
use Votepit\Security\BoardAssetFileStore;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\OAuth\CurlOAuthHttpClient;
use Votepit\Security\OAuth\OAuthHttpClient;
use Votepit\Security\Password\CurlHibpHttpClient;
use Votepit\Security\Password\HibpHttpClient;
use Votepit\Security\Password\PasswordPolicy;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\RateLimiter;
use Votepit\Security\SessionService;
use Votepit\Security\SmtpHostPolicy;
use Votepit\Security\TimeTrapService;
use Votepit\Security\TokenVault;
use Votepit\Security\Totp;
use Votepit\Security\TotpBackupCodes;
use Votepit\Security\TotpSetupToken;
use Votepit\Security\Turnstile\CurlTurnstileHttpClient;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Security\Turnstile\TurnstileHttpClient;
use Votepit\Security\Webhook\CurlWebhookSender;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookUrlGuard;
use Votepit\Support\Clock;
use Votepit\Support\SystemClock;
use Votepit\Translation\CircuitBreakingTranslationHttpClient;
use Votepit\Translation\CurlTranslationHttpClient;
use Votepit\Translation\GoogleTranslateProvider;
use Votepit\Translation\NullTranslationProvider;
use Votepit\Translation\TranslationHttpClient;
use Votepit\Translation\TranslationProvider;
use Votepit\Webhook\Chat\ChatMessageFormatter;
use Votepit\Webhook\Chat\ChatNotificationDispatcher;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Builds the Slim 4 app with the PSR-15 middleware pipeline (arch.md L1–L4) and
 * the defined routes.
 *
 * Smoke route (GET /), security headers, RateLimit(IP)/session/AuthN/
 * BlockCheck/CSRF as a pipeline, AuthZ per route.
 * GET /login + POST /login (magic-link request flow), mailer seam,
 * UserRepository, LoginTokenRepository.
 * All routes return JSON API responses; Twig removed;
 * GET /api/bootstrap (CSRF token + whoami for the SPA).
 *
 * The DB connection is optional: without it (DB-less smoke test) the
 * RateLimit(IP) layer is skipped and the login routes are not registered.
 * The mailer is optional: without one (production) a SymfonyMailerAdapter
 * is built from the SmtpConfig; tests inject an InMemoryMailer.
 * The AuditLogger is optional: without one, the file-based default is built.
 *
 * Route registration lives in the route modules under Votepit\Http\Routes
 * (one small class per area, static register()); this class builds the
 * shared services, the global pipeline and calls the modules in a
 * load-bearing order:
 *   1. global/unprefixed modules (System, Readiness, Bootstrap, Auth, AccountSecurity,
 *      InviteAndSignup, ApiV1, IssueTracker webhooks, Operator, Notification, Support, Discovery),
 *   2. AppExtension::register() (extension routes),
 *   3. Profile (unprefixed /account/* first, then its one prefixed route),
 *      then the {account}-prefixed modules (Board → Idea → Tag → Comment → Widget →
 *      Roadmap → Translation → BoardSettings → Member → AccountAdmin →
 *      BoardIntegration → IssueTracker admin → ApiTokenAdmin; the IssueTracker
 *      webhooks are unprefixed and belong to group 1),
 *   4. extension middleware on named core routes (CoreRoute).
 * A new unprefixed route MUST go into a module of group 1 (FastRoute
 * collision, see the "Cloud-mode routing safety net" below). The complete
 * route table is pinned by tests/Http/RouteTableSnapshotTest.php.
 */
final class AppFactory
{
    /**
     * @param list<AppExtension>|null $extensions Pre-built extensions; null
     *                                            loads them from Config::$extensions.
     * @return App<null>
     */
    public static function create(
        Config $config,
        ?Connection $conn = null,
        ?Mailer $mailer = null,
        ?AuditLogger $auditLogger = null,
        ?SmtpHostPolicy $smtpHostPolicy = null,
        // profile-avatar-social: injectable so tests point at a throwaway
        // temp dir instead of writing into the real repo-root storage/
        // directory. Production/self-host default: $root . '/storage/avatars'.
        ?string $avatarDirOverride = null,
        // P-28 board imagery: same idea as avatarDirOverride (tests use a temp dir).
        ?string $boardAssetDirOverride = null,
        // Plan-derived limits. Community Edition default: no limits at all
        // (UnrestrictedPlanPolicy); resolved from the extensions unless a
        // test injects one explicitly.
        ?PlanPolicy $planPolicy = null,
        // Extensions (Votepit\Extension\AppExtension). null = build them from
        // Config::$extensions (production); tests pass an explicit list.
        ?array $extensions = null,
        // OAuth login (Google/GitHub) outbound HTTP seam — null = the real
        // CurlOAuthHttpClient (production/self-host default). Tests inject
        // an InMemoryOAuthHttpClient to exercise the token-exchange/
        // profile-fetch contract without real network calls, same pattern
        // as $mailer/$smtpHostPolicy above.
        ?OAuthHttpClient $oauthHttpClient = null,
        // board-webhooks: injectable HTTP sender + defer-runner so tests can
        // exercise the full HTTP route without real network access and
        // without waiting on PHP process shutdown (see WebhookDispatcher).
        ?WebhookSenderInterface $webhookSender = null,
        ?\Closure $webhookDeferRunner = null,
        // idea-comment-translation: outbound HTTP seam for
        // GoogleTranslateProvider's token-exchange + translate calls — null
        // = the real CurlTranslationHttpClient (production/self-host
        // default). Tests inject an InMemoryTranslationHttpClient, same
        // pattern as $oauthHttpClient above.
        ?TranslationHttpClient $translationHttpClient = null,
        // password-policy-hardening: outbound HTTP seam for the HIBP Pwned
        // Passwords range API — null = the real CurlHibpHttpClient
        // (production/self-host default). Tests inject an
        // InMemoryHibpHttpClient, same pattern as $oauthHttpClient above.
        ?HibpHttpClient $hibpHttpClient = null,
        // OWASP/BSI audit remediation issue 12: outbound Sentry/security-event
        // seam — null = the real Sentry-DSN-gated resolution below
        // (production/self-host default). Tests inject a RecordingErrorReporter
        // to assert on reportSecurityEvent() calls, same pattern as $mailer.
        ?ErrorReporter $errorReporter = null,
        // anti-automation-challenge (issue 20): outbound HTTP seam for
        // Cloudflare Turnstile's siteverify endpoint — null = the real
        // CurlTurnstileHttpClient (production/self-host default). Tests
        // inject an InMemoryTurnstileHttpClient, same pattern as
        // $oauthHttpClient above.
        ?TurnstileHttpClient $turnstileHttpClient = null,
        // Embeddable widget (ADR 0005): path of the built iframe shell
        // (app/widget.html → dist → public/widget.html). null = that
        // production location; tests point at a temp file.
        ?string $widgetShellPath = null,
        // board-webhooks / chat notifications: injectable SSRF guard so tests
        // can stub DNS resolution for the fixed Slack/Discord hosts (same
        // pattern as $smtpHostPolicy). null = real DNS.
        ?WebhookUrlGuard $webhookUrlGuard = null,
        // Board phase windows (broaden-use-cases issue 04): the server's
        // "now" for submission/voting deadlines — null = SystemClock (UTC).
        // Tests inject a fixed clock to pin exact boundaries.
        ?Clock $clock = null,
        // Linear/GitHub issue links (issue 19): outbound HTTP seam for the
        // two fixed provider API hosts — null = the real
        // CurlIssueTrackerHttpClient (SSRF-pinned via $webhookUrlGuard).
        // Tests inject an InMemoryIssueTrackerHttpClient.
        ?IssueTrackerHttpClient $issueTrackerHttpClient = null,
    ): App {
        $responseFactory = new ResponseFactory();
        $clock         ??= new SystemClock();

        $app = new App($responseFactory);

        // --- Services -----------------------------------------------------
        $root     = dirname(__DIR__, 2); // Repo-Root
        $secure   = $config->env === 'prod';
        // Outbound SMTP target policy: restrictive on the shared cloud host
        // (tenant-chosen relays must be public), permissive for self-host.
        // Injectable so tests can stub DNS resolution.
        $smtpHostPolicy ??= new SmtpHostPolicy($config->routingMode === 'cloud');
        // Extensions — none in a plain Community install. Everything an
        // extension may influence goes through Votepit\Extension\AppExtension;
        // core never references a concrete extension class.
        $extensions ??= ExtensionLoader::fromConfig($config);
        $planPolicy ??= ExtensionLoader::planPolicy($extensions) ?? new UnrestrictedPlanPolicy();
        // Feature flags the SPA reads once from GET /api/bootstrap. Core's
        // own flags: per-board SMTP settings are a self-host feature (a
        // hosted multi-tenant installation uses the operator's central
        // mailer only, see the board SMTP routes below); legal footer links
        // are null unless an extension provides them — the Community Edition
        // renders no operator-specific legal links; the marketing-discover
        // redirect URL is likewise null unless an extension provides one —
        // Community's DiscoverPage renders the in-app list instead.
        $bootstrapFeatures = ExtensionLoader::bootstrapFeatures($extensions, [
            'board_smtp'             => $config->routingMode !== 'cloud',
            'legal_links'            => null,
            'marketing_discover_url' => null,
            // anti-automation-challenge (issue 20): the PUBLIC Turnstile
            // site key (safe to expose — only the secret key is sensitive),
            // or null when unconfigured (default) — the SPA renders no
            // widget at all until the server actually challenges a request.
            'turnstile_site_key'     => $config->turnstileConfigured() ? $config->turnstileSiteKey() : null,
            // Product-news consent (W1): the signup checkbox and the profile toggle are
            // rendered only when true. Self-host default: false.
            'marketing_consent'      => $config->marketingConsentEnabled,
            // Installation kill switch for reasons for votes (Config::$voteReasonsEnabled):
            // false hides the board toggle and the reason follow-up in the SPA.
            'vote_reasons'           => $config->voteReasonsEnabled,
        ]);
        $sessions = new SessionService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
            cookieDomain: $config->sessionCookieDomain,
            inactivityWindow: $config->sessionInactivityWindow,
        );
        $csrf     = new CsrfService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
        );
        $audit    = $auditLogger ?? new AuditLogger($root . '/logs/audit.log');
        // OWASP/BSI audit remediation issue 10: sampled authz.denied logging
        // for every AuthZMiddleware::deny() path — see AuthzDenialLogger's
        // class doc. No RateLimiter (and therefore unsampled logging) in the
        // DB-less smoke-test app, matching every other $conn-gated service
        // below.
        $authzDenialLogger = new AuthzDenialLogger($audit, $conn instanceof Connection ? new RateLimiter($conn) : null);
        // OWASP/BSI audit remediation issue 11: pseudonymized IP for the
        // security-critical login/password-reset audit events — same
        // identity_server_key as IdentityHasher/ViewDedupHasher, domain-
        // separated via the "audit-ip:" HMAC prefix (see AuditIpHasher).
        $auditIpHasher  = new AuditIpHasher($config->identityServerKey);
        $passwordPolicy = new PasswordPolicy($hibpHttpClient ?? new CurlHibpHttpClient());

        // UserRepository is already needed (with DB) for AuthN hydration.
        $userRepo = $conn instanceof Connection ? new UserRepository($conn) : null;

        // BlockRepository — account-wide targeted block,
        // complements the global users.is_blocked kill switch in BlockCheckMiddleware.
        $blockRepoForCheck = $conn instanceof Connection ? new BlockRepository($conn) : null;

        // --- Global PSR-15 pipeline -------------------------------------
        // Add order is the reverse of execution order (last added = outermost).
        // Execution outer → inner:
        //   Error → BodyParsing → SecurityHeader → RateLimit(IP) → RoutingMiddleware →
        //   AccountContext → Session → AuthN → BlockCheck → CSRF → [Route: AuthZ → Handler]
        $app->add(new CsrfMiddleware($csrf, $responseFactory, ExtensionLoader::csrfExemptions($extensions), $audit));
        $app->add(new BlockCheckMiddleware($responseFactory, $blockRepoForCheck));
        $app->add(new AuthNMiddleware($userRepo, $sessions));
        $app->add(new SessionMiddleware($sessions));

        // RateLimit(IP) + AccountContext only with a DB connection (board lookups
        // need the DB anyway; AccountContext resolves to the
        // default account (self-host runs exactly one account) or
        // — cloud mode, cloud path routing — to the
        // {account} path segment). RoutingMiddleware MUST run before AccountContext
        // (added later = further outside = runs first), otherwise the
        // {account} route argument isn't resolved yet in cloud mode.
        if ($conn instanceof Connection) {
            $app->add(new AccountContextMiddleware(new AccountRepository($conn), $config->routingMode));
            $app->addRoutingMiddleware();

            // anti-automation-challenge (issue 20): the requester's IP-based
            // "suspicion" counter, fed by the global perIp() rate limiter
            // right below (see TurnstileGuard's class doc for why the
            // global bucket rather than a dedicated one per guarded
            // action). Only constructed with a DB connection — the
            // guarded actions (idea create/vote/comment, wired further
            // down in the second `if ($conn instanceof Connection)` block
            // via BoardRoutes)
            // are themselves only ever registered there too.
            $turnstileGuard = new TurnstileGuard($config, new RateLimiter($conn), $turnstileHttpClient ?? new CurlTurnstileHttpClient());

            $ipLimit = $config->rateLimit('global:ip');
            $app->add(RateLimitMiddleware::perIp(
                new RateLimiter($conn),
                $responseFactory,
                'global',
                $ipLimit['limit'],
                $ipLimit['window'],
                $config->trustCloudflareIp,
                static function (string $ip) use ($audit, $auditIpHasher, $turnstileGuard): void {
                    $audit->log('global.rate_limited', ['ip_hmac' => $auditIpHasher->hash($ip)]);
                    $turnstileGuard->recordSuspicion($ip);
                },
            ));

        }

        $app->add(new SecurityHeaderMiddleware(ExtensionLoader::responseHeaders($extensions)));
        $app->addBodyParsingMiddleware();

        $reporter = $errorReporter ?? ($config->sentryDsn !== ''
            ? new SentryErrorReporter($config->sentryDsn, $config->env)
            : new NullErrorReporter());
        $errorMiddleware = $app->addErrorMiddleware(
            displayErrorDetails: $config->env === 'dev',
            logErrors: true,
            logErrorDetails: $config->env === 'dev',
        );
        $errorMiddleware->setDefaultErrorHandler(new ReportingErrorHandler(
            $app->getCallableResolver(),
            $responseFactory,
            $reporter,
        ));

        SystemRoutes::register($app, $responseFactory, $audit);

        // Login routes: only register with a DB connection.
        if ($conn instanceof Connection) {
            $userRepo ??= new UserRepository($conn); // already built above; ??= narrows the type
            $blockRepoForCheck ??= new BlockRepository($conn); // already built above; ??= narrows the type
            $hasher    = new IdentityHasher($config->identityServerKey);
            $tokenRepo = new LoginTokenRepository($conn);
            $boardRepo = new BoardRepository($conn);
            // P-28 board imagery: files live outside the webroot; deletion paths purge them.
            $boardAssetFiles   = new BoardAssetFileStore($boardAssetDirOverride ?? $root . '/storage/board-assets');
            $boardAssetCleanup = new BoardAssetCleanup(new BoardAssetRepository($conn), $boardAssetFiles);
            $vault     = new TokenVault();
            $smtpSettingsRepo = new SmtpSettingsRepository($conn);
            $encryptionSvc    = new EncryptionService($config->appKey);
            $boardSmtpRepo    = new BoardSmtpSettingsRepository($conn);
            // Trailing RateLimiter/ErrorReporter/AuditLogger args wire
            // SmtpConfigResolver::buildMailer()'s outbound-mail-volume
            // monitoring (review-2026-09-04-fixes item 15) — $reporter is
            // already built above (Sentry in prod, NullErrorReporter in
            // self-host), same seam every other background alert uses.
            $circuitBreaker = new CircuitBreaker($conn);
            $smtpResolver = new SmtpConfigResolver($smtpSettingsRepo, $boardSmtpRepo, $encryptionSvc, $config->smtp, $smtpHostPolicy, new RateLimiter($conn), $reporter, $audit, $circuitBreaker);
            $accountMemberRepo = new AccountMemberRepository($conn);
            // board-operator-compliance issue 10: repeat-offender escalation —
            // shared by the moderation actions (BoardRoutes) and the
            // manual-override endpoint (BoardAdminRoutes).
            $standingRepo    = new AccountMemberStandingRepository($conn);
            $standingService = new ModerationStandingService();

            // Password + TOTP 2FA: $loginSessionIssuer bundles the
            // "issue a real session" code path for all three login routes
            // (magic link/LoginVerifyAction, password/LoginPasswordAction,
            // second 2FA step/Login2faAction). $totpEncryption uses its
            // OWN EncryptionService context ('totp', not 'smtp') — key
            // separation from the existing SMTP secrets (see EncryptionService
            // class doc).
            $loginSessionIssuer = new LoginSessionIssuer($sessions, $accountMemberRepo, $config);
            // OAuth login (Google/GitHub) outbound HTTP seam — see create()'s
            // $oauthHttpClient doc comment.
            $oauthHttp          = $oauthHttpClient ?? new CurlOAuthHttpClient();
            $totp               = new Totp();
            $totpSetupToken     = new TotpSetupToken($config->appKey);
            $totpEncryption     = new EncryptionService($config->appKey, 'totp');
            $totpBackupCodeRepo = new TotpBackupCodeRepository($conn, new TotpBackupCodes());

            // Shared AccountRepository instance for every plan-limit gate
            // (board count, invite/team cap, Agent API) — all of them consult
            // $planPolicy for the actual limits.
            $accountRepoForPlan = new AccountRepository($conn);
            $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);

            // Cloud mode (cloud path routing): every account-/
            // board-scoped route (public board routes as well as everything under
            // AuthZ::accountAdmin()/accountOwner()) carries a leading
            // `/{account}` path segment — AccountContextMiddleware resolves it.
            // Global/identity-scoped routes (login/logout/invite/accept,
            // installation-wide AuthZ::admin(), bearer-token `/api/v1/*`)
            // deliberately stay UNPREFIXED, see the respective route below.
            // Self-host (default): $accountPrefix is '', paths remain exactly
            // as before — the lockstep invariant.
            $accountPrefix = $config->routingMode === 'cloud' ? '/{account}' : '';

            // Per-action rate limits for magic-link requests.
            $emailRateLimit = $config->rateLimit('magiclink:email');
            $mlIpRateLimit  = $config->rateLimit('magiclink:ip');

            // Shared across the auth, operator and account-admin route
            // groups below (each triggers a mail-based reset link).
            $passwordResetMailer = new PasswordResetMailer($tokenRepo, $vault, $mailer, $smtpResolver, $config);

            // GET /ready — readiness probe over the DB, every migration
            // stream (core + extensions) and the cron manager heartbeat.
            $migrationRunners = [new MigrationRunner($conn, $root . '/migrations', $config)];
            foreach (ExtensionLoader::migrationStreams($extensions) as $trackingTable => $migrationsDir) {
                $migrationRunners[] = new MigrationRunner($conn, $migrationsDir, $config, $trackingTable);
            }
            SystemRoutes::registerReadiness(
                $app,
                $responseFactory,
                new ReadinessCheck($conn, $migrationRunners, $config->readinessCronMaxAgeMinutes),
                $audit,
            );

            BootstrapRoutes::register(
                $app,
                $config,
                $responseFactory,
                $userRepo,
                $accountMemberRepo,
                $accountRepoForPlan,
                $planPolicy,
                $bootstrapFeatures,
            );

            // GET /api/v1/session/badge — signed-in header badge; group 1
            // (global, unprefixed), see SessionRoutes.
            SessionRoutes::register($app, $responseFactory, $config, $conn);

            AuthRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $auditIpHasher,
                $sessions,
                $userRepo,
                $hasher,
                $tokenRepo,
                $vault,
                $mailer,
                $smtpResolver,
                $boardRepo,
                $accountMemberRepo,
                $accountRepoForPlan,
                $loginSessionIssuer,
                $totp,
                $totpEncryption,
                $totpBackupCodeRepo,
                $passwordResetMailer,
                $emailRateLimit,
                $mlIpRateLimit,
                $oauthHttp,
                $passwordPolicy,
                $reporter,
                new MarketingConsentRepository($conn),
            );

            AccountSecurityRoutes::register(
                $app,
                $responseFactory,
                $audit,
                $authzDenialLogger,
                $sessions,
                $userRepo,
                $hasher,
                $totp,
                $totpSetupToken,
                $totpEncryption,
                $totpBackupCodeRepo,
                $passwordResetMailer,
                $passwordPolicy,
            );


            $ideaRepo      = new IdeaRepository($conn);
            $commentRepo   = new CommentRepository($conn);
            $moderation    = new ContentModerationService($root . '/resources/moderation');
            $modConfigRepo = new ModerationConfigRepository($conn);
            $tagRepo       = new TagRepository($conn);
            // Per-board status registry (migrations/0073_create_board_statuses.sql).
            $boardStatusRepo = new BoardStatusRepository($conn);
            // Reasoned status decisions / history (migration 0091, P-01).
            $statusDecisionRepo = new IdeaStatusDecisionRepository($conn);
            $timeTrap      = new TimeTrapService($config->appKey);
            $normalizer    = new TitleNormalizer();

            // idea-comment-translation: TranslationProvider resolved once
            // (fail-secure default: NullTranslationProvider unless
            // Config::translationConfigured() is true), mirrors the
            // "resolve once in AppFactory, inject into actions" shape
            // already used for $oauthHttp/$webhookDispatcher above.
            $translationRepo = new TranslationRepository($conn);
            // Circuit breaker only around the real outbound client — a test
            // that injects its own $translationHttpClient double controls
            // its own failure semantics directly.
            $resolvedTranslationHttpClient = $translationHttpClient
                ?? new CircuitBreakingTranslationHttpClient(new CurlTranslationHttpClient(), $circuitBreaker);
            $translationProvider = self::buildTranslationProvider($config, $resolvedTranslationHttpClient, $audit);

            // board-webhooks: own EncryptionService context ('board_webhook',
            // not 'smtp'/'totp') — key separation between at-rest secrets,
            // same discipline as the TOTP encryption above. WebhookUrlGuard
            // is ALWAYS restrictive (unlike SmtpHostPolicy) — see its class
            // doc for why self-host isn't exempt here.
            $webhookRepo       = new BoardWebhookRepository($conn);
            $webhookSecretEnc  = new EncryptionService($config->appKey, 'board_webhook');
            $webhookUrlGuard ??= new WebhookUrlGuard();
            $resolvedWebhookSender = $webhookSender ?? new CurlWebhookSender();
            // Slack/Discord chat notifications (issue 18): own encryption
            // context for the stored incoming-webhook URLs (bearer secrets),
            // fanned out through WebhookDispatcher so every board event
            // reaches chat as well.
            $chatRepo     = new BoardChatIntegrationRepository($conn);
            $chatUrlEnc   = new EncryptionService($config->appKey, 'board_chat_webhook');
            $chatNotifier = new ChatNotificationDispatcher(
                $chatRepo,
                $webhookUrlGuard,
                $resolvedWebhookSender,
                $chatUrlEnc,
                $planPolicy,
                $accountPlans,
                new ChatMessageFormatter($config->appUrl, $config->routingMode === 'cloud'),
                $webhookDeferRunner ?? WebhookDispatcher::defaultDeferRunner(),
                null,
                new RateLimiter($conn),
            );
            $webhookDispatcher = new WebhookDispatcher(
                $webhookRepo,
                $webhookUrlGuard,
                $resolvedWebhookSender,
                $webhookSecretEnc,
                $webhookDeferRunner,
                null,
                new RateLimiter($conn),
                $chatNotifier,
            );

            // FULLTEXT recall + Jaro–Winkler rerank collaborator for
            // the as-you-type duplicate search — pure Domain service, no DB/HTTP.
            $duplicateDetection = new DuplicateDetectionService($normalizer, new JaroWinklerSimilarity());

            // ---------------------------------------------------------------
            // Cloud-mode routing safety net (cloud path routing):
            // EVERY global/identity-scoped (unprefixed) route MUST be
            // registered before the FIRST {account}-prefixed (in cloud mode:
            // variable) route. Reason: FastRoute throws a hard BadRouteException
            // AT COMPILE TIME as soon as a later-registered
            // fully static route (e.g. `/invite/accept`, 2 segments)
            // syntactically collides with an ALREADY registered variable route
            // of the same segment count (`/([^/]+)/([^/]+)` in
            // cloud mode) — "Static route is shadowed by previously defined
            // variable route". This is purely registration order, not
            // runtime priority; that's why /invite/accept
            // and /api/v1/* (including their object construction) live HERE, before the first
            // $accountPrefix route below, instead of their original location
            // further down in the code. Self-host ($accountPrefix === '') is
            // unaffected (there, e.g. `/{board}` is only 1 segment, never in
            // conflict with the 2-segment system routes).
            // ---------------------------------------------------------------
            $inviteRepo = new InviteRepository($conn);
            // Terms/AUP/Privacy consent gate for SignupAccountAction
            // (board-operator-compliance ticket 04) — resolved HERE,
            // before ExtensionContext exists (see
            // AppExtension::termsAcceptanceGate() class doc for why it
            // takes $conn directly instead).
            $termsGate = ExtensionLoader::termsAcceptanceGate($extensions, $conn) ?? new NullTermsAcceptanceGate();
            InviteAndSignupRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $inviteRepo,
                $accountMemberRepo,
                $accountRepoForPlan,
                $vault,
                $boardRepo,
                $planPolicy,
                $termsGate,
            );

            // ---------------------------------------------------------------
            // Agent API / Votepit MCP: api_tokens admin
            // CRUD (session-authenticated) + Bearer-token-authenticated REST
            // endpoints under /api/v1 (a NEW trust boundary alongside the
            // session-cookie path — see ApiTokenAuthMiddleware class doc).
            // $apiTokenRepo/$apiTokenAuthenticator are shared beyond the
            // routes below (ExtensionContext, the /admin/tokens routes).
            // ---------------------------------------------------------------
            $apiTokenRepo          = new ApiTokenRepository($conn);
            $apiTokenAuthenticator = new ApiTokenAuthenticator($apiTokenRepo, $vault, $clock);

            // Shared by the Agent API write endpoints, the operator/
            // notifications/support routes and the idea/comment notification
            // fan-out further below (same table).
            $notificationRepo = new NotificationRepository($conn);

            // Idea status change execution (notification fan-out, status
            // mails incl. anonymous voters, audit, board webhook) — shared by
            // the moderator status route (IdeaRoutes), the Agent API status
            // endpoint (ApiV1Routes) and the inbound issue-tracker status
            // sync (IssueTrackerRoutes). Its mailer
            // shares the global notification-mail bucket with the comment
            // fan-out (RateLimiter buckets are keyed by string, not instance).
            $statusMailRateLimit = $config->rateLimit('notification-mail:global');
            $ideaStatusChanger   = new IdeaStatusChanger(
                $conn,
                $ideaRepo,
                $statusDecisionRepo,
                $audit,
                $notificationRepo,
                $userRepo,
                new VoteRepository($conn),
                $commentRepo,
                new CommentNotificationMailer(
                    new RateLimiter($conn),
                    $mailer,
                    $smtpResolver,
                    $config,
                    $statusMailRateLimit['limit'],
                    $statusMailRateLimit['window'],
                    BoardMailBranding::forConnection($conn, $accountRepoForPlan, $planPolicy),
                ),
                new EncryptionService($config->appKey, 'anon_vote_email'),
                new IdeaFollowRepository($conn),
                ParticipantMailRouter::forConfig($conn, $config),
                new AccountMailLinks(new AccountRepository($conn), $config->routingMode),
                $blockRepoForCheck,
                $webhookDispatcher,
            );

            ApiV1Routes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $ideaRepo,
                $normalizer,
                $moderation,
                $modConfigRepo,
                $boardRepo,
                $apiTokenAuthenticator,
                $clock,
                $ideaStatusChanger,
                $commentRepo,
                $tagRepo,
                $boardStatusRepo,
                $accountMemberRepo,
                $standingRepo,
                $blockRepoForCheck,
                $notificationRepo,
                $userRepo,
                $mailer,
                $smtpResolver,
                $webhookDispatcher,
                $duplicateDetection,
                $turnstileHttpClient,
            );

            // MCP OAuth 2.1 authorization server (issue 21): discovery
            // metadata, dynamic client registration, token/revocation and
            // the consent endpoints behind the SPA page /oauth/authorize.
            // Its grants are api_tokens rows, so the access tokens it
            // issues authenticate through $apiTokenAuthenticator above.
            OAuthServerRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $vault,
                $clock,
                $apiTokenRepo,
                $accountRepoForPlan,
                $accountMemberRepo,
                $userRepo,
                $boardRepo,
                $planPolicy,
            );

            // Linear/GitHub issue links (issue 19). Own encryption contexts
            // for the API token and the webhook signing secret (key
            // separation). The inbound status-sync webhooks are unprefixed
            // (group 1, FastRoute ordering); the admin routes follow
            // BoardIntegrationRoutes below.
            $issueTrackerRepo       = new IssueTrackerConnectionRepository($conn);
            $issueLinkRepo          = new IdeaIssueLinkRepository($conn);
            $issueTrackerTokenEnc   = new EncryptionService($config->appKey, 'issue_tracker_token');
            $issueTrackerSecretEnc  = new EncryptionService($config->appKey, 'issue_tracker_webhook');
            $issueTrackerApi        = new IssueTrackerApi($issueTrackerHttpClient ?? new CurlIssueTrackerHttpClient($webhookUrlGuard));
            IssueTrackerRoutes::registerWebhooks(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $planPolicy,
                $accountPlans,
                $issueTrackerRepo,
                $issueTrackerSecretEnc,
                new IssueStatusSync($issueLinkRepo, $boardRepo, $ideaRepo, $boardStatusRepo, $ideaStatusChanger),
            );

            OperatorRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountRepoForPlan,
                $boardRepo,
                $ideaRepo,
                $userRepo,
                $hasher,
                $passwordResetMailer,
                $mailer,
                $smtpResolver,
                $notificationRepo,
                $reporter,
                $boardAssetCleanup,
            );

            NotificationRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $authzDenialLogger,
                $notificationRepo,
                $translationRepo,
                $translationProvider,
            );

            $supportRequestAction = SupportRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountRepoForPlan,
                $userRepo,
                $mailer,
                $smtpResolver,
                $notificationRepo,
                $translationRepo,
                $translationProvider,
            );

            DiscoveryRoutes::register(
                $app,
                $responseFactory,
                $boardRepo,
            );

            // ---------------------------------------------------------------
            // Extensions (AppExtension::register()) — registered HERE so an
            // extension's global/unprefixed routes (e.g. a payment provider's
            // signed webhook, which has no concept of this app's {account}
            // path segment) land before the first $accountPrefix route, for
            // the same FastRoute-collision reason as /invite/accept, /api/v1/*
            // and /operator/* above (Cloud-Routing safety net, see comment
            // block further up). Account-scoped extension routes prepend
            // $ctx->accountPrefix themselves. The global PSR-15 pipeline
            // applies to every extension route unchanged.
            // ---------------------------------------------------------------
            $extensionContext = new ExtensionContext(
                $app,
                $config,
                $conn,
                $responseFactory,
                $audit,
                $planPolicy,
                $accountPrefix,
                $accountRepoForPlan,
                $accountMemberRepo,
                $boardRepo,
                $apiTokenRepo,
                $userRepo,
                $loginSessionIssuer,
            );
            foreach ($extensions as $extension) {
                $extension->register($extensionContext);
            }
            $deletionPrecondition = ExtensionLoader::accountDeletionPrecondition($extensions, $extensionContext)
                ?? new NullAccountDeletionPrecondition();

            // Board-operator acknowledgment gate for BoardCreateAction
            // (board-operator-compliance ticket 05) — resolved via the full
            // ExtensionContext (unlike $termsGate above), since POST
            // /admin/boards is registered well after ExtensionContext exists
            // (see BoardOperatorAcknowledgmentGate class doc).
            $boardOperatorAckGate = ExtensionLoader::boardOperatorAcknowledgmentGate($extensions, $extensionContext)
                ?? new NullBoardOperatorAcknowledgmentGate();

            // Ongoing board-operator-compliance enforcer (board-operator-
            // compliance follow-up, 2026-09-09) — re-evaluates public-facing
            // boards after AccountRenameAction, BoardOperatorAcknowledgmentAction
            // and BoardBrandingAction. See BoardComplianceEnforcer class doc.
            $boardComplianceEnforcer = ExtensionLoader::boardComplianceEnforcer($extensions, $extensionContext)
                ?? new NullBoardComplianceEnforcer();

            // Optional confirmation of duplicate suggestions (competitor-
            // parity issue 11). null = deterministic suggestions only (the
            // Community default) — see DuplicateConfirmer.
            $duplicateConfirmer = ExtensionLoader::duplicateConfirmer($extensions, $extensionContext);

            // Optional AI tag suggestions for the submit form (P-35). null = none.
            $tagSuggester = ExtensionLoader::tagSuggester($extensions, $extensionContext);

            // Participant mails (re-engagement B/C/F): cadence preferences,
            // one-click unsubscribe, idea follow.
            ParticipantMailRoutes::register($app, $config, $responseFactory, $conn, $audit, $authzDenialLogger, $accountPrefix);

            ProfileRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $root,
                $avatarDirOverride,
                $userRepo,
                $accountMemberRepo,
                $ideaRepo,
                $accountPrefix,
                $vault,
                $mailer,
                $smtpResolver,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
            );

            // Guest idea submission gate (ADR 0006): flag ∧ Turnstile ∧ plan.
            $anonymousSubmissionGate = new AnonymousSubmissionGate($config, $accountPlans, $planPolicy);

            // Operator worklist (P-06): before BoardRoutes, see WorklistRoutes.
            WorklistRoutes::register($app, $responseFactory, $accountPrefix, $boardRepo, $ideaRepo, $userRepo, $accountMemberRepo, $authzDenialLogger, $clock);

            BoardRoutes::register(
                $app,
                $responseFactory,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $ideaRepo,
                $tagRepo,
                $boardOperatorAckGate,
                $boardStatusRepo,
                $clock,
                $anonymousSubmissionGate,
                new BoardAssetRepository($conn),
            );

            $ideaActions = IdeaRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $accountMemberRepo,
                $ideaRepo,
                $commentRepo,
                $timeTrap,
                $duplicateDetection,
                $normalizer,
                $moderation,
                $blockRepoForCheck,
                $modConfigRepo,
                $notificationRepo,
                $userRepo,
                $mailer,
                $smtpResolver,
                $tagRepo,
                $webhookDispatcher,
                $standingRepo,
                $standingService,
                $turnstileHttpClient,
                $boardStatusRepo,
                $clock,
                $ideaStatusChanger,
                $statusDecisionRepo,
                $anonymousSubmissionGate,
                $duplicateConfirmer,
                $tagSuggester,
                $planPolicy,
            );

            TagRoutes::register(
                $app,
                $responseFactory,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountMemberRepo,
                $ideaRepo,
                $tagRepo,
            );

            $commentCreateAction = CommentRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $accountMemberRepo,
                $ideaRepo,
                $commentRepo,
                $moderation,
                $blockRepoForCheck,
                $modConfigRepo,
                $notificationRepo,
                $userRepo,
                $mailer,
                $smtpResolver,
                $standingRepo,
                $standingService,
                $turnstileHttpClient,
                $boardStatusRepo,
                BoardMailBranding::forConnection($conn, $accountRepoForPlan, $planPolicy),
            );

            // Embeddable widget (ADR 0005) — reuses the submit/vote/comment
            // action instances above behind the widget-token guard.
            WidgetRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $widgetShellPath ?? $root . '/public/widget.html',
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $ideaRepo,
                $tagRepo,
                $boardStatusRepo,
                $userRepo,
                $blockRepoForCheck,
                $hasher,
                $ideaActions['idea_create'],
                $ideaActions['vote'],
                $commentCreateAction,
                $clock,
            );

            RoadmapRoutes::register(
                $app,
                $responseFactory,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $ideaRepo,
                $boardStatusRepo,
                new BoardAssetRepository($conn),
            );

            TranslationRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $ideaRepo,
                $commentRepo,
                $translationRepo,
                $translationProvider,
            );

            BoardSettingsRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $userRepo,
                $modConfigRepo,
                $moderation,
                $boardOperatorAckGate,
                $boardComplianceEnforcer,
                $boardStatusRepo,
                $clock,
                $anonymousSubmissionGate,
                $duplicateConfirmer,
                ($bootstrapFeatures['billing'] ?? null) === true,
                $boardAssetCleanup,
            );

            $uploadTermsVersion = ExtensionLoader::uploadTermsVersion($extensions);
            BoardAssetRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $boardAssetFiles,
                $uploadTermsVersion,
            );

            MemberRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $inviteRepo,
                $userRepo,
                $hasher,
                $passwordResetMailer,
                $mailer,
                $smtpResolver,
                $vault,
                $standingRepo,
                $reporter,
            );

            AccountAdminRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $accountMemberRepo,
                $inviteRepo,
                $userRepo,
                $ideaRepo,
                $commentRepo,
                $apiTokenRepo,
                $boardSmtpRepo,
                $modConfigRepo,
                $deletionPrecondition,
                $supportRequestAction,
                $boardComplianceEnforcer,
            );

            BoardIntegrationRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountMemberRepo,
                $smtpResolver,
                $boardSmtpRepo,
                $encryptionSvc,
                $smtpHostPolicy,
                $webhookRepo,
                $webhookUrlGuard,
                $webhookSecretEnc,
                $accountRepoForPlan,
                $planPolicy,
                $chatRepo,
                $chatUrlEnc,
            );

            IssueTrackerRoutes::registerAdmin(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $accountMemberRepo,
                $accountRepoForPlan,
                $boardRepo,
                $ideaRepo,
                $planPolicy,
                $issueTrackerRepo,
                $issueLinkRepo,
                $issueTrackerApi,
                $issueTrackerTokenEnc,
                $issueTrackerSecretEnc,
            );

            BoardImportRoutes::register(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountMemberRepo,
                $ideaRepo,
                $tagRepo,
                $normalizer,
            );

            ApiTokenAdminRoutes::register(
                $app,
                $responseFactory,
                $audit,
                $authzDenialLogger,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
                $accountMemberRepo,
                $apiTokenRepo,
                $apiTokenAuthenticator,
            );

            // Extension middleware on core-owned routes (CoreRoute) — applied
            // LAST, once every route exists, so it sits outermost on the
            // route (outside AuthZ and the per-action rate limit). A name
            // whose route is not registered in this installation (e.g.
            // BOARD_SMTP_TEST in cloud mode) is a configuration error, not
            // a silently missing guard.
            // ---------------------------------------------------------------
            $routeCollector = $app->getRouteCollector();
            foreach (ExtensionLoader::routeMiddleware($extensions, $extensionContext) as $routeName => $middlewares) {
                try {
                    $route = $routeCollector->getNamedRoute($routeName);
                } catch (\RuntimeException) {
                    throw new ConfigException("config: extension attaches middleware to core route \"{$routeName}\", which is not registered in routing_mode \"{$config->routingMode}\".");
                }
                // The widget twin of a core write route (WidgetRoutes::TWINS)
                // gets the same extension middleware — no bypass via the
                // embed path.
                $twin = isset(WidgetRoutes::TWINS[$routeName]) ? $routeCollector->getNamedRoute(WidgetRoutes::TWINS[$routeName]) : null;
                foreach ($middlewares as $middleware) {
                    $route->add($middleware);
                    $twin?->add($middleware);
                }
            }
        }

        return $app;
    }

    /**
     * Resolves the TranslationProvider once — NullTranslationProvider
     * (fail-secure default) unless Config::translationConfigured() is
     * true, in which case the configured GCP service-account key file is
     * read and a real GoogleTranslateProvider is built. A key file that
     * cannot be read or parsed is treated the same as "not configured"
     * (fail-secure — a broken credentials file must never turn into a
     * 500, only into the feature staying unavailable), logged via $audit
     * without ever including the file's contents.
     */
    private static function buildTranslationProvider(
        Config $config,
        TranslationHttpClient $translationHttpClient,
        AuditLogger $audit,
    ): TranslationProvider {
        if (!$config->translationConfigured()) {
            return new NullTranslationProvider();
        }

        $google = $config->translationGoogleConfig();

        $raw = @file_get_contents($google['service_account_key']);
        if ($raw === false) {
            $audit->log('translation.config_error', ['reason' => 'service_account_key_unreadable']);
            return new NullTranslationProvider();
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['client_email'] ?? null)
            || !is_string($decoded['private_key'] ?? null)
            || $decoded['client_email'] === ''
            || $decoded['private_key'] === ''
        ) {
            $audit->log('translation.config_error', ['reason' => 'service_account_key_malformed']);
            return new NullTranslationProvider();
        }

        return new GoogleTranslateProvider(
            $translationHttpClient,
            $google['project_id'],
            $decoded['client_email'],
            $decoded['private_key'],
            $google['region'],
            $audit,
        );
    }
}
