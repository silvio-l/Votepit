<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\DuplicateCandidateFinder;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\IdeaStatusChanger;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Action\ApiBoardAction;
use Votepit\Http\Action\ApiBoardBrandingAction;
use Votepit\Http\Action\ApiIdeaAction;
use Votepit\Http\Action\ApiIdeaWriteAction;
use Votepit\Http\Action\CommentCreateAction;
use Votepit\Http\Action\IdeaMergeAction;
use Votepit\Http\Action\IdeaStatusAction;
use Votepit\Http\Action\IdeaTagAssignAction;
use Votepit\Http\Action\IdeaTagRemoveAction;
use Votepit\Http\Action\McpAction;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Participant\ParticipantMailRouter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\ApiTokenAuthenticator;
use Votepit\Security\EncryptionService;
use Votepit\Security\OAuthServer\OAuthServerMetadata;
use Votepit\Security\RateLimiter;
use Votepit\Security\Turnstile\CurlTurnstileHttpClient;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Security\Turnstile\TurnstileHttpClient;
use Votepit\Support\Clock;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Agent API: bearer-token-authenticated REST endpoints under /api/v1 and
 * the MCP endpoint (ApiTokenAuthMiddleware instead of session AuthZ).
 * The session-authenticated token administration lives in
 * ApiTokenAdminRoutes. The public contract is documented in
 * documentation/openapi.yaml (OpenApiSpecTest keeps it in sync with the
 * routes registered here).
 *
 * Registered before the first {account}-prefixed route (cloud-routing
 * safety net, see AppFactory::create()) — which is why the idea write
 * endpoints build their own instances of the session-UI actions they
 * delegate to (same classes, same collaborators as IdeaRoutes/TagRoutes/
 * CommentRoutes) instead of receiving the ones constructed there.
 */
final class ApiV1Routes
{
    /**
     * Bearer-token-authenticated REST API (/api/v1/*) + the MCP endpoint.
     *
     * @param App<null> $app
     */
    public static function register(
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
        Clock $clock,
        IdeaStatusChanger $ideaStatusChanger,
        CommentRepository $commentRepo,
        TagRepository $tagRepo,
        BoardStatusRepository $boardStatusRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountMemberStandingRepository $standingRepo,
        BlockRepository $blockRepo,
        NotificationRepository $notificationRepo,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        WebhookDispatcher $webhookDispatcher,
        DuplicateDetectionService $duplicateDetection,
        ?TurnstileHttpClient $turnstileHttpClient,
        ApiBoardBrandingAction $brandingAction,
    ): void {
        // ---------------------------------------------------------------
        // Agent API / Votepit MCP: Bearer-token-authenticated REST
        // endpoints under /api/v1 (a NEW trust boundary alongside the
        // session-cookie path — see ApiTokenAuthMiddleware class doc).
        // ---------------------------------------------------------------

        // No board slug in the path — the board comes exclusively from the
        // token scope (ApiTokenAuthMiddleware). CSRF is moot for bearer
        // requests (CsrfMiddleware exception, see there) and BlockCheck/
        // AuthZMiddleware deliberately do NOT run here — ApiTokenAuthMiddleware
        // IS the AuthN+board gate for these routes; the per-endpoint grant
        // level (read/write/moderate) is checked in the actions.
        $premoderationNotifier = new PremoderationNotifier($accountMemberRepo, $notificationRepo, $userRepo);
        $apiIdeaAction  = new ApiIdeaAction($ideaRepo, $boardRepo, $normalizer, $audit, $moderation, $clock, $modConfigRepo, $webhookDispatcher, $premoderationNotifier, $userRepo, $standingRepo);
        $apiBoardAction = new ApiBoardAction($boardRepo, $boardStatusRepo, $tagRepo);
        $apiWriteAction = self::writeAction(
            $config,
            $conn,
            $audit,
            $ideaRepo,
            $moderation,
            $modConfigRepo,
            $boardRepo,
            $commentRepo,
            $tagRepo,
            $boardStatusRepo,
            $accountMemberRepo,
            $standingRepo,
            $blockRepo,
            $notificationRepo,
            $userRepo,
            $mailer,
            $smtpResolver,
            $webhookDispatcher,
            $duplicateDetection,
            $turnstileHttpClient,
            $clock,
            $ideaStatusChanger,
        );

        $apiWriteRateLimit = $config->rateLimit('apitoken:write');

        $apiTokenIdentity = static function (ServerRequestInterface $r): ?string {
            /** @var array{token_id?: int}|null $token */
            $token = $r->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);
            return is_array($token) && isset($token['token_id']) ? (string) $token['token_id'] : null;
        };

