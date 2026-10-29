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
use Votepit\Http\Action\AccountMemberStandingAction;
use Votepit\Http\Action\InviteAction;
use Votepit\Http\Action\MemberAction;
use Votepit\Http\Action\TrustedAuthorAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\RateLimiter;
use Votepit\Security\TokenVault;

/**
 * Account membership: member list/remove/role/password-reset/standing and
 * invitations under {account}/admin/members and {account}/admin/invites,
 * plus the trusted-authors list under {account}/admin/trusted-authors.
 */
final class MemberRoutes
{
    /**
     * Members + invites (account-scoped).
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
        AccountMemberStandingRepository $standingRepo,
        ErrorReporter $reporter,
    ): void {
        // GET /admin/members — list members + open invites.
        // AuthZ: accountAdmin (owner + admin may read; moderator cannot —
        // restricted to comment/idea moderation only, see accountModerate()).
        // POST /admin/members/{userId}/remove, /role — mutations are owner-only.
        // The account's owner never appears in the returned list (see
        // MemberAction::list()) — they can't be removed or re-roled via
        // this UI, so there is nothing to act on for their own row.
        // $inviteRepo was already constructed in AppFactory::create()
        // (shared with InviteAndSignupRoutes) — only referenced here.
        $memberAction = new MemberAction($accountMemberRepo, $inviteRepo, $userRepo, $hasher, $passwordResetMailer, $audit, $standingRepo, $reporter);

        $app->get($accountPrefix . '/admin/members', $memberAction->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/members/{userId:[0-9]+}/remove', $memberAction->remove(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/members/{userId:[0-9]+}/role', $memberAction->changeRole(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/members/password-reset — trigger a mail-based reset
        // link for a member, identified by re-typed email (AuthZ:
        // accountAdmin — owner + admin, see MemberAction class doc).
        $app->post($accountPrefix . '/admin/members/password-reset', $memberAction->passwordReset(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // PATCH /admin/members/{userId}/standing — manual repeat-offender
        // standing override (board-operator-compliance issue 10). AuthZ:
        // accountModerate (owner|admin|moderator — a moderation decision,
        // not an account-management one, unlike the owner-only
        // remove()/changeRole() routes above; see AccountMemberStandingAction's
        // class doc / PRD user story 24).
        $app->patch(
            $accountPrefix . '/admin/members/{userId:[0-9]+}/standing',
            new AccountMemberStandingAction($accountMemberRepo, $standingRepo, $audit),
        )
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // GET  /admin/trusted-authors                 — trusted authors list.
        // POST /admin/trusted-authors/{userId}/revoke — clear one trust.
        // (broaden-use-cases issue 11) AuthZ: accountModerate, same reasoning
        // as the standing override above; CSRF global; account-scoped by the
        // standing table's primary key (see TrustedAuthorAction).
        $trustedAuthorAction = new TrustedAuthorAction($standingRepo, $audit);

        $app->get($accountPrefix . '/admin/trusted-authors', $trustedAuthorAction->list(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/trusted-authors/{userId:[0-9]+}/revoke', $trustedAuthorAction->revoke(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/invites — invite by email. AuthZ: accountOwner
        // (invite is one of the owner-only actions, see acceptance criteria);
        // CSRF globally enforced; per-action rate limit invite:send.
        // POST /admin/invites/{id}/revoke — revoke an open invite (AuthZ: accountOwner).
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $inviteAction   = new InviteAction(
            $userRepo,
            $accountMemberRepo,
            $inviteRepo,
            $accountPlans,
            $planPolicy,
            $hasher,
            $vault,
            $mailer,
            $smtpResolver,
            $config,
            $audit,
            $conn,
        );
        $inviteRateLimit = $config->rateLimit('invite:send');

        $app->post($accountPrefix . '/admin/invites', $inviteAction->send(...))
            ->setName(CoreRoute::INVITE_SEND)
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger))
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
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));
    }
}
