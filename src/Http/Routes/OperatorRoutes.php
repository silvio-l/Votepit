<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Action\AbuseReportAction;
use Votepit\Http\Action\OperatorAccountAction;
use Votepit\Http\Action\OperatorBoardAction;
use Votepit\Http\Action\OperatorUpdateAction;
use Votepit\Http\Action\OperatorUsageAction;
use Votepit\Http\Action\OperatorUserAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AbuseReportRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\RateLimiter;
use Votepit\Update\CommunityStateStore;

/**
 * Platform operator panel (unprefixed, AuthZMiddleware::operator()/
 * support()): usage overview, account/board lock/unlock/delete, user
 * password-reset/block/end-sessions, abuse-report review — plus the public
 * abuse-report intake POST /reports (DSA Art. 16).
 *
 * Registered before the first {account}-prefixed route (cloud-routing
 * safety net, see AppFactory::create()).
 */
final class OperatorRoutes
{
    /**
     * Operator panel + public abuse-report intake.
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
        BoardRepository $boardRepo,
        IdeaRepository $ideaRepo,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        PasswordResetMailer $passwordResetMailer,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        NotificationRepository $notificationRepo,
        ErrorReporter $reporter,
        ?\Votepit\Cleanup\BoardAssetCleanup $boardAssetCleanup = null,
    ): void {
        // ---------------------------------------------------------------
        // Operator panel (platform super-admin): a NEW authz
        // tier STRICTLY ABOVE account-scoping (AuthZMiddleware::operator(),
        // users.is_operator — see that middleware's class doc). Every
        // route below is platform-wide, NOT account-prefixed — it MUST be
        // registered here, before the first $accountPrefix route, for the
        // same FastRoute-collision reason as /invite/accept
        // and /api/v1/* above (cloud-routing safety net, see the comment
        // block in AppFactory::create()). POST /reports is the one exception: anon
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

        // SupportRequestRepository is built here purely so
        // OperatorUsageAction can fold its open-ticket count into the
        // usage overview, the same way it already does for
        // $abuseReportRepo (the support routes themselves live in
        // SupportRoutes).
        $supportRequestRepo = new SupportRequestRepository($conn);

        $operatorAccountAction = new OperatorAccountAction($accountRepoForPlan, $audit, $reporter, $boardAssetCleanup);
        $operatorBoardAction   = new OperatorBoardAction($boardRepo, $audit, $reporter, $boardAssetCleanup);
        $operatorUserAction    = new OperatorUserAction($userRepo, $hasher, $passwordResetMailer, $audit);
        $operatorUsageAction   = new OperatorUsageAction($accountRepoForPlan, $boardRepo, $ideaRepo, $userRepo, $abuseReportRepo, $supportRequestRepo, $config);

        $app->get('/operator/usage', $operatorUsageAction)
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        // Community update status (banner). The installation root is the core root:
        // this file lives in src/Http/Routes/, three levels below it.
        $operatorUpdateAction = new OperatorUpdateAction(new CommunityStateStore($conn), $config, dirname(__DIR__, 3));
        $app->get('/operator/update', $operatorUpdateAction)
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->get('/operator/accounts', $operatorAccountAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/accounts/{id:[0-9]+}/lock', $operatorAccountAction->lock(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/accounts/{id:[0-9]+}/unlock', $operatorAccountAction->unlock(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/accounts/{id:[0-9]+}/delete', $operatorAccountAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        // POST /operator/users/password-reset — trigger a mail-based reset
        // link for ANY platform user, identified by re-typed email
        // (Punkt 5d). AuthZ: support() — is_support OR is_operator, both
        // tiers may use it (see OperatorUserAction class doc).
        $app->post('/operator/users/password-reset', $operatorUserAction->passwordReset(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        // POST /operator/users/block — global kill switch (Punkt
        // V7.4.2/V7.4.5 remediation). Body: { email, blocked: bool }.
        $app->post('/operator/users/block', $operatorUserAction->block(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        // POST /operator/users/end-sessions — force-logout a target user
        // without blocking them (account-takeover response). Body: { email }.
        $app->post('/operator/users/end-sessions', $operatorUserAction->endSessions(...))
            ->add(AuthZMiddleware::support($responseFactory, $authzDenialLogger));

        $app->get('/operator/boards', $operatorBoardAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/boards/{id:[0-9]+}/lock', $operatorBoardAction->lock(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/boards/{id:[0-9]+}/unlock', $operatorBoardAction->unlock(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/boards/{id:[0-9]+}/delete', $operatorBoardAction->delete(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->get('/operator/reports', $abuseReportAction->list(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));

        $app->post('/operator/reports/{id:[0-9]+}/review', $abuseReportAction->review(...))
            ->add(AuthZMiddleware::operator($responseFactory, $authzDenialLogger));
    }
}