        // Rate-limit bucket (per token) + bearer gate. Slim runs the LAST
        // added middleware first: token auth → rate limit → action.
        $guard = static function (RouteInterface $route, string $bucket, ?string $resourceMetadataUrl = null) use ($conn, $responseFactory, $config, $apiTokenIdentity, $apiTokenAuthenticator, $boardRepo, $accountMemberRepo): void {
            $limit = $config->rateLimit($bucket);
            $route
                ->add(RateLimitMiddleware::perAction(
                    new RateLimiter($conn),
                    $responseFactory,
                    $bucket,
                    $limit['limit'],
                    $limit['window'],
                    $apiTokenIdentity,
                ))
                ->add(new ApiTokenAuthMiddleware($apiTokenAuthenticator, $boardRepo, $accountMemberRepo, $responseFactory, $resourceMetadataUrl));
        };

        $guard($app->get('/api/v1/board', $apiBoardAction), 'apitoken:read');
        $guard($app->get('/api/v1/ideas', $apiIdeaAction->list(...)), 'apitoken:read');
        $guard($app->get('/api/v1/ideas/{id:[0-9]+}', $apiIdeaAction->detail(...)), 'apitoken:read');
        $guard($app->post('/api/v1/ideas', $apiIdeaAction->create(...)), 'apitoken:write');

        // Idea write endpoints (competitor-parity issue 15): vote/comment
        // need the write grant, the moderator actions the explicit moderate
        // grant (ApiIdeaWriteAction). All share the per-token write budget.
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/vote', $apiWriteAction->vote(...)), 'apitoken:write');
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/comments', $apiWriteAction->comment(...)), 'apitoken:write');
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/status', $apiWriteAction->status(...)), 'apitoken:write');
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/tags', $apiWriteAction->assignTag(...)), 'apitoken:write');
        $guard($app->delete('/api/v1/ideas/{id:[0-9]+}/tags/{tagId:[0-9]+}', $apiWriteAction->removeTag(...)), 'apitoken:write');
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/merge', $apiWriteAction->mergeInto(...)), 'apitoken:write');
        $guard($app->post('/api/v1/ideas/{id:[0-9]+}/unmerge', $apiWriteAction->unmerge(...)), 'apitoken:write');

        // Board branding (migration 0117): reading needs any grant, every write
        // the manage grant (ApiBoardBrandingAction). Same write budget as above.
        $guard($app->get('/api/v1/board/branding', $brandingAction->getBranding(...)), 'apitoken:read');
        $guard($app->patch('/api/v1/board/branding', $brandingAction->patchBranding(...)), 'apitoken:write');
        $guard($app->post('/api/v1/board/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}', $brandingAction->uploadAsset(...)), 'apitoken:write');
        $guard($app->delete('/api/v1/board/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}', $brandingAction->deleteAsset(...)), 'apitoken:write');

        // ---------------------------------------------------------------
        // Agent API / Votepit MCP: MCP (Model Context
        // Protocol) resource wrapper over the same capability set as
        // /api/v1/* above (see McpAction class doc). One JSON-RPC 2.0
        // endpoint, same Bearer-token trust boundary and apitoken:read
        // rate-limit bucket as every other /api/v1 read route — the write
        // tools additionally spend from apitoken:write inside McpAction
        // itself (a single JSON-RPC endpoint can't be split per-method at
        // the route level).
        // ---------------------------------------------------------------
        $mcpAction = new McpAction(
            $apiBoardAction,
            $apiIdeaAction,
            $apiWriteAction,
            $brandingAction,
            $responseFactory,
            new RateLimiter($conn),
            $apiWriteRateLimit['limit'],
            $apiWriteRateLimit['window'],
        );

