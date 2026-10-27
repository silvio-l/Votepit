<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\ModerationStandingService;
use Votepit\Http\Action\CommentCreateAction;
use Votepit\Http\Action\CommentModerationAction;
use Votepit\Http\Action\CommentReactionAction;
use Votepit\Http\Action\CommentUpdateAction;
use Votepit\Http\Action\ModerationAppealAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\BoardMailBranding;
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
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationAppealRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\RateLimiter;
use Votepit\Security\Turnstile\CurlTurnstileHttpClient;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Security\Turnstile\TurnstileHttpClient;

/**
 * Comment create/moderate/edit/react plus the moderation-appeal routes
 * ({account}/appeals, {account}/moderation/appeals). The appeal routes sit
 * between comment moderation and comment edit/react to keep the
 * historical registration order (pinned by RouteTableSnapshotTest).
 */
final class CommentRoutes
{
    /**
     * Comment create/delete/edit/react + moderation appeals.
     *
     * Returns the comment-create action so WidgetRoutes can mount the same
     * instance behind the widget-token guard.
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
        AccountRepository $accountRepoForPlan,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        CommentRepository $commentRepo,
        ContentModerationService $moderation,
        BlockRepository $blockRepoForCheck,
        ModerationConfigRepository $modConfigRepo,
        NotificationRepository $notificationRepo,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        AccountMemberStandingRepository $standingRepo,
        ModerationStandingService $standingService,
        ?TurnstileHttpClient $turnstileHttpClient,
        BoardStatusRepository $boardStatusRepo,
        ?BoardMailBranding $mailBranding = null,
    ): CommentCreateAction {
        // Comment-reactions (migrations/0050_add_comment_reactions.sql):
        // write side (CommentReactionAction); the detail-page read lives
        // in IdeaRoutes (stateless repository, one instance per module).
        $reactionRepo = new CommentReactionRepository($conn);

        // anti-automation-challenge (issue 20): independent TurnstileGuard
        // instance for comment create — same reasoning as in IdeaRoutes.
        $turnstileGuard = new TurnstileGuard($config, new RateLimiter($conn), $turnstileHttpClient ?? new CurlTurnstileHttpClient());

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
            $mailBranding,
        );

        $commentCreateAction = new CommentCreateAction($conn, $boardRepo, $ideaRepo, $commentRepo, $audit, $moderation, $blockRepoForCheck, $notificationRepo, $userRepo, $commentNotificationMailer, $turnstileGuard, $boardStatusRepo, ParticipantMailRouter::forConfig($conn, $config), new AccountMailLinks($accountRepoForPlan, $config->routingMode), $modConfigRepo, $standingRepo);
        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments',
            $commentCreateAction,
        )
        ->setName(CoreRoute::COMMENT_CREATE)
        ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
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
        // (moderator's other allowed action, board-operator-compliance issue 06:
        // reason-coded, soft delete + author notification). AuthZ: accountModerate;
        // CSRF globally enforced. Board-/idea-scoping in the action (foreign
        // idea/comment, or an already-moderated comment → 404).
        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/delete',
            new CommentModerationAction($boardRepo, $ideaRepo, $commentRepo, new ModerationRepository($conn), $userRepo, $commentNotificationMailer, $audit, $accountRepoForPlan, $config, $standingRepo, $standingService),
        )
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST {accountPrefix}/appeals — the original content author submits
        // an appeal against a moderation action (board-operator-compliance
        // issue 09). AuthZ: user (anon → 401); ownership/tenant checks in
        // the action (403/404). Per-user rate limit appeal:submit.
        // GET/POST {accountPrefix}/moderation/appeals[/{id}/resolve] —
        // moderator/admin appeal review inbox, account-scoped.
        $appealsRepo      = new ModerationAppealRepository($conn);
        $appealAction     = new ModerationAppealAction(new ModerationRepository($conn), $appealsRepo, $ideaRepo, $commentRepo, $audit);
        $appealRateLimit  = $config->rateLimit('appeal:submit');

        $app->post($accountPrefix . '/appeals', $appealAction->submit(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'appeal:submit',
                $appealRateLimit['limit'],
                $appealRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        $app->get($accountPrefix . '/moderation/appeals', $appealAction->list(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/moderation/appeals/{id:[0-9]+}/resolve', $appealAction->resolve(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/ideas/{id}/comments/{commentId}/edit — the author edits
        // their own comment within CommentUpdateAction::EDIT_WINDOW_SECONDS (60s)
        // of posting. AuthZ: user (anon → 401); CSRF globally enforced. Ownership
        // + the edit window are enforced inside the action (403 / 422).
        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/edit',
            new CommentUpdateAction($boardRepo, $ideaRepo, $commentRepo, $audit, $moderation, $modConfigRepo),
        )
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // POST /{board}/ideas/{id}/comments/{commentId}/react — toggle an
        // emoji reaction on a comment. AuthZ: user (anon → 401); CSRF +
        // BlockCheck global; per-action rate limit comment:react (mirrors
        // idea:vote's generosity — reactions are just as lightweight as
        // votes). Board-/idea-/comment-scoping in the action (foreign
        // idea/comment → 404, no mutation). One active reaction per
        // (comment, user) — mirrors idea:vote's toggle/switch/retract.
        $reactRateLimit = $config->rateLimit('comment:react');

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/react',
            new CommentReactionAction($boardRepo, $ideaRepo, $commentRepo, $reactionRepo, $audit, $blockRepoForCheck),
        )
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'comment:react',
                $reactRateLimit['limit'],
                $reactRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        return $commentCreateAction;
    }
}
