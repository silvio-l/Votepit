<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Action\FaqAction;
use Votepit\Http\Action\OperatorAnnouncementAction;
use Votepit\Http\Action\SupportRequestAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\FaqRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;

/**
 * Operator-to-customer communication (unprefixed): operator broadcast
 * announcements, the operator-facing support-request inbox and the FAQ
 * (public GET /faq + support-tier mutations).
 *
 * Returns the SupportRequestAction so AccountAdminRoutes can register its
 * account-scoped half ({account}/admin/support). Registered before the
 * first {account}-prefixed route (cloud-routing safety net, see
 * AppFactory::create()).
 */
final class SupportRoutes
{
    /**
     * Announcements + operator support inbox + FAQ.
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
        AccountRepository $accountRepoForPlan,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        NotificationRepository $notificationRepo,
        TranslationRepository $translationRepo,
        TranslationProvider $translationProvider,
    ): SupportRequestAction {
        // Support-request operator inbox (dashboard contact form —
        // SupportRequestAction class doc). Submission itself (POST
        // {account}/admin/support) is account-scoped and registered by
        // AccountAdminRoutes, alongside the account's other
        // AuthZ::accountAdmin() routes — only the operator-facing half
        // belongs in this unprefixed block. Stateless repository/mailer,
        // built per route module (OperatorRoutes builds its own for the
        // usage overview and abuse-report notifications); the mailer
        // shares the global notification-mail:global bucket.
        $supportRequestRepo = new SupportRequestRepository($conn);
        $operatorNotificationMailRateLimit = $config->rateLimit('notification-mail:global');
        $operatorNotificationMailer        = new CommentNotificationMailer(
            new RateLimiter($conn),
            $mailer,
            $smtpResolver,
            $config,
            $operatorNotificationMailRateLimit['limit'],
            $operatorNotificationMailRateLimit['window'],
        );
        $rateLimiter = new RateLimiter($conn);
        $providerName = $config->translationProvider() !== '' ? $config->translationProvider() : 'google';
        $supportMessageTranslateRateLimit = $config->rateLimit('translation:support_message');

        $operatorAnnouncementAction = new OperatorAnnouncementAction($notificationRepo, $audit);

        $app->get('/operator/announcements', $operatorAnnouncementAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/announcements', $operatorAnnouncementAction->create(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->delete('/operator/announcements/{id:[0-9]+}', $operatorAnnouncementAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $supportRequestAction = new SupportRequestAction(
            $supportRequestRepo,
            $notificationRepo,
            $audit,
            $accountRepoForPlan,
            $userRepo,
            $operatorNotificationMailer,
            $translationRepo,
            $translationProvider,
            $rateLimiter,
            $supportMessageTranslateRateLimit['limit'],
            $supportMessageTranslateRateLimit['window'],
            $config->translationConfigured(),
            $providerName,
        );

        // Customer-support routes: the trusted-helper tier (is_support)
        // is enough here — see AuthZMiddleware::support() class doc.
        // Account/board lock/unlock/delete, abuse reports (OperatorRoutes)
        // and the announcements above stay operator()-only.
        $app->get('/operator/support', $supportRequestAction->operatorList(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->get('/operator/support/{id:[0-9]+}', $supportRequestAction->operatorGetThread(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->post('/operator/support/{id:[0-9]+}/reply', $supportRequestAction->operatorReply(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->post('/operator/support/{id:[0-9]+}/status', $supportRequestAction->operatorSetStatus(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->post('/operator/support/{id:[0-9]+}/translate', $supportRequestAction->operatorTranslate(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

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
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->post('/operator/faq', $faqAction->create(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->put('/operator/faq/{id:[0-9]+}', $faqAction->update(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->delete('/operator/faq/{id:[0-9]+}', $faqAction->delete(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        return $supportRequestAction;
    }
}
