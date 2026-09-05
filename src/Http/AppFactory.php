<?php

declare(strict_types=1);

namespace Votepit\Http;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\ConfigException;
use Votepit\Domain\AccountExportService;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\JaroWinklerSimilarity;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\StatusService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Extension\AccountDeletionPrecondition;
use Votepit\Extension\AppExtension;
use Votepit\Extension\ExtensionContext;
use Votepit\Extension\ExtensionLoader;
use Votepit\Extension\NullAccountDeletionPrecondition;
use Votepit\Http\Action\AbuseReportAction;
use Votepit\Http\Action\AccountDeleteAction;
use Votepit\Http\Action\AccountExportAction;
use Votepit\Http\Action\AccountPasswordResetAction;
use Votepit\Http\Action\AccountProfileAction;
use Votepit\Http\Action\AccountRenameAction;
use Votepit\Http\Action\AccountSettingsAction;
use Votepit\Http\Action\ApiBoardAction;
use Votepit\Http\Action\ApiIdeaAction;
use Votepit\Http\Action\ApiTokenAction;
use Votepit\Http\Action\AvatarServeAction;
use Votepit\Http\Action\BoardActiveSetAction;
use Votepit\Http\Action\BoardBrandingAction;
use Votepit\Http\Action\BoardCreateAction;
use Votepit\Http\Action\BoardDeleteAction;
use Votepit\Http\Action\BoardDiscoveryAction;
use Votepit\Http\Action\BoardHomeAction;
use Votepit\Http\Action\BoardListAction;
use Votepit\Http\Action\BoardModerationAction;
use Votepit\Http\Action\BoardRenameAction;
use Votepit\Http\Action\BoardRoadmapAction;
use Votepit\Http\Action\BoardSmtpAction;
use Votepit\Http\Action\BoardSmtpTestAction;
use Votepit\Http\Action\CommentCreateAction;
use Votepit\Http\Action\CommentModerationAction;
use Votepit\Http\Action\CommentUpdateAction;
use Votepit\Http\Action\DefaultBoardAction;
use Votepit\Http\Action\FaqAction;
use Votepit\Http\Action\IdeaCreateAction;
use Votepit\Http\Action\IdeaDetailAction;
use Votepit\Http\Action\IdeaEditAction;
use Votepit\Http\Action\IdeaNewAction;
use Votepit\Http\Action\IdeaPinAction;
use Votepit\Http\Action\IdeaSearchDuplicatesAction;
use Votepit\Http\Action\IdeaStatusAction;
use Votepit\Http\Action\IdeaWithdrawAction;
use Votepit\Http\Action\InviteAcceptAction;
use Votepit\Http\Action\InviteAction;
use Votepit\Http\Action\Login2faAction;
use Votepit\Http\Action\LoginPasswordAction;
use Votepit\Http\Action\LoginVerifyAction;
use Votepit\Http\Action\McpAction;
use Votepit\Http\Action\MemberAction;
use Votepit\Http\Action\NotificationAction;
use Votepit\Http\Action\NotificationPreferencesAction;
use Votepit\Http\Action\OnboardingCompleteAction;
use Votepit\Http\Action\OperatorAccountAction;
use Votepit\Http\Action\OperatorAnnouncementAction;
use Votepit\Http\Action\OperatorBoardAction;
use Votepit\Http\Action\OperatorUsageAction;
use Votepit\Http\Action\OperatorUserAction;
use Votepit\Http\Action\PasswordResetConfirmAction;
use Votepit\Http\Action\PasswordResetRequestAction;
use Votepit\Http\Action\PublicProfileAction;
use Votepit\Http\Action\SetPasswordAction;
use Votepit\Http\Action\SignupAccountAction;
use Votepit\Http\Action\SupportRequestAction;
use Votepit\Http\Action\TelemetryOptInAction;
use Votepit\Http\Action\TotpAction;
use Votepit\Http\Action\UserBlockAction;
use Votepit\Http\Action\VoteAction;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\BlockCheckMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Http\Support\LoginBoardResolver;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Monitoring\NullErrorReporter;
use Votepit\Monitoring\SentryErrorReporter;
use Votepit\Persistence\AbuseReportRepository;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\FaqRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\NotificationEmailVerificationRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\UserSocialLinkRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\ApiTokenAuthenticator;
use Votepit\Security\AvatarProcessor;
use Votepit\Security\ClientIp;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdeaViewTracker;
use Votepit\Security\IdentityHasher;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\RateLimiter;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\SessionService;
use Votepit\Security\SmtpHostPolicy;
use Votepit\Security\TimeTrapService;
use Votepit\Security\TokenVault;
use Votepit\Security\Totp;
use Votepit\Security\TotpBackupCodes;
use Votepit\Security\TotpSetupToken;
use Votepit\Security\ViewDedupHasher;

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
        // Plan-derived limits. Community Edition default: no limits at all
        // (UnrestrictedPlanPolicy); resolved from the extensions unless a
        // test injects one explicitly.
        ?PlanPolicy $planPolicy = null,
        // Extensions (Votepit\Extension\AppExtension). null = build them from
        // Config::$extensions (production); tests pass an explicit list.
        ?array $extensions = null,
    ): App {
        $responseFactory = new ResponseFactory();

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
        ]);
        $sessions = new SessionService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
            cookieDomain: $config->sessionCookieDomain,
        );
        $csrf     = new CsrfService(
            appKey: $config->appKey,
            lifetime: $config->sessionLifetime,
            secure: $secure,
        );
        $audit    = $auditLogger ?? new AuditLogger($root . '/logs/audit.log');

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
        $app->add(new CsrfMiddleware($csrf, $responseFactory, ExtensionLoader::csrfExemptions($extensions)));
        $app->add(new BlockCheckMiddleware($responseFactory, $blockRepoForCheck));
        $app->add(new AuthNMiddleware($userRepo));
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

            $ipLimit = $config->rateLimit('global:ip');
            $app->add(RateLimitMiddleware::perIp(
                new RateLimiter($conn),
                $responseFactory,
                'global',
                $ipLimit['limit'],
                $ipLimit['window'],
                $config->trustCloudflareIp,
            ));

        }

        $app->add(new SecurityHeaderMiddleware(ExtensionLoader::responseHeaders($extensions)));
        $app->addBodyParsingMiddleware();

        $reporter = $config->sentryDsn !== ''
            ? new SentryErrorReporter($config->sentryDsn, $config->env)
            : new NullErrorReporter();
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

        self::registerSystemRoutes($app, $responseFactory, $audit);

        // Login routes: only register with a DB connection.
        if ($conn instanceof Connection) {
            $userRepo ??= new UserRepository($conn); // already built above; ??= narrows the type
            $blockRepoForCheck ??= new BlockRepository($conn); // already built above; ??= narrows the type
            $hasher    = new IdentityHasher($config->identityServerKey);
            $tokenRepo = new LoginTokenRepository($conn);
            $boardRepo = new BoardRepository($conn);
            $vault     = new TokenVault();
            $smtpSettingsRepo = new SmtpSettingsRepository($conn);
            $encryptionSvc    = new EncryptionService($config->appKey);
            $boardSmtpRepo    = new BoardSmtpSettingsRepository($conn);
            // Trailing RateLimiter/ErrorReporter/AuditLogger args wire
            // SmtpConfigResolver::buildMailer()'s outbound-mail-volume
            // monitoring (review-2026-09-04-fixes item 15) — $reporter is
            // already built above (Sentry in prod, NullErrorReporter in
            // self-host), same seam every other background alert uses.
            $smtpResolver = new SmtpConfigResolver($smtpSettingsRepo, $boardSmtpRepo, $encryptionSvc, $config->smtp, $smtpHostPolicy, new RateLimiter($conn), $reporter, $audit);
            $accountMemberRepo = new AccountMemberRepository($conn);

            // Password + TOTP 2FA: $loginSessionIssuer bundles the
            // "issue a real session" code path for all three login routes
            // (magic link/LoginVerifyAction, password/LoginPasswordAction,
            // second 2FA step/Login2faAction). $totpEncryption uses its
            // OWN EncryptionService context ('totp', not 'smtp') — key
            // separation from the existing SMTP secrets (see EncryptionService
            // class doc).
            $loginSessionIssuer = new LoginSessionIssuer($sessions, $accountMemberRepo, $config);
            $totp               = new Totp();
            $totpSetupToken     = new TotpSetupToken($config->appKey);
            $totpEncryption     = new EncryptionService($config->appKey, 'totp');
            $totpBackupCodeRepo = new TotpBackupCodeRepository($conn, new TotpBackupCodes());

            // Shared AccountRepository instance for every plan-limit gate
            // (board count, invite/team cap, Agent API) — all of them consult
            // $planPolicy for the actual limits.
            $accountRepoForPlan = new AccountRepository($conn);

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

            self::registerAuthRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
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
                $bootstrapFeatures,
                $loginSessionIssuer,
                $totp,
                $totpSetupToken,
                $totpEncryption,
                $totpBackupCodeRepo,
                $passwordResetMailer,
                $emailRateLimit,
                $mlIpRateLimit,
            );


            $ideaRepo      = new IdeaRepository($conn);
            $commentRepo   = new CommentRepository($conn);
            $moderation    = new ContentModerationService($root . '/resources/moderation');
            $modConfigRepo = new ModerationConfigRepository($conn);
            $timeTrap      = new TimeTrapService($config->appKey);
            $normalizer    = new TitleNormalizer();

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
            self::registerInviteAndSignupRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $inviteRepo,
                $accountMemberRepo,
                $accountRepoForPlan,
                $vault,
                $boardRepo,
                $planPolicy,
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
            $apiTokenAuthenticator = new ApiTokenAuthenticator($apiTokenRepo, $vault);

            self::registerApiTokenRoutes(
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
            );

            // Shared by the operator/notifications/support routes and the
            // idea/comment notification fan-out further below (same table).
            $notificationRepo = new NotificationRepository($conn);

            $supportRequestAction = self::registerOperatorRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $accountRepoForPlan,
                $boardRepo,
                $ideaRepo,
                $userRepo,
                $hasher,
                $passwordResetMailer,
                $mailer,
                $smtpResolver,
                $notificationRepo,
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

            self::registerProfileRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $root,
                $avatarDirOverride,
                $userRepo,
                $accountMemberRepo,
                $ideaRepo,
                $accountPrefix,
                $vault,
                $mailer,
                $smtpResolver,
            );

            self::registerBoardRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $accountPrefix,
                $boardRepo,
                $accountRepoForPlan,
                $planPolicy,
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
            );

            self::registerBoardAdminRoutes(
                $app,
                $config,
                $responseFactory,
                $conn,
                $audit,
                $accountPrefix,
                $boardRepo,
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
                $ideaRepo,
                $commentRepo,
                $apiTokenRepo,
                $apiTokenAuthenticator,
                $boardSmtpRepo,
                $modConfigRepo,
                $encryptionSvc,
                $smtpHostPolicy,
                $deletionPrecondition,
                $supportRequestAction,
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
                foreach ($middlewares as $middleware) {
                    $route->add($middleware);
                }
            }
        }

        return $app;
    }

    /**
     * Smoke/liveness/robots — anon, no DB required.
     *
     * @param App<null> $app
     */
    private static function registerSystemRoutes(App $app, ResponseFactory $responseFactory, AuditLogger $audit): void
    {
        // --- Routes -------------------------------------------------------
        // Smoke route: proves boot + pipeline + security headers.
        $app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($audit): ResponseInterface {
            $audit->log('smoke.hit', ['ua' => $request->getHeaderLine('User-Agent')]);
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $response->getBody()->write((string) json_encode([
                'ok'               => true,
                'status'           => 'Security foundation active.',
                'csrf_token'       => is_string($csrfToken) ? $csrfToken : '',
                'is_authenticated' => $user !== null,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // GET /health — dedicated liveness endpoint for uptime monitoring/load
        // balancers: unlike `/`, this is a documented, stable contract, not
        // the informal smoke route — no audit logging (would spam the log on
        // every health-check interval) and no DB touch (liveness only, not readiness — a DB
        // outage should surface as failing application routes, not as this
        // probe flapping the process supervisor/load balancer).
        $app->get('/health', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // GET /robots.txt — everything allowed (an installation is crawlable by
        // default; the SPA's public board pages are meant to be found). Named
        // so an extension can replace the policy (CoreRoute::ROBOTS_TXT).
        $app->get('/robots.txt', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $response->getBody()->write("User-agent: *\nDisallow:\n");
            return $response->withStatus(200)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        })->setName(CoreRoute::ROBOTS_TXT)->add(AuthZMiddleware::anon($responseFactory));

    }

    /**
     * Bootstrap + magic-link login/logout + password login + TOTP 2FA +
     * password reset + account password/TOTP self-service.
     *
     * @param App<null>                      $app
     * @param array<string, int|string|null> $bootstrapFeatures
     * @param array{limit: int, window: int} $emailRateLimit
     * @param array{limit: int, window: int} $mlIpRateLimit
     */
    private static function registerAuthRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        SessionService $sessions,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        LoginTokenRepository $tokenRepo,
        TokenVault $vault,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        BoardRepository $boardRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepoForPlan,
        array $bootstrapFeatures,
        LoginSessionIssuer $loginSessionIssuer,
        Totp $totp,
        TotpSetupToken $totpSetupToken,
        EncryptionService $totpEncryption,
        TotpBackupCodeRepository $totpBackupCodeRepo,
        PasswordResetMailer $passwordResetMailer,
        array $emailRateLimit,
        array $mlIpRateLimit,
    ): void {
        // GET /api/bootstrap — CSRF token + whoami for the SPA (AuthZ: anon).
        // Returns the current CSRF token and the logged-in user to the SPA.
        // Must be called by the SPA at startup, before any mutating requests are sent.
        $app->get('/api/bootstrap', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($config, $accountMemberRepo, $userRepo, $bootstrapFeatures, $accountRepoForPlan): ResponseInterface {
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $userPayload = null;
            if (is_array($user)) {
                $userId = (int) ($user['id'] ?? 0);

                // Only the boolean flags, never password_hash/totp_secret_encrypted
                // themselves — the SPA needs this exclusively to
                // render the profile security section correctly (password
                // already set? 2FA already active?).
                $credentials  = $userRepo->findByIdWithCredentials($userId);
                $hasPassword  = is_array($credentials) && is_string($credentials['password_hash'] ?? null);
                $totpEnabled  = is_array($credentials) && is_string($credentials['totp_enabled_at'] ?? null);
                $avatarFilename = is_array($credentials) && is_string($credentials['avatar_filename'] ?? null)
                    ? $credentials['avatar_filename']
                    : null;

                $userPayload = [
                    'id'           => $userId,
                    // Random opaque handle (migrations/0036-0038), safe to
                    // display — the raw auto-increment id above stays
                    // internal-only (used as an API key for scoped
                    // mutations the caller is already authorized for,
                    // never rendered as text).
                    'public_id'    => is_array($credentials) && is_string($credentials['public_id'] ?? null)
                        ? $credentials['public_id']
                        : null,
                    // Optional, globally unique display name (migration
                    // 0022) — the SPA's session/account menu prefers this
                    // over public_id/account-slug wherever it needs to
                    // show "who is signed in" independent of which
                    // account/page is currently open.
                    'username'     => is_array($credentials) && is_string($credentials['username'] ?? null)
                        ? $credentials['username']
                        : null,
                    'is_admin'     => (bool) ($user['is_admin'] ?? false),
                    'is_operator'  => (bool) ($user['is_operator'] ?? false),
                    'is_support'   => (bool) ($user['is_support'] ?? false),
                    // Test-User feature: dedicated QA/E2E account, never a
                    // real customer — the SPA uses this to skip Matomo
                    // analytics entirely (App.tsx), server-side this flag
                    // also exempts the request from rate limiting (see
                    // RateLimitMiddleware::process()).
                    'is_test_account' => (bool) ($user['is_test_account'] ?? false),
                    'has_password' => $hasPassword,
                    'totp_enabled' => $totpEnabled,
                    // profile-avatar-social: piggybacked on the same
                    // per-user row already fetched above for
                    // has_password/totp_enabled, so the header's account
                    // menu (rendered on every page from bootstrap()) can
                    // show the real avatar without a second round-trip.
                    'avatar_url'   => $avatarFilename !== null ? '/avatar/' . $avatarFilename : null,
                    // profile-visibility feature: own current privacy
                    // setting, so ProfilePage's Privacy toggle renders
                    // correctly without an extra round-trip. Default is
                    // anonymous (migration 0021).
                    'profile_visible' => is_array($credentials) && (bool) ($credentials['profile_visible'] ?? false),
                    // Account role per slug (owner|moderator), NOT the
                    // platform admin flag above — the SPA uses this to
                    // correctly gate the admin UI for the current account
                    // slug (previously incorrectly used is_admin).
                    'memberships' => $accountMemberRepo->membershipsWithSlugFor($userId),
                ];
            }

            $response->getBody()->write((string) json_encode([
                'csrf_token'            => is_string($csrfToken) ? $csrfToken : '',
                'user'                  => $userPayload,
                // Tells the SPA whether to expect an /{account}-prefixed path
                // segment on board-/admin-scoped routes (Config::routingMode,
                // cloud path routing) — see AccountContextMiddleware
                // and $accountPrefix above. Not sensitive: same value for every
                // visitor of this installation.
                'routing_mode'          => $config->routingMode,
                // Client-facing DSN for @sentry/react (main.tsx) — '' disables
                // it (self-host default). Not sensitive: a Sentry DSN only
                // authorizes sending events, same value for every visitor.
                'sentry_dsn_frontend'   => $config->sentryDsnFrontend,
                // This installation's OWN optional analytics (Config
                // doc comment) — '' disables it, same "public,
                // authorizes sending only" reasoning as the Sentry DSN
                // above.
                'matomo_url'            => $config->matomoUrl,
                'matomo_site_id'        => $config->matomoSiteId,
                // Product-improvement telemetry (self-host only, see
                // Votepit\Telemetry\CommunityTelemetry) — null in cloud
                // mode, the SPA never renders the consent step there.
                // matomo_site_id stays '' (inert) until both the
                // account opted in AND CommunityTelemetry::MATOMO_SITE_ID
                // has been filled in.
                'telemetry'             => $config->routingMode === 'self-host'
                    ? (function () use ($accountRepoForPlan): array {
                        $account  = $accountRepoForPlan->findById($accountRepoForPlan->defaultAccountId());
                        $optedIn  = is_array($account) && (bool) ($account['telemetry_opted_in'] ?? false);
                        $decided  = is_array($account) && $account['telemetry_decided_at'] !== null;
                        return [
                            'decided'        => $decided,
                            'opted_in'       => $optedIn,
                            'matomo_url'     => $optedIn ? \Votepit\Telemetry\CommunityTelemetry::MATOMO_URL : '',
                            'matomo_site_id' => $optedIn ? \Votepit\Telemetry\CommunityTelemetry::MATOMO_SITE_ID : '',
                        ];
                    })()
                    : null,
                // Edition/extension feature flags (see $bootstrapFeatures
                // above). Not sensitive: same value for every visitor.
                'features'              => $bootstrapFeatures,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // GET /login — SPA route: returns validated return_to path (AuthZ: anon).
        $app->get('/login', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $params   = $request->getQueryParams();
            $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
            $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '';
            $response->getBody()->write((string) json_encode([
                'ok'        => true,
                'return_to' => $returnTo,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // POST /login — processes the email, sends the magic link (AuthZ: anon).
        // Response is ALWAYS identical (anti-enumeration; AC 3 & 4).
        $app->post('/login', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($userRepo, $hasher, $tokenRepo, $vault, $mailer, $smtpResolver, $boardRepo, $audit, $config, $accountMemberRepo, $accountRepoForPlan): ResponseInterface {
            $parsed    = $request->getParsedBody();
            $rawEmail  = is_array($parsed) ? (string) ($parsed['email'] ?? '') : '';
            $email     = strtolower(trim($rawEmail));
            $rawR      = is_array($parsed) ? (string) ($parsed['r'] ?? '') : '';
            $returnTo  = ReturnToValidator::isValid($rawR) ? $rawR : '';

            // Only send when syntax is valid — neutral handling (no 4xx).
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                // Email is only held transiently for hashing + mail sending (ADR 0002) —
                // never stored in the DB; findByEmailHmac()/create() only ever see the HMAC.
                $emailHmac = $hasher->hash($email);
                $user      = $userRepo->findByEmailHmac($emailHmac) ?? $userRepo->create($emailHmac);

                $tokenRepo->deleteOpenForUser((int) $user['id']);

                $pair      = $vault->generate();
                $expiresAt = (new \DateTimeImmutable('+' . $config->magicLinkTtl . ' seconds'))
                    ->format('Y-m-d H:i:s');
                $tokenRepo->insert((int) $user['id'], $pair['hash'], $expiresAt);

                $link = $config->appUrl . '/login/verify?token=' . $pair['token'];
                if ($returnTo !== '') {
                    $link .= '&r=' . rawurlencode($returnTo);
                }

                // Resolve per-board SMTP: see LoginBoardResolver's class doc — cloud-mode
                // returnTo is /{accountSlug}/{boardSlug}/..., so the account can't come from
                // the request attribute here (/login has no {account} route argument, that
                // attribute always falls back to defaultAccountId()) and must be parsed out
                // of returnTo itself.
                $boardId = LoginBoardResolver::resolve(
                    $returnTo,
                    $config->routingMode,
                    $boardRepo,
                    $accountRepoForPlan,
                    (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID),
                );
                $mailToUse = $mailer ?? $smtpResolver->buildMailer($boardId);

                $loginMail = MailTemplate::render(
                    'Your login link',
                    ['Hello,', 'here is your login link:'],
                    $link,
                    'Log in now',
                    ['The link is valid for 15 minutes.', 'Please do not share it.'],
                );
                $mailToUse->send(
                    $email,
                    'Your Votepit login link',
                    $loginMail['text'],
                    $loginMail['html'],
                    $loginMail['image'],
                );

                // Only the pseudonymized email_hmac in the log (ADR 0002) — plaintext email
                // and plaintext token must NEVER go into the log.
                $audit->log('magic_link.requested', ['email_hmac' => $emailHmac]);

                // Scheduled-deletion export reminder: piggy-back the reminder
                // mail onto this exact request —
                // the ONLY moment this codebase ever holds $email in plaintext
                // (ADR 0002, see AccountMemberRepository::ownedAccountsPendingReminder()
                // class doc for the full rationale). Deliberately a SEPARATE mail
                // from the login link above (a distinct subject a user can search
                // for), sent to every account this user owns with a still-pending,
                // not-yet-notified deletion schedule.
                foreach ($accountMemberRepo->ownedAccountsPendingReminder((int) $user['id']) as $pending) {
                    $deadline = new \DateTimeImmutable($pending['deletion_scheduled_at']);
                    $reminderMail = MailTemplate::render(
                        'Your account will be deleted soon',
                        [
                            'Hello,',
                            "deletion has been scheduled for your account. Your account and all "
                            . "associated boards, ideas and comments will be permanently deleted on "
                            . "{$deadline->format('Y-m-d')}.",
                            'Please back up your data before then (export under "Account"). '
                            . 'As long as the grace period is running, you can also cancel the '
                            . 'deletion there.',
                        ],
                        null,
                        null,
                        ['Please do not share it.'],
                    );
                    $mailToUse->send(
                        $email,
                        'Your Votepit account will be deleted soon',
                        $reminderMail['text'],
                        $reminderMail['html'],
                        $reminderMail['image'],
                    );
                    $accountRepoForPlan->markDeletionReminderSent($pending['account_id']);
                    $audit->log('account.deletion_reminder_sent', ['account_id' => $pending['account_id']]);
                }
            }

            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })
        ->setName(CoreRoute::LOGIN_REQUEST)
        ->add(AuthZMiddleware::anon($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'magiclink:email',
            $emailRateLimit['limit'],
            $emailRateLimit['window'],
            static function (ServerRequestInterface $r) use ($hasher): ?string {
                $parsed = $r->getParsedBody();
                $email  = is_array($parsed) ? trim((string) ($parsed['email'] ?? '')) : '';
                return $email !== '' ? $hasher->hash($email) : null;
            },
        ))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'magiclink:ip',
            $mlIpRateLimit['limit'],
            $mlIpRateLimit['window'],
            static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
        ));

        // POST /logout — bumps token_version (invalidates all sessions) + clears the cookie.
        // AuthZ: user (anon → 401); CSRF: mutating verb → globally enforced.
        $app->post('/logout', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($userRepo, $sessions, $audit): ResponseInterface {
            /** @var array<string, mixed>|null $user */
            $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            if (is_array($user)) {
                $userRepo->bumpTokenVersion((int) $user['id']);
                $audit->log('user.logout', ['uid' => (int) $user['id']]);
            }
            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $sessions->clear(
                $response->withStatus(200)->withHeader('Content-Type', 'application/json')
            );
        })->add(AuthZMiddleware::user($responseFactory));

        // GET /login/verify?token=<plaintext> — verifies the magic link and
        // issues a fresh session (AuthZ: anon, GET → CSRF-exempt:
        // the one-time token itself is the capability). On failure NO
        // side effect, uniform 4xx JSON error response.
        $app->get('/login/verify', new LoginVerifyAction($userRepo, $tokenRepo, $vault, $audit, $config, $loginSessionIssuer, $conn, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /login/password — email + password login (AuthZ: anon), additive
        // to the magic link (which stays always available). With active TOTP: no
        // session issued, instead a pending-2FA token (see LoginPasswordAction).
        $passwordRateLimit  = $config->rateLimit('login:password');
        $passwordRateLimiter = new RateLimiter($conn);
        $app->post('/login/password', new LoginPasswordAction($userRepo, $hasher, $tokenRepo, $vault, $loginSessionIssuer, $audit, $passwordRateLimiter, $config->trustCloudflareIp))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perIp(
                $passwordRateLimiter,
                $responseFactory,
                'login:password',
                $passwordRateLimit['limit'],
                $passwordRateLimit['window'],
                $config->trustCloudflareIp,
            ));

        // POST /login/2fa — second step after magic-link/password login with
        // active TOTP (AuthZ: anon — the pending-token capability IS the
        // authentication at this point, analogous to /login/verify).
        $twoFaRateLimit  = $config->rateLimit('login:2fa');
        $twoFaRateLimiter = new RateLimiter($conn);
        $app->post('/login/2fa', new Login2faAction($tokenRepo, $vault, $userRepo, $totpBackupCodeRepo, $totp, $totpEncryption, $loginSessionIssuer, $audit, $twoFaRateLimiter, $config->trustCloudflareIp))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perIp(
                $twoFaRateLimiter,
                $responseFactory,
                'login:2fa',
                $twoFaRateLimit['limit'],
                $twoFaRateLimit['window'],
                $config->trustCloudflareIp,
                static function (string $ip) use ($audit): void {
                    $audit->log('login_2fa.rate_limited', ['ip' => $ip]);
                },
            ));

        // POST /password/reset/request — "forgot password" step A (AuthZ: anon).
        // Response is ALWAYS identical (anti-enumeration, like POST /login) —
        // see PasswordResetRequestAction class doc. Same dual-bucket pattern
        // (email + IP) as magiclink:email/magiclink:ip.
        $resetEmailRateLimit = $config->rateLimit('password:reset:email');
        $resetIpRateLimit    = $config->rateLimit('password:reset:ip');
        $passwordResetMailer = new PasswordResetMailer($tokenRepo, $vault, $mailer, $smtpResolver, $config);
        $app->post(
            '/password/reset/request',
            new PasswordResetRequestAction($userRepo, $hasher, $vault, $passwordResetMailer, $audit),
        )
            ->setName(CoreRoute::PASSWORD_RESET_REQUEST)
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'password:reset:email',
                $resetEmailRateLimit['limit'],
                $resetEmailRateLimit['window'],
                static function (ServerRequestInterface $r) use ($hasher): ?string {
                    $parsed = $r->getParsedBody();
                    $email  = is_array($parsed) ? trim((string) ($parsed['email'] ?? '')) : '';
                    return $email !== '' ? $hasher->hash($email) : null;
                },
            ))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'password:reset:ip',
                $resetIpRateLimit['limit'],
                $resetIpRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

        // POST /password/reset/confirm — "forgot password" step B (AuthZ: anon —
        // the single-use reset token itself is the capability, analog /login/verify).
        $app->post('/password/reset/confirm', new PasswordResetConfirmAction($userRepo, $tokenRepo, $vault, $audit))
            ->add(AuthZMiddleware::anon($responseFactory));

        // Profile settings (AuthZ: user) — password + TOTP 2FA are self-
        // configurable by EVERY logged-in user, no special role is required
        // (CLAUDE.md scope note — primarily intended for administrative
        // accounts, but technically available to everyone).
        $app->post('/account/password', new SetPasswordAction($userRepo, $audit))
            ->add(AuthZMiddleware::user($responseFactory));

        // POST /account/password-reset — logged-in self-service "send me a
        // reset link" (AuthZ: user). See AccountPasswordResetAction class doc
        // for why the address must be re-typed (ADR 0002: no plaintext email
        // is ever stored, not even for the caller's own account).
        $app->post('/account/password-reset', new AccountPasswordResetAction($userRepo, $hasher, $passwordResetMailer, $audit))
            ->add(AuthZMiddleware::user($responseFactory));

        $totpAction = new TotpAction($userRepo, $totpBackupCodeRepo, $totp, $totpSetupToken, $totpEncryption, $audit);
        $app->post('/account/totp/setup', $totpAction->setup(...))
            ->add(AuthZMiddleware::user($responseFactory));
        $app->post('/account/totp/confirm', $totpAction->confirm(...))
            ->add(AuthZMiddleware::user($responseFactory));
        $app->post('/account/totp/disable', $totpAction->disable(...))
            ->add(AuthZMiddleware::user($responseFactory));
        $app->post('/account/totp/backup-codes/regenerate', $totpAction->regenerateBackupCodes(...))
            ->add(AuthZMiddleware::user($responseFactory));
    }

    /**
     * Invite acceptance + cloud onboarding signup (both global/unprefixed).
     *
     * @param App<null> $app
     */
    private static function registerInviteAndSignupRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        InviteRepository $inviteRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepoForPlan,
        TokenVault $vault,
        BoardRepository $boardRepo,
        PlanPolicy $planPolicy,
    ): void {

        // GET /invite/accept?token=<plaintext> — accept an invite.
        // AuthZ: user (anon → 401, the SPA then redirects to /login?r=…).
        // GET → CSRF-exempt (the one-time token is the capability, analogous to /login/verify).
        $app->get('/invite/accept', new InviteAcceptAction($inviteRepo, $accountMemberRepo, $accountRepoForPlan, $vault, $audit, $conn))
            ->add(AuthZMiddleware::user($responseFactory));

        // GET/POST /signup/account — Cloud onboarding step 2 (cloud signup
        // onboarding). Cloud-mode only: self-host already operates
        // exactly one, pre-seeded account and never needs a second-account-
        // creation flow (Config::routingMode lockstep invariant). Global/
        // identity-scoped, unprefixed — MUST likewise stand here before the first
        // $accountPrefix route (2-segment route, same FastRoute
        // collision risk as /invite/accept, see comment block above).
        if ($config->routingMode === 'cloud') {
            $signupAction = new SignupAccountAction(new AccountRepository($conn), $accountMemberRepo, $boardRepo, $conn, $audit, $planPolicy);

            $app->get('/signup/account', $signupAction->status(...))
                ->add(AuthZMiddleware::user($responseFactory));

            $app->post('/signup/account', $signupAction->create(...))
                ->add(AuthZMiddleware::user($responseFactory));
        }

    }

    /**
     * Bearer-token-authenticated REST API (/api/v1/*) + the MCP endpoint.
     *
     * @param App<null> $app
     */
    private static function registerApiTokenRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        IdeaRepository $ideaRepo,
        TitleNormalizer $normalizer,
        ContentModerationService $moderation,
        ModerationConfigRepository $modConfigRepo,
        BoardRepository $boardRepo,
        ApiTokenAuthenticator $apiTokenAuthenticator,
    ): void {
        // ---------------------------------------------------------------
        // Agent API / Votepit MCP: api_tokens admin
        // CRUD (session-authenticated) + Bearer-token-authenticated REST
        // endpoints under /api/v1 (a NEW trust boundary alongside the
        // session-cookie path — see ApiTokenAuthMiddleware class doc).
        // ---------------------------------------------------------------

        // Token-authenticated REST API (bearer auth instead of session cookie).
        // No board slug in the path — the board comes exclusively from the
        // token scope (ApiTokenAuthMiddleware). CSRF is moot for bearer requests
        // (CsrfMiddleware exception, see there) and BlockCheck/
        // AuthZMiddleware deliberately do NOT run here — ApiTokenAuthMiddleware
        // IS the complete AuthN+AuthZ gate for these routes.
        $apiIdeaAction  = new ApiIdeaAction($ideaRepo, $normalizer, $audit, $moderation, $modConfigRepo);
        $apiBoardAction = new ApiBoardAction($boardRepo);

        $apiReadRateLimit  = $config->rateLimit('apitoken:read');
        $apiWriteRateLimit = $config->rateLimit('apitoken:write');

        $apiTokenIdentity = static function (ServerRequestInterface $r): ?string {
            /** @var array{token_id?: int}|null $token */
            $token = $r->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);
            return is_array($token) && isset($token['token_id']) ? (string) $token['token_id'] : null;
        };

        $app->get('/api/v1/board', $apiBoardAction)
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'apitoken:read',
                $apiReadRateLimit['limit'],
                $apiReadRateLimit['window'],
                $apiTokenIdentity,
            ))
            ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $responseFactory));

        $app->get('/api/v1/ideas', $apiIdeaAction->list(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'apitoken:read',
                $apiReadRateLimit['limit'],
                $apiReadRateLimit['window'],
                $apiTokenIdentity,
            ))
            ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $responseFactory));

        $app->get('/api/v1/ideas/{id:[0-9]+}', $apiIdeaAction->detail(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'apitoken:read',
                $apiReadRateLimit['limit'],
                $apiReadRateLimit['window'],
                $apiTokenIdentity,
            ))
            ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $responseFactory));

        $app->post('/api/v1/ideas', $apiIdeaAction->create(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'apitoken:write',
                $apiWriteRateLimit['limit'],
                $apiWriteRateLimit['window'],
                $apiTokenIdentity,
            ))
            ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $responseFactory));

        // ---------------------------------------------------------------
        // Agent API / Votepit MCP: MCP (Model Context
        // Protocol) resource wrapper over the same capability set as
        // /api/v1/* above (see McpAction class doc). One JSON-RPC 2.0
        // endpoint, same Bearer-token trust boundary and apitoken:read
        // rate-limit bucket as every other /api/v1 route — the write
        // tool (create_idea) additionally spends from apitoken:write
        // inside McpAction itself (a single JSON-RPC endpoint can't be
        // split per-method at the route level).
        // ---------------------------------------------------------------
        $mcpAction = new McpAction(
            $apiBoardAction,
            $apiIdeaAction,
            new RateLimiter($conn),
            $apiWriteRateLimit['limit'],
            $apiWriteRateLimit['window'],
        );

        $app->post('/api/v1/mcp', $mcpAction)
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'apitoken:read',
                $apiReadRateLimit['limit'],
                $apiReadRateLimit['window'],
                $apiTokenIdentity,
            ))
            ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $responseFactory));

    }

    /**
     * Operator panel (platform super-admin): abuse reports, account/board/
     * user/usage management, notifications, announcements, support inbox,
     * FAQ and public board discovery. All global/unprefixed.
     *
     * @param  App<null> $app
     * @return SupportRequestAction the account-scoped support routes further
     *                              below reuse this same instance.
     */
    private static function registerOperatorRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AccountRepository $accountRepoForPlan,
        BoardRepository $boardRepo,
        IdeaRepository $ideaRepo,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        PasswordResetMailer $passwordResetMailer,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        NotificationRepository $notificationRepo,
    ): SupportRequestAction {
        // ---------------------------------------------------------------
        // Operator panel (platform super-admin): a NEW authz
        // tier STRICTLY ABOVE account-scoping (AuthZMiddleware::operator(),
        // users.is_operator — see that middleware's class doc). Every
        // route below is platform-wide, NOT account-prefixed — it MUST be
        // registered here, before the first $accountPrefix route, for the
        // same FastRoute-collision reason as /invite/accept
        // and /api/v1/* above (cloud-routing safety net, see comment
        // block further up). POST /reports is the one exception: anon
        // (public abuse-report intake, DSA Art. 16) — every other route
        // here requires AuthZMiddleware::operator().
        // ---------------------------------------------------------------
        $reportEncryption = new EncryptionService($config->appKey, 'abuse_report');
        $abuseReportRepo  = new AbuseReportRepository($conn);
        $operatorNotificationMailRateLimit = $config->rateLimit('notification-mail:global');
        $operatorNotificationMailer        = new CommentNotificationMailer(
            new RateLimiter($conn),
            $mailer,
            $smtpResolver,
            $config,
            $operatorNotificationMailRateLimit['limit'],
            $operatorNotificationMailRateLimit['window'],
        );
        $abuseReportAction = new AbuseReportAction(
            $abuseReportRepo,
            $accountRepoForPlan,
            $boardRepo,
            $ideaRepo,
            $reportEncryption,
            $audit,
            $notificationRepo,
            $userRepo,
            $operatorNotificationMailer,
        );

        $reportRateLimit = $config->rateLimit('report:submit');

        $app->post('/reports', $abuseReportAction->submit(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'report:submit',
                $reportRateLimit['limit'],
                $reportRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

        // SupportRequestRepository is built here already (rather than
        // down by the support routes below) purely so OperatorUsageAction
        // can fold its open-ticket count into the usage overview, the
        // same way it already does for $abuseReportRepo.
        $supportRequestRepo = new SupportRequestRepository($conn);

        $operatorAccountAction = new OperatorAccountAction($accountRepoForPlan, $audit);
        $operatorBoardAction   = new OperatorBoardAction($boardRepo, $audit);
        $operatorUserAction    = new OperatorUserAction($userRepo, $hasher, $passwordResetMailer, $audit);
        $operatorUsageAction   = new OperatorUsageAction($accountRepoForPlan, $boardRepo, $ideaRepo, $userRepo, $abuseReportRepo, $supportRequestRepo);

        $app->get('/operator/usage', $operatorUsageAction)
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->get('/operator/accounts', $operatorAccountAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/accounts/{id:[0-9]+}/lock', $operatorAccountAction->lock(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/accounts/{id:[0-9]+}/unlock', $operatorAccountAction->unlock(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/accounts/{id:[0-9]+}/delete', $operatorAccountAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        // POST /operator/users/password-reset — trigger a mail-based reset
        // link for ANY platform user, identified by re-typed email
        // (Punkt 5d). AuthZ: support() — is_support OR is_operator, both
        // tiers may use it (see OperatorUserAction class doc).
        $app->post('/operator/users/password-reset', $operatorUserAction->passwordReset(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->get('/operator/boards', $operatorBoardAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/boards/{id:[0-9]+}/lock', $operatorBoardAction->lock(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/boards/{id:[0-9]+}/unlock', $operatorBoardAction->unlock(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/boards/{id:[0-9]+}/delete', $operatorBoardAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->get('/operator/reports', $abuseReportAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/reports/{id:[0-9]+}/review', $abuseReportAction->review(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        // Support-request operator inbox (dashboard contact form —
        // SupportRequestAction class doc). Submission itself (POST
        // /{account}/support) is account-scoped and registered further
        // below, alongside the account's other AuthZ::accountAdmin()
        // routes — only the operator-facing half belongs in this
        // unprefixed block.
        // Notifications (migrations/0024_add_notifications_remove_support_email.sql):
        // the in-app inbox replacing the old support-request email
        // channel — support replies land here, plus operator-authored
        // broadcast announcements below. Built here already so
        // SupportRequestAction can create a "support_reply" notification
        // on operatorReply().
        $notificationAction = new NotificationAction($notificationRepo);

        $app->get('/notifications', $notificationAction->list(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->post('/notifications/{id:[0-9]+}/read', $notificationAction->markRead(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->delete('/notifications/{id:[0-9]+}', $notificationAction->dismiss(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $operatorAnnouncementAction = new OperatorAnnouncementAction($notificationRepo, $audit);

        $app->get('/operator/announcements', $operatorAnnouncementAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->post('/operator/announcements', $operatorAnnouncementAction->create(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $app->delete('/operator/announcements/{id:[0-9]+}', $operatorAnnouncementAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory));

        $supportRequestAction = new SupportRequestAction(
            $supportRequestRepo,
            $notificationRepo,
            $audit,
            $accountRepoForPlan,
            $userRepo,
            $operatorNotificationMailer,
        );

        // Customer-support routes: the trusted-helper tier (is_support)
        // is enough here — see AuthZMiddleware::support() class doc.
        // Account/board lock/unlock/delete, abuse reports and
        // announcements below stay operator()-only.
        $app->get('/operator/support', $supportRequestAction->operatorList(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->get('/operator/support/{id:[0-9]+}', $supportRequestAction->operatorGetThread(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->post('/operator/support/{id:[0-9]+}/reply', $supportRequestAction->operatorReply(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->post('/operator/support/{id:[0-9]+}/status', $supportRequestAction->operatorSetStatus(...))
            ->add(AuthZMiddleware::support($responseFactory));

        // FAQ (migrations/0023_add_support_and_faq.sql class doc):
        // GET /faq is anon and platform-wide, must be registered here
        // (before the first $accountPrefix route) for the same
        // FastRoute-collision reason as /reports//operator/* above.
        // Mutations need only the support tier (same reasoning as the
        // support-ticket routes above).
        $faqRepo   = new FaqRepository($conn);
        $faqAction = new FaqAction($faqRepo, $audit);

        $app->get('/faq', $faqAction->publicList(...))
            ->add(AuthZMiddleware::anon($responseFactory));

        $app->get('/operator/faq', $faqAction->operatorList(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->post('/operator/faq', $faqAction->create(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->put('/operator/faq/{id:[0-9]+}', $faqAction->update(...))
            ->add(AuthZMiddleware::support($responseFactory));

        $app->delete('/operator/faq/{id:[0-9]+}', $faqAction->delete(...))
            ->add(AuthZMiddleware::support($responseFactory));

        // GET /discover — public, cross-tenant board discovery listing
        // (BoardRepository::listPublicDiscovery(), only visibility='public'
        // boards of confirmed/unlocked/non-deletion-scheduled accounts).
        // Anon and platform-wide, must be registered here (before the
        // first $accountPrefix route) for the same FastRoute-collision
        // reason as /faq/reports//operator/* above.
        $app->get('/discover', (new BoardDiscoveryAction($boardRepo))->list(...))
            ->add(AuthZMiddleware::anon($responseFactory));


        return $supportRequestAction;
    }

    /**
     * profile-avatar-social: user-owned avatar, social links, privacy,
     * username, notification preferences, and the public profile view.
     *
     * @param App<null> $app
     */
    private static function registerProfileRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        string $root,
        ?string $avatarDirOverride,
        UserRepository $userRepo,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        string $accountPrefix,
        TokenVault $vault,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
    ): void {
        // ---------------------------------------------------------------
        // profile-avatar-social — user-owned avatar + social links.
        // User-scoped (NOT account-scoped, no $accountPrefix): a profile
        // is the same across every account the user belongs to (ADR
        // 0001 §2c, mirrors `users` itself being global) — MUST be
        // registered here, before the first $accountPrefix route, for
        // the same FastRoute-collision reason as every other
        // global/unprefixed route above (cloud-routing safety net).
        // AuthZ::user() only — no account-admin check applies, every
        // route acts exclusively on the caller's OWN row (id from the
        // session, never from client input). GET /avatar/{filename} is
        // the one AuthZ::anon() route — a profile picture is public the
        // same way a board's logo_url is.
        // ---------------------------------------------------------------
        $avatarDir = $avatarDirOverride ?? ($root . '/storage/avatars');
        $avatarProcessor = new AvatarProcessor();
        $userSocialLinkRepo = new UserSocialLinkRepository($conn);
        $accountProfileAction = new AccountProfileAction($userRepo, $userSocialLinkRepo, $avatarProcessor, $avatarDir, $audit);
        $avatarUploadRateLimit = $config->rateLimit('avatar:upload');
        $socialLinksRateLimit  = $config->rateLimit('sociallinks:update');
        $userIdentity = static function (ServerRequestInterface $r): ?string {
            $u = $r->getAttribute(AuthNMiddleware::ATTR_USER);
            return is_array($u) ? (string) ($u['id'] ?? '') : null;
        };

        $app->get('/account/profile', $accountProfileAction->getProfile(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->post('/account/avatar', $accountProfileAction->uploadAvatar(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'avatar:upload',
                $avatarUploadRateLimit['limit'],
                $avatarUploadRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->delete('/account/avatar', $accountProfileAction->deleteAvatar(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->put('/account/social-links', $accountProfileAction->putSocialLinks(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'sociallinks:update',
                $socialLinksRateLimit['limit'],
                $socialLinksRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->put('/account/privacy', $accountProfileAction->putPrivacy(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $usernameRateLimit = $config->rateLimit('username:update');
        $app->put('/account/username', $accountProfileAction->putUsername(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'username:update',
                $usernameRateLimit['limit'],
                $usernameRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory));

        // notification-preferences (.scratch/notification-preferences/PRD.md,
        // issue 02) — user-scoped, same convention as the profile routes
        // above (preferences are global per user, not per account/board).
        $notificationEmailVerificationRepo = new NotificationEmailVerificationRepository($conn);
        $notificationPreferencesAction     = new NotificationPreferencesAction(
            $userRepo,
            $notificationEmailVerificationRepo,
            $vault,
            $mailer,
            $smtpResolver,
            $config,
            $audit,
        );

        $app->get('/account/notification-preferences', $notificationPreferencesAction->getPreferences(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->put('/account/notification-preferences', $notificationPreferencesAction->putPreferences(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $notificationEmailRateLimit = $config->rateLimit('notification-email:request');
        $app->post('/account/notification-email', $notificationPreferencesAction->requestEmail(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'notification-email:request',
                $notificationEmailRateLimit['limit'],
                $notificationEmailRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory));

        // GET → CSRF-exempt (the one-time token itself is the capability,
        // analogous to /login/verify) — still requires an authenticated
        // session, since the confirm action additionally checks the
        // token's user_id against the caller (fail-secure, never confirm
        // into the wrong account).
        $app->get('/account/notification-email/confirm', $notificationPreferencesAction->confirmEmail(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->delete('/account/notification-email', $notificationPreferencesAction->deleteEmail(...))
            ->add(AuthZMiddleware::user($responseFactory));

        $app->get('/avatar/{filename}', new AvatarServeAction($avatarDir, $avatarProcessor))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET {account}/members/{userId}/profile — public profile view
        // (profile-visibility feature). AuthZ: anon, same trust level as
        // the ideas/comments it's attached to. Account-scoped only to
        // resolve the target's role badge within THIS account (see
        // PublicProfileAction doc) — MUST be registered here, before the
        // catch-all GET {accountPrefix}/{board} routes further below,
        // for the same FastRoute-collision reason as every other
        // account-scoped route registered ahead of the board catch-all.
        // VoteRepository is instantiated here (not reusing the later
        // $voteRepo, which is defined further below for VoteAction) —
        // it's a stateless wrapper over $conn, cheap to construct.
        $publicProfileAction = new PublicProfileAction($userRepo, $userSocialLinkRepo, $accountMemberRepo, $ideaRepo, new VoteRepository($conn));
        $app->get($accountPrefix . '/members/{userId:[0-9]+}/profile', $publicProfileAction)
            ->add(AuthZMiddleware::anon($responseFactory));
    }

    /**
     * Board admin overview/create/onboarding + the board home catch-all +
     * idea CRUD/vote/status/pin + comment create/moderate/edit + roadmap.
     *
     * @param App<null> $app
     */
    private static function registerBoardRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        CommentRepository $commentRepo,
        TimeTrapService $timeTrap,
        DuplicateDetectionService $duplicateDetection,
        TitleNormalizer $normalizer,
        ContentModerationService $moderation,
        BlockRepository $blockRepoForCheck,
        ModerationConfigRepository $modConfigRepo,
        NotificationRepository $notificationRepo,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
    ): void {

        // GET /admin/boards — account-scoped board overview.
        // AuthZ: accountAdmin. MUST be registered before the catch-all GET /{board},
        // otherwise "admin" would be incorrectly interpreted as a board slug.
        $app->get($accountPrefix . '/admin/boards', (new BoardListAction($boardRepo, $accountRepoForPlan, $planPolicy))->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/onboarding/complete — dismisses the first-run Setup
        // Wizard for the current account (finished or explicitly skipped;
        // see OnboardingCompleteAction). AuthZ: accountAdmin, same tier as
        // the boards list/create routes the wizard itself calls.
        $app->post($accountPrefix . '/admin/onboarding/complete', (new OnboardingCompleteAction($accountRepoForPlan))->complete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/telemetry-opt-in — Setup Wizard's product-improvement
        // telemetry consent decision (self-host only in practice; see
        // TelemetryOptInAction). AuthZ: accountAdmin, same tier as the
        // rest of the wizard.
        $app->post($accountPrefix . '/admin/telemetry-opt-in', (new TelemetryOptInAction($accountRepoForPlan))->optIn(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/boards — creates a new board in the current account.
        // AuthZ: accountAdmin; CSRF globally enforced.
        // MUST be registered before the catch-all GET /{board}, otherwise
        // "admin" would be incorrectly interpreted as a board slug.
        $app->post($accountPrefix . '/admin/boards', (new BoardCreateAction($boardRepo, $accountRepoForPlan, $planPolicy, $audit))->create(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/boards/active-set — owner re-choice of which
        // board(s) stay active after a downgrade froze boards over the new
        // plan's limit. AuthZ: accountOwner (same tier as invites).
        // MUST be registered before the catch-all GET /{board}.
        $app->post($accountPrefix . '/admin/boards/active-set', (new BoardActiveSetAction($boardRepo, $accountRepoForPlan, $planPolicy, $audit))->set(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // GET /api/board/default — default board slug for the SPA root route `/`
        // (BoardPage.tsx has no :boardSlug there yet). MUST be registered before
        // the catch-all GET /{board}, otherwise "api" would be incorrectly
        // interpreted as a board slug.
        $app->get($accountPrefix . '/api/board/default', new DefaultBoardAction($boardRepo, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board} — board home = idea list (newest, status filter, pagination).
        // AuthZ: anon (reading is public). Unknown slug → 404.
        $app->get($accountPrefix . '/{board}', new BoardHomeAction($boardRepo, $ideaRepo, $accountMemberRepo, $accountRepoForPlan, $planPolicy))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board}/ideas/{id} — idea detail view.
        // AuthZ: anon (reading is public). Unknown slug or idea → 404.
        // Cross-board leak prevented by board-scoped findInBoard().
        // viewTracker: cookie-free view-count dedup (migrations/
        // 0049_add_ideas_view_count.sql) — reuses identity_server_key
        // (ViewDedupHasher, domain-separated from IdentityHasher's email
        // hashing) and the existing rate_limits table (RateLimiter).
        $viewTracker = new IdeaViewTracker(
            new RateLimiter($conn),
            $ideaRepo,
            new ViewDedupHasher($config->identityServerKey),
            $config->trustCloudflareIp,
        );
        $app->get($accountPrefix . '/{board}/ideas/{id:[0-9]+}', new IdeaDetailAction($boardRepo, $ideaRepo, $commentRepo, $accountMemberRepo, $viewTracker))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board}/ideas/new — SPA route: returns board info + auth status + time-trap stamp
        // (AuthZ: anon). PRG redirect to login is dropped server-side; the SPA evaluates is_authenticated
        // instead. form_at must be echoed back by the SPA as the _form_at field in the POST.
        $app->get($accountPrefix . '/{board}/ideas/new', new IdeaNewAction($boardRepo, $timeTrap, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board}/ideas/search-duplicates?title=... — as-you-type duplicate
        // recall for the submit form. AuthZ: user (anon → 401, matches
        // the surrounding submit-flow trust level); per-action rate limit dupsearch:user.
        $dupSearchRateLimit = $config->rateLimit('dupsearch:user');

        $app->get($accountPrefix . '/{board}/ideas/search-duplicates', new IdeaSearchDuplicatesAction($boardRepo, $ideaRepo, $duplicateDetection))
        ->setName(CoreRoute::IDEA_SEARCH_DUPLICATES)
        ->add(AuthZMiddleware::user($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'dupsearch:user',
            $dupSearchRateLimit['limit'],
            $dupSearchRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas — create an idea.
        // AuthZ: user (anon → 401); CSRF globally enforced; per-action rate limit idea:submit.
        $submitRateLimit = $config->rateLimit('idea:submit');

        $app->post($accountPrefix . '/{board}/ideas', new IdeaCreateAction($boardRepo, $ideaRepo, $normalizer, $audit, $moderation, $timeTrap, $blockRepoForCheck, $modConfigRepo))
        ->setName(CoreRoute::IDEA_CREATE)
        ->add(AuthZMiddleware::user($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:submit',
            $submitRateLimit['limit'],
            $submitRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // GET /{board}/ideas/{id}/edit — edit form.
        // AuthZ: anon; row-level ownership check in the action; anon → 401 JSON.
        // The time-trap stamp is embedded as a hidden field.
        $editAction = new IdeaEditAction(
            $boardRepo,
            $ideaRepo,
            $normalizer,
            $audit,
            $moderation,
            $timeTrap,
            $blockRepoForCheck,
            $modConfigRepo,
        );

        // GET /edit: anon → 401 JSON (in-action).
        // Ownership check also in the action (404/403).
        $app->get($accountPrefix . '/{board}/ideas/{id:[0-9]+}/edit', $editAction->getEdit(...))
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /{board}/ideas/{id} — update an idea.
        // AuthZ: user; row-level ownership check in the action; CSRF globally enforced.
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}', $editAction->postEdit(...))
            ->add(AuthZMiddleware::user($responseFactory));

        // POST /{board}/ideas/{id}/withdraw — withdraw an idea / hard delete.
        // AuthZ: user; row-level ownership check in the action; CSRF globally enforced.
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/withdraw', new IdeaWithdrawAction($boardRepo, $ideaRepo, $audit, $blockRepoForCheck))
            ->add(AuthZMiddleware::user($responseFactory));

        // POST /{board}/ideas/{id}/vote — cast/change/retract a vote.
        // AuthZ: user (anon → 401); CSRF + BlockCheck global; per-action rate limit idea:vote.
        // Board-scoping in the action via findInBoard (foreign idea → 404, no row).
        $voteRepo      = new VoteRepository($conn);
        $voteRateLimit = $config->rateLimit('idea:vote');

        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/vote', new VoteAction($boardRepo, $ideaRepo, $voteRepo, $audit, $blockRepoForCheck))
        ->setName(CoreRoute::IDEA_VOTE)
        ->add(AuthZMiddleware::user($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:vote',
            $voteRateLimit['limit'],
            $voteRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas/{id}/status — set status.
        // AuthZ: accountModerate (owner|admin|moderator — one of
        // moderator's two allowed actions, anon → 401, missing account
        // role → 403); CSRF + BlockCheck global; per-action rate limit
        // idea:status. Board-scoping in the action via findInBoard (foreign idea → 404,
        // no mutation).
        $statusRateLimit = $config->rateLimit('idea:status');

        // idea-status-follow-notification fan-out (issue 05): shares the
        // SAME global notification-mail rate-limit bucket as the comment
        // fan-out below — a separate CommentNotificationMailer instance
        // is fine, since RateLimiter buckets are keyed by the bucket
        // STRING in the DB, not by instance (see RateLimiter::hit()).
        $statusNotificationMailRateLimit = $config->rateLimit('notification-mail:global');
        $statusNotificationMailer        = new CommentNotificationMailer(
            new RateLimiter($conn),
            $mailer,
            $smtpResolver,
            $config,
            $statusNotificationMailRateLimit['limit'],
            $statusNotificationMailRateLimit['window'],
        );

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/status',
            new IdeaStatusAction($conn, $boardRepo, $ideaRepo, new StatusService(), $audit, $notificationRepo, $userRepo, $voteRepo, $commentRepo, $statusNotificationMailer, $blockRepoForCheck),
        )
        ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:status',
            $statusRateLimit['limit'],
            $statusRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas/{id}/pin — pin/unpin.
        // AuthZ: accountModerate (owner|admin|moderator, anon → 401,
        // missing account role → 403); CSRF + BlockCheck global; per-action rate limit
        // idea:pin. Board-scoping in the action via findInBoard (foreign idea → 404,
        // no mutation).
        $pinRateLimit = $config->rateLimit('idea:pin');

        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/pin', new IdeaPinAction($boardRepo, $ideaRepo, $audit))
        ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:pin',
            $pinRateLimit['limit'],
            $pinRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas/{id}/comments — create a comment.
        // AuthZ: user (anon → 401); CSRF globally enforced; per-action rate limit
        // comment:user. Board-scoping in the action via findInBoard (foreign idea
        // → 404, no comment is created).
        $commentRateLimit = $config->rateLimit('comment:user');

        // notification-mail:global — a SINGLE global bucket (not per-
        // user/IP) shared across every comment-triggered notification
        // email, so a comment storm can't eat into the shared
        // account-wide send limit that time-critical magic-link mail
        // depends on.
        $notificationMailRateLimit = $config->rateLimit('notification-mail:global');
        $commentNotificationMailer = new CommentNotificationMailer(
            new RateLimiter($conn),
            $mailer,
            $smtpResolver,
            $config,
            $notificationMailRateLimit['limit'],
            $notificationMailRateLimit['window'],
        );

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments',
            new CommentCreateAction($conn, $boardRepo, $ideaRepo, $commentRepo, $audit, $moderation, $blockRepoForCheck, $notificationRepo, $userRepo, $commentNotificationMailer, $modConfigRepo),
        )
        ->setName(CoreRoute::COMMENT_CREATE)
        ->add(AuthZMiddleware::user($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'comment:user',
            $commentRateLimit['limit'],
            $commentRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas/{id}/comments/{commentId}/delete — remove a comment
        // (moderator's other allowed action). AuthZ: accountModerate; CSRF globally
        // enforced. Board-/idea-scoping in the action (foreign idea/comment → 404).
        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/delete',
            new CommentModerationAction($boardRepo, $ideaRepo, $commentRepo, $audit),
        )
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo));

        // POST /{board}/ideas/{id}/comments/{commentId}/edit — the author edits
        // their own comment within CommentUpdateAction::EDIT_WINDOW_SECONDS (60s)
        // of posting. AuthZ: user (anon → 401); CSRF globally enforced. Ownership
        // + the edit window are enforced inside the action (403 / 422).
        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/edit',
            new CommentUpdateAction($boardRepo, $ideaRepo, $commentRepo, $audit, $moderation, $modConfigRepo),
        )
            ->add(AuthZMiddleware::user($responseFactory));

        // GET /{board}/roadmap — board-scoped roadmap.
        // Trust level: anon (public roadmap, no login required).
        // Returns only aggregates (score, consensus, comment count) — no voter PII.
        // Ideas are returned grouped by status (planned / in_progress / done);
        // open and declined don't appear. Uses idx_ideas_board_status.
        $app->get($accountPrefix . '/{board}/roadmap', new BoardRoadmapAction($boardRepo, $ideaRepo, $accountMemberRepo))
            ->add(AuthZMiddleware::anon($responseFactory));
    }

    /**
     * Board admin settings: branding, rename, delete, moderation, blocking,
     * members/invites, account settings/rename/delete/export, account-scoped
     * support, per-board SMTP (self-host only) and API tokens.
     *
     * @param App<null> $app
     */
    private static function registerBoardAdminRoutes(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        InviteRepository $inviteRepo,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        PasswordResetMailer $passwordResetMailer,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        TokenVault $vault,
        IdeaRepository $ideaRepo,
        CommentRepository $commentRepo,
        ApiTokenRepository $apiTokenRepo,
        ApiTokenAuthenticator $apiTokenAuthenticator,
        BoardSmtpSettingsRepository $boardSmtpRepo,
        ModerationConfigRepository $modConfigRepo,
        EncryptionService $encryptionSvc,
        SmtpHostPolicy $smtpHostPolicy,
        AccountDeletionPrecondition $deletionPrecondition,
        SupportRequestAction $supportRequestAction,
    ): void {

        // GET/POST /admin/boards/{slug}/branding — read/save the branding settings page
        // (AuthZ: accountAdmin, board-scoped, CSRF globally enforced for POST).
        // Every value is strictly validated BEFORE saving; invalid → null → default theme
        // (no raw value ever ends up in the DB/CSS).
        $brandingAction = new BoardBrandingAction($boardRepo, $accountRepoForPlan, $planPolicy, $audit);

        $app->get($accountPrefix . '/admin/boards/{slug}/branding', $brandingAction->getBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/boards/{slug}/branding', $brandingAction->postBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // PUT /admin/boards/{slug} — renames a board's title and/or slug.
        // AuthZ: accountAdmin, board-scoped, CSRF globally enforced. See
        // BoardRenameAction's class doc for the independent title/slug
        // semantics and the frozen-board/tombstone/collision rules.
        $app->put($accountPrefix . '/admin/boards/{slug}', (new BoardRenameAction($boardRepo, $audit))->rename(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/boards/{slug}/delete — permanent board deletion.
        // AuthZ: accountOwner (stricter than the accountAdmin routes above —
        // see BoardDeleteAction's class doc for why deletion alone is
        // owner-only), CSRF globally enforced.
        $app->post($accountPrefix . '/admin/boards/{slug}/delete', (new BoardDeleteAction($boardRepo, $audit))->delete(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // GET/POST /admin/boards/{slug}/moderation — read/save the moderation settings page
        // (AuthZ: accountAdmin, board-scoped, CSRF globally enforced for POST).
        // GET shows a toggle (on/off) + the current board custom words as JSON. POST: three
        // sub-actions via the hidden field "action": toggle | add | remove. Invalid input
        // → 422 JSON without a 500 (no exception rethrow).
        $moderationAction = new BoardModerationAction($boardRepo, $modConfigRepo, $audit);

        $app->get($accountPrefix . '/admin/boards/{slug}/moderation', $moderationAction->getModeration(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/boards/{slug}/moderation', $moderationAction->postModeration(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/boards/{slug}/block — block/unblock a user account-wide.
        // AuthZ: accountAdmin; CSRF + BlockCheck global;
        // per-action rate limit user:block. `{slug}` serves only the account/
        // AuthZ resolution (findBySlugForAccount, foreign board → 404) — the
        // block itself is account-wide (board_id NULL), not board-scoped.
        $blockRepo      = new BlockRepository($conn);
        $blockRateLimit = $config->rateLimit('user:block');

        $app->post($accountPrefix . '/admin/boards/{slug}/block', new UserBlockAction($boardRepo, $blockRepo, $userRepo, $audit))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'user:block',
                $blockRateLimit['limit'],
                $blockRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        // GET /admin/members — list members + open invites.
        // AuthZ: accountAdmin (owner + admin may read; moderator cannot —
        // restricted to comment/idea moderation only, see accountModerate()).
        // POST /admin/members/{userId}/remove, /role — mutations are owner-only.
        // The account's owner never appears in the returned list (see
        // MemberAction::list()) — they can't be removed or re-roled via
        // this UI, so there is nothing to act on for their own row.
        // $inviteRepo was already constructed further up (cloud-routing
        // safety net, see there) — only referenced here.
        $memberAction = new MemberAction($accountMemberRepo, $inviteRepo, $userRepo, $hasher, $passwordResetMailer, $audit);

        $app->get($accountPrefix . '/admin/members', $memberAction->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/members/{userId:[0-9]+}/remove', $memberAction->remove(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/members/{userId:[0-9]+}/role', $memberAction->changeRole(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // POST /admin/members/password-reset — trigger a mail-based reset
        // link for a member, identified by re-typed email (AuthZ:
        // accountAdmin — owner + admin, see MemberAction class doc).
        $app->post($accountPrefix . '/admin/members/password-reset', $memberAction->passwordReset(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // POST /admin/invites — invite by email. AuthZ: accountOwner
        // (invite is one of the owner-only actions, see acceptance criteria);
        // CSRF globally enforced; per-action rate limit invite:send.
        // POST /admin/invites/{id}/revoke — revoke an open invite (AuthZ: accountOwner).
        $inviteAction   = new InviteAction(
            $userRepo,
            $accountMemberRepo,
            $inviteRepo,
            $accountRepoForPlan,
            $planPolicy,
            $hasher,
            $vault,
            $mailer,
            $smtpResolver,
            $config,
            $audit,
        );
        $inviteRateLimit = $config->rateLimit('invite:send');

        $app->post($accountPrefix . '/admin/invites', $inviteAction->send(...))
            ->setName(CoreRoute::INVITE_SEND)
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'invite:send',
                $inviteRateLimit['limit'],
                $inviteRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        $app->post($accountPrefix . '/admin/invites/{id:[0-9]+}/revoke', $inviteAction->revoke(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // GET /admin/account — owner-facing account summary behind the
        // SPA's account settings page (slug for the typed delete
        // confirmation, default-account flag, pending deletion deadline).
        // AuthZ: accountOwner. See AccountSettingsAction class doc.
        $accountSettingsAction = new AccountSettingsAction($accountRepoForPlan);
        $app->get($accountPrefix . '/admin/account', $accountSettingsAction->show(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // PUT  /admin/account                — rename the account's name/slug.
        // GET  /admin/account/slug-available  — live slug-availability check.
        // AuthZ: accountOwner. See AccountRenameAction class doc.
        $accountRenameAction = new AccountRenameAction($accountRepoForPlan, $audit);
        $app->put($accountPrefix . '/admin/account', $accountRenameAction->rename(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        $app->get($accountPrefix . '/admin/account/slug-available', $accountRenameAction->slugAvailable(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo));

        // POST /admin/account/delete        — owner self-service GDPR
        //                                      account deletion (48h grace
        //                                      period, typed-confirmation
        //                                      re-validated server-side).
        // POST /admin/account/delete/cancel — undo, while the grace
        //                                      period is still running.
        // AuthZ: accountOwner on both. See AccountDeleteAction class doc.
        $accountDeleteAction = new AccountDeleteAction($accountRepoForPlan, $deletionPrecondition, $audit);
        $accountDeleteRateLimit = $config->rateLimit('account:delete');
        $accountDeleteCancelRateLimit = $config->rateLimit('account:delete:cancel');
        $ownerIdentity = static function (ServerRequestInterface $r): ?string {
            $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
            return is_array($user) ? (string) ($user['id'] ?? '') : null;
        };

        $app->post($accountPrefix . '/admin/account/delete', $accountDeleteAction->requestDeletion(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'account:delete',
                $accountDeleteRateLimit['limit'],
                $accountDeleteRateLimit['window'],
                $ownerIdentity,
            ));

        $app->post($accountPrefix . '/admin/account/delete/cancel', $accountDeleteAction->cancelDeletion(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'account:delete:cancel',
                $accountDeleteCancelRateLimit['limit'],
                $accountDeleteCancelRateLimit['window'],
                $ownerIdentity,
            ));

        // GET /admin/export — account-scoped data export (customer
        // self-export, GDPR Art. 20). AuthZ: accountOwner (same
        // tier as invites — see AccountExportAction class doc). Reuses
        // repository instances already constructed above (ideaRepo,
        // commentRepo, voteRepo, inviteRepo, apiTokenRepo, boardSmtpRepo,
        // modConfigRepo, blockRepo) — no new query surface, only tightly-
        // scoped account-wide sibling methods on each of them.
        $voteRepo      = new VoteRepository($conn);
        $exportService = new AccountExportService(
            $accountRepoForPlan,
            $accountMemberRepo,
            $boardRepo,
            $ideaRepo,
            $voteRepo,
            $commentRepo,
            $inviteRepo,
            $apiTokenRepo,
            $boardSmtpRepo,
            $modConfigRepo,
            $blockRepo,
            $userRepo,
        );
        $exportAction = new AccountExportAction($exportService);
        $exportRateLimit = $config->rateLimit('account:export');

        $app->get($accountPrefix . '/admin/export', $exportAction->download(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'account:export',
                $exportRateLimit['limit'],
                $exportRateLimit['window'],
                $ownerIdentity,
            ));

        // POST/GET /admin/support — dashboard support-request contact
        // form (SupportRequestAction class doc). NOT a bare "/support"
        // (would collide with the board catch-all route "/{boardSlug}"
        // in self-host mode — FastRoute shadows a static route
        // registered after a same-shape variable route). AuthZ:
        // accountAdmin (owner|admin) — handling support requests is part
        // of the 'admin' role's scope; moderator is restricted to
        // comment/idea moderation only and does not pass this (see
        // accountModerate()).
        // $supportRequestAction was already constructed in the operator
        // block further up (its operator-facing routes live there);
        // this is only its account-scoped half.
        $app->post($accountPrefix . '/admin/support', $supportRequestAction->submit(...))
            ->setName(CoreRoute::SUPPORT_REQUEST_SUBMIT)
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->get($accountPrefix . '/admin/support', $supportRequestAction->listMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->get($accountPrefix . '/admin/support/{id:[0-9]+}', $supportRequestAction->getThreadMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/support/{id:[0-9]+}/reply', $supportRequestAction->replyMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        // Per-board SMTP settings are a self-host feature: an operator who
        // runs one installation for one organisation may want a board to
        // send through its own relay. On a hosted multi-tenant install
        // (routing_mode: cloud) the operator's central mailer is the only
        // sender — tenant-chosen relays are not offered, so the routes are
        // not registered at all (the SPA hides the section via the
        // `board_smtp` bootstrap feature). SmtpConfigResolver still honours
        // any board_smtp_settings row that may exist.
        if ($config->routingMode !== 'cloud') {
            $smtpTestLimit = $config->rateLimit('smtp:test');

            // GET /admin/boards/{slug}/smtp — read board SMTP (AuthZ: accountAdmin,
            // board-scoped). Never returns the password.
            // PUT /admin/boards/{slug}/smtp — save board SMTP or reset to default.
            $boardSmtpAction = new BoardSmtpAction($boardRepo, $boardSmtpRepo, $encryptionSvc, $audit, $smtpHostPolicy);

            $app->get($accountPrefix . '/admin/boards/{slug}/smtp', $boardSmtpAction->getSmtp(...))
                ->setName(CoreRoute::BOARD_SMTP_GET)
                ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

            $app->put($accountPrefix . '/admin/boards/{slug}/smtp', $boardSmtpAction->putSmtp(...))
                ->setName(CoreRoute::BOARD_SMTP_PUT)
                ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

            // POST /admin/boards/{slug}/smtp/test — send a test mail via the resolved board settings
            // (AuthZ: accountAdmin, board-scoped).
            $app->post($accountPrefix . '/admin/boards/{slug}/smtp/test', new BoardSmtpTestAction($boardRepo, $smtpResolver, $boardSmtpRepo, $encryptionSvc, $audit, $smtpHostPolicy))
            ->setName(CoreRoute::BOARD_SMTP_TEST)
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'smtp:test',
                $smtpTestLimit['limit'],
                $smtpTestLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $u = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($u) ? (string) ($u['id'] ?? '') : null;
                },
            ))->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));
        }

        // GET  /admin/tokens             — list the account's tokens (all boards).
        // POST /admin/tokens             — create a token (plaintext only in this response).
        // POST /admin/tokens/{id}/revoke — revoke a token.
        // AuthZ: accountAdmin (owner + admin — mirrors branding/moderation/board-SMTP, see ApiTokenAction doc).
        // Account-scoped since migration 0044 — no board slug in the path,
        // a token's board grants are chosen in the request body (create()).
        $apiTokenAction = new ApiTokenAction($boardRepo, $apiTokenRepo, $apiTokenAuthenticator, $accountRepoForPlan, $planPolicy, $audit);

        $app->get($accountPrefix . '/admin/tokens', $apiTokenAction->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/tokens', $apiTokenAction->create(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

        $app->post($accountPrefix . '/admin/tokens/{id:[0-9]+}/revoke', $apiTokenAction->revoke(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo));

    }
}
