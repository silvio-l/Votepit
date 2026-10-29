<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Action\IdeaIssueLinkAction;
use Votepit\Http\Action\IssueTrackerConnectionAction;
use Votepit\Http\Action\IssueTrackerWebhookAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\IssueTrackerWebhookMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\IssueTracker\IssueStatusSync;
use Votepit\IssueTracker\IssueTrackerApi;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaIssueLinkRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IssueTrackerConnectionRepository;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;

/**
 * Linear/GitHub issue-tracker links (issue 19): the account connection,
 * "create issue from idea" and the inbound status-sync webhooks.
 *
 * Two registration points because of the FastRoute ordering rule (see
 * AppFactory class doc): the webhooks are global/unprefixed (the provider
 * knows nothing about the {account} segment — the random webhook id
 * identifies the connection) and go into group 1; the admin routes are
 * {account}-prefixed and go after BoardIntegrationRoutes.
 */
final class IssueTrackerRoutes
{
    /** Route-name prefix; CsrfMiddleware::SIGNED_WEBHOOK_ROUTES lists the full names. */
    public const WEBHOOK_ROUTE_NAME_PREFIX = 'core.issue_tracker_webhook.';

    /**
     * POST /api/issue-tracker-webhooks/{linear|github}/{hook} — anonymous by
     * necessity (server-to-server); authenticated by the provider's HMAC
     * signature in IssueTrackerWebhookMiddleware. Per-IP rate limit runs
     * FIRST (added last), before the connection lookup.
     *
     * @param App<null> $app
     */
    public static function registerWebhooks(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        PlanPolicy $planPolicy,
        AccountPlanResolver $accountPlans,
        IssueTrackerConnectionRepository $connections,
        EncryptionService $webhookSecretEnc,
        IssueStatusSync $sync,
    ): void {
        $limit = $config->rateLimit('issue-tracker:webhook');

        foreach (IssueTrackerProvider::cases() as $provider) {
            $app->post(
                '/api/issue-tracker-webhooks/' . $provider->value . '/{hook:[0-9a-f]{32}}',
                new IssueTrackerWebhookAction($provider, $sync, $accountPlans, $planPolicy, $audit),
            )
                ->setName(self::WEBHOOK_ROUTE_NAME_PREFIX . $provider->value)
                ->add(new IssueTrackerWebhookMiddleware($provider, $connections, $webhookSecretEnc, $responseFactory))
                ->add(RateLimitMiddleware::perAction(
                    new RateLimiter($conn),
                    $responseFactory,
                    'issue-tracker:webhook',
                    $limit['limit'],
                    $limit['window'],
                    static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
                ));
        }
    }

    /**
     * Account connection (accountAdmin) + per-idea issue links
     * (accountModerate). Both plan-gated inside the actions.
     *
     * @param App<null> $app
     */
    public static function registerAdmin(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepo,
        BoardRepository $boardRepo,
        IdeaRepository $ideaRepo,
        PlanPolicy $planPolicy,
        IssueTrackerConnectionRepository $connections,
        IdeaIssueLinkRepository $links,
        IssueTrackerApi $api,
        EncryptionService $tokenEnc,
        EncryptionService $webhookSecretEnc,
    ): void {
        $perUser = static function (ServerRequestInterface $r): ?string {
            $u = $r->getAttribute(AuthNMiddleware::ATTR_USER);
            return is_array($u) ? (string) ($u['id'] ?? '') : null;
        };
        $connectLimit = $config->rateLimit('issue-tracker:connect');
        $createLimit  = $config->rateLimit('issue-tracker:create');

        $accountPlans = new AccountPlanResolver($accountRepo, $accountMemberRepo, $planPolicy);
        $connectionAction = new IssueTrackerConnectionAction($config->appUrl, $accountPlans, $planPolicy, $connections, $api, $tokenEnc, $webhookSecretEnc, $audit);

        $app->get($accountPrefix . '/admin/issue-trackers', $connectionAction->get(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->put($accountPrefix . '/admin/issue-trackers/{provider}', $connectionAction->put(...))
            ->add(RateLimitMiddleware::perAction(new RateLimiter($conn), $responseFactory, 'issue-tracker:connect', $connectLimit['limit'], $connectLimit['window'], $perUser))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->delete($accountPrefix . '/admin/issue-trackers/{provider}', $connectionAction->delete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $linkAction = new IdeaIssueLinkAction(
            $config->appUrl,
            $config->routingMode === 'cloud',
            $boardRepo,
            $ideaRepo,
            $accountRepo,
            $accountPlans,
            $planPolicy,
            $connections,
            $links,
            $api,
            $tokenEnc,
            $audit,
        );

        $app->get($accountPrefix . '/admin/boards/{slug}/ideas/{id:[0-9]+}/issue-links', $linkAction->get(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/ideas/{id:[0-9]+}/issue-links/{provider}', $linkAction->post(...))
            ->add(RateLimitMiddleware::perAction(new RateLimiter($conn), $responseFactory, 'issue-tracker:create', $createLimit['limit'], $createLimit['window'], $perUser))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->delete($accountPrefix . '/admin/boards/{slug}/ideas/{id:[0-9]+}/issue-links/{provider}', $linkAction->delete(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
