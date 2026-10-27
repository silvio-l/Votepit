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
use Votepit\Http\Action\BoardChatNotificationAction;
use Votepit\Http\Action\BoardSmtpAction;
use Votepit\Http\Action\BoardSmtpTestAction;
use Votepit\Http\Action\BoardWebhookAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\BoardWebhookRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\SmtpHostPolicy;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * Per-board outbound integrations: board SMTP relay (self-host only, not
 * registered in routing_mode 'cloud'), the board webhook and Slack/Discord
 * chat notifications.
 */
final class BoardIntegrationRoutes
{
    /**
     * Board SMTP (self-host only) + board webhook settings.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountMemberRepository $accountMemberRepo,
        SmtpConfigResolver $smtpResolver,
        BoardSmtpSettingsRepository $boardSmtpRepo,
        EncryptionService $encryptionSvc,
        SmtpHostPolicy $smtpHostPolicy,
        BoardWebhookRepository $webhookRepo,
        WebhookUrlGuard $webhookUrlGuard,
        EncryptionService $webhookSecretEnc,
        AccountRepository $accountRepo,
        PlanPolicy $planPolicy,
        BoardChatIntegrationRepository $chatRepo,
        EncryptionService $chatUrlEnc,
    ): void {
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
                ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

            $app->put($accountPrefix . '/admin/boards/{slug}/smtp', $boardSmtpAction->putSmtp(...))
                ->setName(CoreRoute::BOARD_SMTP_PUT)
                ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

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
            ))->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));
        }

        // board-webhooks: available in both self-host and cloud mode (unlike
        // board SMTP above) — every board owner/admin may configure an
        // outbound webhook regardless of routing mode.
        $boardWebhookAction = new BoardWebhookAction($boardRepo, $webhookRepo, $webhookUrlGuard, $webhookSecretEnc, $audit);

        $app->get($accountPrefix . '/admin/boards/{slug}/webhook', $boardWebhookAction->get(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->put($accountPrefix . '/admin/boards/{slug}/webhook', $boardWebhookAction->put(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->patch($accountPrefix . '/admin/boards/{slug}/webhook', $boardWebhookAction->patch(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->delete($accountPrefix . '/admin/boards/{slug}/webhook', $boardWebhookAction->delete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // Slack/Discord chat notifications (issue 18): both routing modes;
        // plan-gated inside the action (PlanPolicy::chatNotificationsAllowed).
        $accountPlans = new AccountPlanResolver($accountRepo, $accountMemberRepo, $planPolicy);
        $chatAction = new BoardChatNotificationAction($boardRepo, $accountPlans, $planPolicy, $chatRepo, $chatUrlEnc, $audit);

        $app->get($accountPrefix . '/admin/boards/{slug}/chat-notifications', $chatAction->get(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->put($accountPrefix . '/admin/boards/{slug}/chat-notifications/{provider}', $chatAction->put(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->delete($accountPrefix . '/admin/boards/{slug}/chat-notifications/{provider}', $chatAction->delete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