        // MCP OAuth (issue 21): a 401 here points the client at the
        // protected resource metadata (RFC 9728) — OAuthServerRoutes serves
        // it, and the access tokens it leads to resolve through the same
        // ApiTokenAuthMiddleware as a static token.
        // Named so CsrfMiddleware::SESSIONLESS_MACHINE_ROUTES lets an
        // unauthenticated call through to that 401 instead of a CSRF 403.
        $guard($app->post('/api/v1/mcp', $mcpAction)->setName('core.api_v1.mcp'), 'apitoken:read', (new OAuthServerMetadata($config->appUrl))->resourceMetadataUrl());
    }

    /**
     * The idea write endpoints' action with its own instances of the
     * session-UI actions it delegates to — wired exactly like IdeaRoutes/
     * TagRoutes/CommentRoutes wire them (same notification-mail bucket,
     * same anonymous-vote e-mail encryption context, same webhook
     * dispatcher incl. the chat fan-out). Status changes run through the
     * shared IdeaStatusChanger, the same instance the moderator UI uses.
     */
    private static function writeAction(
        Config $config,
        Connection $conn,
        AuditLogger $audit,
        IdeaRepository $ideaRepo,
        ContentModerationService $moderation,
        ModerationConfigRepository $modConfigRepo,
        BoardRepository $boardRepo,
        CommentRepository $commentRepo,
        TagRepository $tagRepo,
        BoardStatusRepository $boardStatusRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountMemberStandingRepository $standingRepo,
        BlockRepository $blockRepo,
        NotificationRepository $notificationRepo,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        WebhookDispatcher $webhookDispatcher,
        DuplicateDetectionService $duplicateDetection,
        ?TurnstileHttpClient $turnstileHttpClient,
        Clock $clock,
        IdeaStatusChanger $ideaStatusChanger,
    ): ApiIdeaWriteAction {
        $voteRepo         = new VoteRepository($conn);
        $anonEmailEnc     = new EncryptionService($config->appKey, 'anon_vote_email');
        $mailLimit        = $config->rateLimit('notification-mail:global');
        $notificationMail = new CommentNotificationMailer(new RateLimiter($conn), $mailer, $smtpResolver, $config, $mailLimit['limit'], $mailLimit['window']);
        $turnstileGuard   = new TurnstileGuard($config, new RateLimiter($conn), $turnstileHttpClient ?? new CurlTurnstileHttpClient());

        return new ApiIdeaWriteAction(
            $boardRepo,
            $ideaRepo,
            $voteRepo,
            $boardStatusRepo,
            $accountMemberRepo,
            new AnonymousVoterHasher($config->identityServerKey),
            $audit,
            $clock,
            new CommentCreateAction($conn, $boardRepo, $ideaRepo, $commentRepo, $audit, $moderation, $blockRepo, $notificationRepo, $userRepo, $notificationMail, $turnstileGuard, $boardStatusRepo, ParticipantMailRouter::forConfig($conn, $config), new AccountMailLinks(new AccountRepository($conn), $config->routingMode), $modConfigRepo, $standingRepo),
            new IdeaStatusAction($boardRepo, $ideaRepo, $boardStatusRepo, $ideaStatusChanger),
            new IdeaTagAssignAction($boardRepo, $ideaRepo, $tagRepo, $audit),
            new IdeaTagRemoveAction($boardRepo, $ideaRepo, $tagRepo, $audit),
            new IdeaMergeAction(
                $conn,
                $boardRepo,
                $ideaRepo,
                $voteRepo,
                $commentRepo,
                new DuplicateCandidateFinder($ideaRepo, $duplicateDetection),
                $audit,
                $notificationRepo,
                $userRepo,
                $notificationMail,
                $anonEmailEnc,
                new AccountMailLinks(new AccountRepository($conn), $config->routingMode),
                $blockRepo,
                $webhookDispatcher,
            ),
        );
    }
}
