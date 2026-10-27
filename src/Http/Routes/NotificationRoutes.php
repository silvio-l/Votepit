<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Action\NotificationAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;

/**
 * In-app notification inbox (unprefixed, AuthZ: user): list, mark read,
 * dismiss, translate.
 *
 * Registered before the first {account}-prefixed route (cloud-routing
 * safety net, see AppFactory::create()).
 */
final class NotificationRoutes
{
    /**
     * Notification inbox routes under /notifications.
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuthzDenialLogger $authzDenialLogger,
        NotificationRepository $notificationRepo,
        TranslationRepository $translationRepo,
        TranslationProvider $translationProvider,
    ): void {
        // Notifications (migrations/0024_add_notifications_remove_support_email.sql):
        // the in-app inbox replacing the old support-request email
        // channel — support replies land here (SupportRequestAction
        // creates a "support_reply" notification on operatorReply(), see
        // SupportRoutes), plus operator-authored broadcast announcements.
        $rateLimiter = new RateLimiter($conn);
        $providerName = $config->translationProvider() !== '' ? $config->translationProvider() : 'google';
        $notificationTranslateRateLimit = $config->rateLimit('translation:notification');

        $notificationAction = new NotificationAction(
            $notificationRepo,
            $translationRepo,
            $translationProvider,
            $rateLimiter,
            $notificationTranslateRateLimit['limit'],
            $notificationTranslateRateLimit['window'],
            $config->translationConfigured(),
            $providerName,
        );

        $app->get('/notifications', $notificationAction->list(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->post('/notifications/{id:[0-9]+}/read', $notificationAction->markRead(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->delete('/notifications/{id:[0-9]+}', $notificationAction->dismiss(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->post('/notifications/translate', $notificationAction->translate(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
    }
}
