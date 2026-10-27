<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\UserDataExportService;
use Votepit\Domain\UserDeletionGuard;
use Votepit\Http\Action\AccountOAuthAction;
use Votepit\Http\Action\AccountProfileAction;
use Votepit\Http\Action\AvatarServeAction;
use Votepit\Http\Action\DigestUnsubscribeAction;
use Votepit\Http\Action\MarketingConsentAction;
use Votepit\Http\Action\NotificationPreferencesAction;
use Votepit\Http\Action\OperatorNudgePreferencesAction;
use Votepit\Http\Action\OperatorNudgeUnsubscribeAction;
use Votepit\Http\Action\PublicProfileAction;
use Votepit\Http\Action\UserSelfServiceAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\NotificationEmailVerificationRepository;
use Votepit\Persistence\OAuthIdentityRepository;
use Votepit\Persistence\OperatorNudgeRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\UserSocialLinkRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AvatarProcessor;
use Votepit\Security\ClientIp;
use Votepit\Security\DigestUnsubscribeToken;
use Votepit\Security\IdentityHasher;
use Votepit\Security\OperatorNudgeUnsubscribeToken;
use Votepit\Security\RateLimiter;
use Votepit\Security\TokenVault;

/**
 * User-owned profile/account settings under /account/* (avatar, social
 * links, privacy, username, OAuth identities, notification preferences),
 * avatar serving and the account-scoped public member profile.
 */
