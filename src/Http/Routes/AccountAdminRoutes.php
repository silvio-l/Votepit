<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountExportService;
use Votepit\Extension\AccountDeletionPrecondition;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Http\Action\AccountDeleteAction;
use Votepit\Http\Action\AccountExportAction;
use Votepit\Http\Action\AccountRenameAction;
use Votepit\Http\Action\AccountSettingsAction;
use Votepit\Http\Action\SupportRequestAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\RateLimiter;

/**
 * Owner-facing account administration: account summary/rename/slug check,
 * GDPR self-service deletion (+ cancel), data export and the account-scoped
 * half of the support-request contact form.
 */
final class AccountAdminRoutes
{
    /**
     * Account settings/rename/delete/export + account-scoped support.
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
        InviteRepository $inviteRepo,
        UserRepository $userRepo,
        IdeaRepository $ideaRepo,
        CommentRepository $commentRepo,
        ApiTokenRepository $apiTokenRepo,
        BoardSmtpSettingsRepository $boardSmtpRepo,
        ModerationConfigRepository $modConfigRepo,
        AccountDeletionPrecondition $deletionPrecondition,
        SupportRequestAction $supportRequestAction,
        BoardComplianceEnforcer $boardComplianceEnforcer,
    ): void {
        // Account-wide block list — read-only here (export); the block
        // write route lives in BoardSettingsRoutes. Stateless repository,
        // one instance per route module.
        $blockRepo = new BlockRepository($conn);

        // GET /admin/account — owner-facing account summary behind the
        // SPA's account settings page (slug for the typed delete
        // confirmation, default-account flag, pending deletion deadline).
        // AuthZ: accountOwner. See AccountSettingsAction class doc.
        $accountSettingsAction = new AccountSettingsAction($accountRepoForPlan);
        $app->get($accountPrefix . '/admin/account', $accountSettingsAction->show(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // PUT  /admin/account                — rename the account's name/slug.
        // GET  /admin/account/slug-available  — live slug-availability check.
        // AuthZ: accountOwner. See AccountRenameAction class doc.
        $accountRenameAction = new AccountRenameAction($accountRepoForPlan, $audit, $boardComplianceEnforcer);
        $app->put($accountPrefix . '/admin/account', $accountRenameAction->rename(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->get($accountPrefix . '/admin/account/slug-available', $accountRenameAction->slugAvailable(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

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
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'account:delete',
                $accountDeleteRateLimit['limit'],
                $accountDeleteRateLimit['window'],
                $ownerIdentity,
            ));

        $app->post($accountPrefix . '/admin/account/delete/cancel', $accountDeleteAction->cancelDeletion(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger))
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
        // the existing repositories (ideaRepo, commentRepo, voteRepo,
        // inviteRepo, apiTokenRepo, boardSmtpRepo, modConfigRepo,
        // blockRepo) — no new query surface, only tightly-
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
            new BoardStatusRepository($conn),
        );
        $exportAction = new AccountExportAction($exportService);
        $exportRateLimit = $config->rateLimit('account:export');

        $app->get($accountPrefix . '/admin/export', $exportAction->download(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger))
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
        // $supportRequestAction was already constructed by
        // OperatorRoutes::register() (its operator-facing routes live there);
        // this is only its account-scoped half.
        $app->post($accountPrefix . '/admin/support', $supportRequestAction->submit(...))
            ->setName(CoreRoute::SUPPORT_REQUEST_SUBMIT)
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->get($accountPrefix . '/admin/support', $supportRequestAction->listMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->get($accountPrefix . '/admin/support/{id:[0-9]+}', $supportRequestAction->getThreadMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/support/{id:[0-9]+}/reply', $supportRequestAction->replyMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/support/{id:[0-9]+}/translate', $supportRequestAction->translateMine(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