final class ProfileRoutes
{
    /**
     * profile-avatar-social: user-owned avatar, social links, privacy,
     * username, notification preferences, and the public profile view.
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
        string $root,
        ?string $avatarDirOverride,
        UserRepository $userRepo,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        string $accountPrefix,
        TokenVault $vault,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        ?BoardRepository $boardRepo = null,
        ?AccountRepository $accountRepo = null,
        ?PlanPolicy $planPolicy = null,
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
        $avatarProcessor = new AvatarProcessor($config->avatarMaxUploadBytes(), $config->avatarAllowedImageTypes());
        $userSocialLinkRepo = new UserSocialLinkRepository($conn);
        $accountProfileAction = new AccountProfileAction($userRepo, $userSocialLinkRepo, $avatarProcessor, $avatarDir, $audit);
        $avatarUploadRateLimit = $config->rateLimit('avatar:upload');
        $socialLinksRateLimit  = $config->rateLimit('sociallinks:update');
        $userIdentity = static function (ServerRequestInterface $r): ?string {
            $u = $r->getAttribute(AuthNMiddleware::ATTR_USER);
            return is_array($u) ? (string) ($u['id'] ?? '') : null;
        };

        $app->get('/account/profile', $accountProfileAction->getProfile(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->post('/account/avatar', $accountProfileAction->uploadAvatar(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'avatar:upload',
                $avatarUploadRateLimit['limit'],
                $avatarUploadRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->delete('/account/avatar', $accountProfileAction->deleteAvatar(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->put('/account/social-links', $accountProfileAction->putSocialLinks(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'sociallinks:update',
                $socialLinksRateLimit['limit'],
                $socialLinksRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->put('/account/privacy', $accountProfileAction->putPrivacy(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

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
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GET /account/oauth-identities + DELETE /account/oauth-identities/{provider}
        // — lets a user see and unlink the OAuth providers connected to their
        // own account (AuthZ: user, same "own row only" posture as every
        // other /account/* route above). Stateless repository wrapping the
        // same $conn/table as the login flow's $oauthIdentityRepo — that one
        // lives in a different method scope (build() vs. this static method).
        $accountOAuthAction = new AccountOAuthAction(new OAuthIdentityRepository($conn), $audit);

        $app->get('/account/oauth-identities', $accountOAuthAction->list(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->delete('/account/oauth-identities/{provider}', $accountOAuthAction->unlink(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // notification-preferences — user-scoped, same convention as the profile routes
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
            new ParticipantMailRepository($conn),
        );

        $app->get('/account/notification-preferences', $notificationPreferencesAction->getPreferences(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->put('/account/notification-preferences', $notificationPreferencesAction->putPreferences(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // Product-news consent (migration 0112, W1): own toggle + double-opt-in confirm.
        // Each route answers 404 unless Config::marketingConsentEnabled (self-host default).
        $marketingConsentAction = new MarketingConsentAction(
            $config,
            $userRepo,
            new MarketingConsentRepository($conn),
            $vault,
            $mailer,
            $smtpResolver,
            $audit,
        );
        $app->get('/account/marketing-consent', $marketingConsentAction->status(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $marketingConsentRateLimit = $config->rateLimit('marketing-consent:update');
        $app->put('/account/marketing-consent', $marketingConsentAction->toggle(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'marketing-consent:update',
                $marketingConsentRateLimit['limit'],
                $marketingConsentRateLimit['window'],
                $userIdentity,
            ))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GET → CSRF-exempt (the one-time token is the capability), analogous to
        // /account/notification-email/confirm; still requires the session of the owner.
        $app->get('/account/marketing-consent/confirm', $marketingConsentAction->confirm(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

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
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GET → CSRF-exempt (the one-time token itself is the capability,
        // analogous to /login/verify) — still requires an authenticated
        // session, since the confirm action additionally checks the
        // token's user_id against the caller (fail-secure, never confirm
        // into the wrong account).
        $app->get('/account/notification-email/confirm', $notificationPreferencesAction->confirmEmail(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $app->delete('/account/notification-email', $notificationPreferencesAction->deleteEmail(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GDPR self-service for every user (competitor-parity issue 12):
        // own-data export + self-deletion with a 48h grace period. Same
        // user-scoped posture as every /account/* route above (AuthZ: user,
        // own row only). See UserSelfServiceAction class doc.
        $userSelfServiceAction = new UserSelfServiceAction(
            $userRepo,
            new UserDataExportService(
                $userRepo,
                $userSocialLinkRepo,
                $accountMemberRepo,
                new OAuthIdentityRepository($conn),
                $ideaRepo,
                new CommentRepository($conn),
                new VoteRepository($conn),
                new CommentReactionRepository($conn),
                new MarketingConsentRepository($conn),
            ),
            new UserDeletionGuard($accountMemberRepo, $userRepo),
            new IdentityHasher($config->identityServerKey),
            $audit,
        );

        foreach ([
            ['GET', '/account/export', 'user:export', $userSelfServiceAction->export(...)],
            ['POST', '/account/delete', 'user:delete', $userSelfServiceAction->requestDeletion(...)],
            ['POST', '/account/delete/cancel', 'user:delete:cancel', $userSelfServiceAction->cancelDeletion(...)],
        ] as [$method, $path, $bucket, $handler]) {
            $limit = $config->rateLimit($bucket);
            $app->map([$method], $path, $handler)
                ->add(RateLimitMiddleware::perAction(
                    new RateLimiter($conn),
                    $responseFactory,
                    $bucket,
                    $limit['limit'],
                    $limit['window'],
                    $userIdentity,
                ))
                ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        }
        // POST /digest/unsubscribe — weekly owner digest one-click
        // unsubscribe (competitor-parity issue 14). AuthZ: anon by design —
        // the recipient may not be logged in; the signed
        // DigestUnsubscribeToken is the capability and can only switch that
        // one user's digest OFF. CSRF still applies (the SPA page posts it),
        // plus a per-IP brake against token guessing.
        $digestUnsubscribeRateLimit = $config->rateLimit('digest:unsubscribe');
        $app->post('/digest/unsubscribe', new DigestUnsubscribeAction($userRepo, new DigestUnsubscribeToken($config->appKey), $audit))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'digest:unsubscribe',
                $digestUnsubscribeRateLimit['limit'],
                $digestUnsubscribeRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

        // Operator nudges (onboarding, quiet board, plan-limit heads-up): the
        // user-scoped switches for the signed-in owner, plus the one-click
        // unsubscribe from a mail link. The unsubscribe is anon by design (the
        // signed OperatorNudgeUnsubscribeToken is the capability and can only
        // switch one kind off for one user), rate-limited per IP like the digest.
        $operatorNudgeRepo        = new OperatorNudgeRepository($conn);
        $operatorNudgePreferences = new OperatorNudgePreferencesAction($operatorNudgeRepo);
        $app->get('/account/operator-nudges', $operatorNudgePreferences->get(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->put('/account/operator-nudges', $operatorNudgePreferences->put(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $operatorNudgeUnsubscribeRateLimit = $config->rateLimit('operator-nudge:unsubscribe');
        $app->post('/operator-nudges/unsubscribe', new OperatorNudgeUnsubscribeAction(
            $operatorNudgeRepo,
            new OperatorNudgeUnsubscribeToken($config->appKey),
            $audit,
        ))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'operator-nudge:unsubscribe',
                $operatorNudgeUnsubscribeRateLimit['limit'],
                $operatorNudgeUnsubscribeRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

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
        // Optional board context (?board=): the board's branding on the profile page.
        $accountPlans = $accountRepo instanceof \Votepit\Persistence\AccountRepository && $planPolicy instanceof \Votepit\Domain\PlanPolicy ? new AccountPlanResolver($accountRepo, $accountMemberRepo, $planPolicy) : null;
        $publicProfileAction = new PublicProfileAction(
            $userRepo,
            $userSocialLinkRepo,
            $accountMemberRepo,
            $ideaRepo,
            new VoteRepository($conn),
            $boardRepo,
            $accountPlans,
            $planPolicy,
            $planPolicy instanceof \Votepit\Domain\PlanPolicy && $accountPlans instanceof \Votepit\Domain\AccountPlanResolver ? new BrandResolver($accountPlans, $planPolicy, new \Votepit\Persistence\BoardAssetRepository($conn)) : null,
        );
        $app->get($accountPrefix . '/members/{userId:[0-9]+}/profile', $publicProfileAction)
            ->add(AuthZMiddleware::anon($responseFactory));
    }
}
