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
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\DuplicateCandidateFinder;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\IdeaStatusChanger;
use Votepit\Domain\ModerationStandingService;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Domain\TitleNormalizer;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Extension\TagSuggester;
use Votepit\Http\Action\AnonymousIdeaSubmission;
use Votepit\Http\Action\GuestIdeaAction;
use Votepit\Http\Action\IdeaCreateAction;
use Votepit\Http\Action\IdeaDetailAction;
use Votepit\Http\Action\IdeaEditAction;
use Votepit\Http\Action\IdeaMergeAction;
use Votepit\Http\Action\IdeaModerationAction;
use Votepit\Http\Action\IdeaModerationQueueAction;
use Votepit\Http\Action\IdeaNewAction;
use Votepit\Http\Action\IdeaPinAction;
use Votepit\Http\Action\IdeaSearchDuplicatesAction;
use Votepit\Http\Action\IdeaStatusAction;
use Votepit\Http\Action\IdeaTagSuggestAction;
use Votepit\Http\Action\IdeaWithdrawAction;
use Votepit\Http\Action\VoteAction;
use Votepit\Http\Action\VoteNotifyEmailAction;
use Votepit\Http\Action\VoteReasonAction;
use Votepit\Http\Action\VoteReasonNoteRemoveAction;
use Votepit\Http\Action\VoteReasonsAdminAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\AccountMailLinks;
use Votepit\Mail\BoardMailBranding;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IdeaStatusDecisionRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\ModerationRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteReasonRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousAuthorCookie;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdeaViewTracker;
use Votepit\Security\RateLimiter;
use Votepit\Security\TimeTrapService;
use Votepit\Security\Turnstile\CurlTurnstileHttpClient;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Security\Turnstile\TurnstileHttpClient;
use Votepit\Security\ViewDedupHasher;
use Votepit\Support\Clock;
use Votepit\Webhook\WebhookDispatcher;

/**
 * Board-scoped idea routes: detail, new/submit form, duplicate search,
 * edit/update/withdraw, moderation, vote (+ anonymous notify-email),
 * status and pin.
 */
final class IdeaRoutes
{
    /**
     * Idea detail/CRUD/vote/status/pin under {account}/{board}/ideas.
     *
     * Returns the submit and vote action instances so WidgetRoutes can
     * mount the very same actions behind the widget-token guard (one
     * implementation, two trust paths).
     *
     * @param App<null> $app
     * @return array{idea_create: IdeaCreateAction, vote: VoteAction}
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
        TimeTrapService $timeTrap,
        DuplicateDetectionService $duplicateDetection,
        TitleNormalizer $normalizer,
        ContentModerationService $moderation,
        BlockRepository $blockRepoForCheck,
        ModerationConfigRepository $modConfigRepo,
        NotificationRepository $notificationRepo,
        UserRepository $userRepo,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        TagRepository $tagRepo,
        WebhookDispatcher $webhookDispatcher,
        AccountMemberStandingRepository $standingRepo,
        ModerationStandingService $standingService,
        ?TurnstileHttpClient $turnstileHttpClient,
        BoardStatusRepository $boardStatusRepo,
        Clock $clock,
        IdeaStatusChanger $ideaStatusChanger,
        IdeaStatusDecisionRepository $statusDecisionRepo,
        AnonymousSubmissionGate $anonymousSubmissionGate,
        ?DuplicateConfirmer $duplicateConfirmer = null,
        ?TagSuggester $tagSuggester = null,
        ?PlanPolicy $planPolicy = null,
    ): array {
        // Board branding on idea, submit and edit pages (same rules as the board home).
        // A missing policy means the unrestricted default, as in AppFactory.
        $planPolicy   ??= new UnrestrictedPlanPolicy();
        $accountPlans  = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $brandResolver = new BrandResolver($accountPlans, $planPolicy, new \Votepit\Persistence\BoardAssetRepository($conn));

        // Comment-reactions (migrations/0050_add_comment_reactions.sql):
        // read side for the detail page (IdeaDetailAction); the toggle
        // write lives in CommentRoutes (stateless repository, one
        // instance per route module).
        $reactionRepo = new CommentReactionRepository($conn);

        // anti-automation-challenge (issue 20): an independent
        // TurnstileGuard instance for the guarded actions registered below
        // (idea create, vote) — cheap to construct (stateless wrapper over
        // $conn), and avoids threading the instance constructed in
        // AppFactory::create()'s first `if ($conn instanceof Connection)`
        // block across an unrelated method boundary, which PHPStan cannot
        // prove non-null there (two separate, not nested, conditionals on
        // the same $conn). CommentRoutes builds its own the same way.
        $turnstileGuard = new TurnstileGuard($config, new RateLimiter($conn), $turnstileHttpClient ?? new CurlTurnstileHttpClient());

        // GET /{board}/ideas/{id} — idea detail view.
        // AuthZ: anon (reading is public). Unknown slug or idea → 404.
        // Cross-board leak prevented by board-scoped findInBoard().
        // viewTracker: cookie-free view-count dedup (migrations/
        // 0049_add_ideas_view_count.sql) — reuses identity_server_key
        // (ViewDedupHasher, domain-separated from IdentityHasher's email
        // hashing) and the existing rate_limits table (RateLimiter).
        $viewTracker = new IdeaViewTracker(
            new RateLimiter($conn),
            $ideaRepo,
            new ViewDedupHasher($config->identityServerKey),
            $config->trustCloudflareIp,
        );
        $app->get($accountPrefix . '/{board}/ideas/{id:[0-9]+}', new IdeaDetailAction($boardRepo, $ideaRepo, $commentRepo, $reactionRepo, $accountMemberRepo, $accountRepoForPlan, $accountPlans, $viewTracker, $tagRepo, $boardStatusRepo, $clock, $statusDecisionRepo, $planPolicy, $brandResolver))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board}/ideas/new — SPA route: returns board info + auth status + time-trap stamp
        // (AuthZ: anon). PRG redirect to login is dropped server-side; the SPA evaluates is_authenticated
        // instead. form_at must be echoed back by the SPA as the _form_at field in the POST.
        $app->get($accountPrefix . '/{board}/ideas/new', new IdeaNewAction($boardRepo, $timeTrap, $accountMemberRepo, $clock, $anonymousSubmissionGate, $tagRepo, $tagSuggester, $planPolicy, $accountPlans, $brandResolver))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /{board}/ideas/search-duplicates?title=... — as-you-type duplicate
        // recall for the submit form. AuthZ: anon at the route — signed-in
        // callers keep the `dupsearch:user` bucket; a guest is only served
        // on a board open to guests (ADR 0006 §5b: published titles only,
        // own `idea:dupes:anon:ip` bucket, never the extension confirmer),
        // otherwise the action answers with the unchanged
        // AuthZMiddleware::user() 401.
        $dupSearchRateLimit = $config->rateLimit('dupsearch:user');

        $duplicateFinder = new DuplicateCandidateFinder($ideaRepo, $duplicateDetection, $duplicateConfirmer);
        $guestDuplicateFinder = new DuplicateCandidateFinder($ideaRepo, $duplicateDetection);

        $app->get($accountPrefix . '/{board}/ideas/search-duplicates', new IdeaSearchDuplicatesAction(
            $boardRepo,
            $duplicateFinder,
            $guestDuplicateFinder,
            $anonymousSubmissionGate,
            new RateLimiter($conn),
            $config,
            $responseFactory,
            $authzDenialLogger,
        ))
        ->setName(CoreRoute::IDEA_SEARCH_DUPLICATES)
        ->add(AuthZMiddleware::anon($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'dupsearch:user',
            $dupSearchRateLimit['limit'],
            $dupSearchRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas — create an idea.
        // AuthZ: user (anon → 401); CSRF globally enforced; per-action rate limit idea:submit.
        $submitRateLimit = $config->rateLimit('idea:submit');

        // Pre-moderation (issue 05): role lookup + aggregated moderator notice.
        $premoderationNotifier = new PremoderationNotifier($accountMemberRepo, $notificationRepo, $userRepo);

        // Guest submission (ADR 0006): same route, AuthZ anon; the action
        // hands session-less requests to AnonymousIdeaSubmission, which
        // answers with the unchanged AuthZMiddleware::user() 401 unless the
        // board is open to guests. idea:submit below keeps returning no
        // identity for guests — their buckets live in the guest path.
        $anonAuthorCookie  = new AnonymousAuthorCookie($config->env === 'prod');
        $anonAuthorHasher  = new AnonymousAuthorHasher($config->identityServerKey);
        $guestSubmission   = new AnonymousIdeaSubmission(
            $boardRepo,
            $ideaRepo,
            $userRepo,
            $normalizer,
            $audit,
            $moderation,
            $modConfigRepo,
            $timeTrap,
            $turnstileGuard,
            new RateLimiter($conn),
            $config,
            $anonymousSubmissionGate,
            $anonAuthorCookie,
            $anonAuthorHasher,
            $responseFactory,
            $authzDenialLogger,
            $premoderationNotifier,
            $webhookDispatcher,
            $clock,
            $tagRepo,
        );

        // Guest device view (ADR 0006 §7): own ideas + withdraw while pending.
        // AuthZ anon; 404 unless the public board is open to guests; the
        // device hash is account-bound (a foreign cookie matches nothing).
        $guestIdeaAction = new GuestIdeaAction($boardRepo, $ideaRepo, $userRepo, $anonymousSubmissionGate, $anonAuthorCookie, $anonAuthorHasher, new RateLimiter($conn), $config, $audit);
        $app->get($accountPrefix . '/{board}/ideas/guest-mine', $guestIdeaAction->mine(...))
            ->add(AuthZMiddleware::anon($responseFactory));
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/guest-withdraw', $guestIdeaAction->withdraw(...))
            ->add(AuthZMiddleware::anon($responseFactory));

        $ideaCreateAction = new IdeaCreateAction($boardRepo, $ideaRepo, $normalizer, $audit, $moderation, $timeTrap, $blockRepoForCheck, $turnstileGuard, $modConfigRepo, $webhookDispatcher, $standingRepo, $accountMemberRepo, $premoderationNotifier, $clock, null, $tagRepo);
        $app->post($accountPrefix . '/{board}/ideas', $ideaCreateAction->withGuestSubmission($guestSubmission))
        ->setName(CoreRoute::IDEA_CREATE)
        ->add(AuthZMiddleware::anon($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:submit',
            $submitRateLimit['limit'],
            $submitRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // GET /{board}/ideas/{id}/edit — edit form.
        // AuthZ: anon; row-level ownership check in the action; anon → 401 JSON.
        // The time-trap stamp is embedded as a hidden field.
        $editAction = new IdeaEditAction(
            $boardRepo,
            $ideaRepo,
            $normalizer,
            $audit,
            $moderation,
            $timeTrap,
            $blockRepoForCheck,
            $modConfigRepo,
            $planPolicy,
            $accountPlans,
            $accountMemberRepo,
            $brandResolver,
            $tagRepo,
        );

        // GET /edit: anon → 401 JSON (in-action).
        // Ownership check also in the action (404/403).
        $app->get($accountPrefix . '/{board}/ideas/{id:[0-9]+}/edit', $editAction->getEdit(...))
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /{board}/ideas/{id} — update an idea.
        // AuthZ: user; row-level ownership check in the action; CSRF globally enforced.
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}', $editAction->postEdit(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // POST /{board}/ideas/{id}/withdraw — withdraw an idea / hard delete.
        // AuthZ: user; row-level ownership check in the action; CSRF globally enforced.
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/withdraw', new IdeaWithdrawAction($boardRepo, $ideaRepo, $audit, $blockRepoForCheck))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // POST /{board}/ideas/{id}/moderate — remove an idea (moderation,
        // board-operator-compliance issue 07: reason-coded, soft delete +
        // author notification, the idea-side counterpart of
        // /comments/{commentId}/delete in CommentRoutes). Not /withdraw — that path is
        // already the author's own hard-delete (IdeaWithdrawAction above).
        // AuthZ: accountModerate; CSRF globally enforced. Board-scoping in
        // the action (foreign idea, or an already-moderated idea → 404).
        // Own CommentNotificationMailer instance (same shared global
        // notification-mail:global rate-limit bucket, see
        // the IdeaStatusChanger built in AppFactory) — the comment routes build their
        // own $commentNotificationMailer in CommentRoutes.
        $ideaModerationRateLimit  = $config->rateLimit('notification-mail:global');
        $ideaModerationMailer     = new CommentNotificationMailer(
            new RateLimiter($conn),
            $mailer,
            $smtpResolver,
            $config,
            $ideaModerationRateLimit['limit'],
            $ideaModerationRateLimit['window'],
            BoardMailBranding::forConnection($conn, $accountRepoForPlan, $planPolicy),
        );

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/moderate',
            new IdeaModerationAction($boardRepo, $ideaRepo, new ModerationRepository($conn), $userRepo, $ideaModerationMailer, $audit, $accountRepoForPlan, $config, $standingRepo, $standingService),
        )
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // Pre-moderation approval queue (broaden-use-cases issue 05).
        // GET  /admin/boards/{slug}/queue         — pending ideas of a board.
        // POST /admin/boards/{slug}/queue/approve — publish selected ideas.
        // POST /admin/boards/{slug}/queue/approve-and-trust — publish one idea
        //      and mark its author as trusted in this account (issue 11).
        // AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
        // account role → 403); CSRF + BlockCheck global. Account-scoped via
        // findBySlugForAccount (foreign board → 404); approval UPDATEs are
        // board-bound (foreign idea ids are skipped). Reject = the existing
        // /{board}/ideas/{id}/moderate route above.
        $moderationQueueAction = new IdeaModerationQueueAction($boardRepo, $ideaRepo, $audit, $premoderationNotifier, $webhookDispatcher, $userRepo, new ModerationRepository($conn), $conn, $standingRepo);

        $app->get($accountPrefix . '/admin/boards/{slug}/queue', $moderationQueueAction->list(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/queue/approve', $moderationQueueAction->approve(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/queue/approve-and-trust', $moderationQueueAction->approveAndTrust(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/boards/{slug}/moderation/guest-reject-all — reject every
        // pending guest idea of this board at once (ADR 0006 §5a, needs
        // `confirm`). AuthZ accountModerate, CSRF global, account-scoped via
        // findBySlugForAccount (foreign board → 404); members' ideas never touched.
        $app->post($accountPrefix . '/admin/boards/{slug}/moderation/guest-reject-all', $moderationQueueAction->rejectAllGuest(...))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /{board}/ideas/{id}/vote — cast/change/retract a vote.
        // AuthZ: anon() at route level — VoteAction itself enforces "user OR
        // board.anonymous_voting_enabled" (ADR 0004; every board defaults to
        // the flag off, so this is behaviourally IDENTICAL to the previous
        // hard 401 until an owner opts in). CSRF + BlockCheck global (the
        // authenticated path only — see VoteAction's class doc); per-action
        // rate limit idea:vote. Board-scoping in the action via findInBoard
        // (foreign idea → 404, no row).
        $voteRepo      = new VoteRepository($conn);
        $voteRateLimit = $config->rateLimit('idea:vote');
        // Same $secure derivation as AppFactory::create() (Config::env ===
        // 'prod') — this method has its own parameter list and doesn't
        // inherit that local, so it's recomputed identically here.
        $anonVoteCookie  = new AnonymousVoteCookie($config->env === 'prod');
        $anonVoterHasher = new AnonymousVoterHasher($config->identityServerKey);
        // Own EncryptionService context ('anon_vote_email', not 'smtp'/
        // 'totp'/'abuse_report'/'board_webhook') — key separation from
        // every other at-rest secret under the same app_key (ADR 0004,
        // migrations/0069_replace_voter_email_hash_with_encryption.sql).
        // Shared between VoteAction (encrypt on cast) and IdeaStatusAction
        // (decrypt on status-change fan-out) below.
        $anonVoteEmailEncryption = new EncryptionService($config->appKey, 'anon_vote_email');

        $voteAction = new VoteAction($boardRepo, $ideaRepo, $voteRepo, $audit, $blockRepoForCheck, $turnstileGuard, $anonVoteCookie, $anonVoterHasher, $anonVoteEmailEncryption, $boardStatusRepo, $clock);
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/vote', $voteAction)
        ->setName(CoreRoute::IDEA_VOTE)
        ->add(AuthZMiddleware::anon($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:vote',
            $voteRateLimit['limit'],
            $voteRateLimit['window'],
            static function (ServerRequestInterface $r) use ($config): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                if (is_array($user)) {
                    return (string) ($user['id'] ?? '');
                }
                // Anonymous voting (ADR 0004): previously unreachable here
                // (AuthZ::user() denied before this middleware's identity
                // callback mattered), so an anonymous requester falls back
                // to client-IP identity — same raw-IP convention as
                // RateLimitMiddleware::perIp() elsewhere in the route table, not
                // hashed (the rate_limits table isn't the audit log; only
                // AuditIpHasher's specific PII-sensitive surface is hashed).
                return ClientIp::resolve($r, $config->trustCloudflareIp);
            },
        ));

        // POST /{board}/ideas/{id}/vote/reason — optional reason for a DOWN vote
        // (P-03, migration 0099). Own identity rule (same as the vote: user or
        // anonymous cookie); the action refuses without the caller's own down
        // vote. FrozenBoardGuard inside the action (423). Rate limit: the vote
        // bucket, so reasons cost no extra budget class.
        // GET /admin/boards/{slug}/ideas/{id}/vote-reasons — operator aggregate
        // (owner/admin/moderator via accountModerate, no identities).
        $voteReasonRepo   = new VoteReasonRepository($conn);
        $voteReasonAction = new VoteReasonAction($boardRepo, $ideaRepo, $voteRepo, $voteReasonRepo, $blockRepoForCheck, $boardStatusRepo, $anonVoteCookie, $anonVoterHasher, $audit, $clock, $config->voteReasonsEnabled);
        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/vote/reason', $voteReasonAction)
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'idea:vote',
                $voteRateLimit['limit'],
                $voteRateLimit['window'],
                static function (ServerRequestInterface $r) use ($config): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    if (is_array($user)) {
                        return (string) ($user['id'] ?? '');
                    }

                    return ClientIp::resolve($r, $config->trustCloudflareIp);
                },
            ));

        $voteReasonsAdminAction = new VoteReasonsAdminAction($boardRepo, $ideaRepo, $voteReasonRepo);
        $app->get($accountPrefix . '/admin/boards/{slug}/ideas/{id:[0-9]+}/vote-reasons', $voteReasonsAdminAction)
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST …/vote-reasons/note/remove — moderator takedown of one free text
        // (owner answer 22a); the reason choice stays. AuthZ: accountModerate.
        // Open on frozen boards (owner answer 26a, moderation takedown class).
        $app->post($accountPrefix . '/admin/boards/{slug}/ideas/{id:[0-9]+}/vote-reasons/note/remove', new VoteReasonNoteRemoveAction($boardRepo, $ideaRepo, $voteReasonRepo, $audit))
            ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // PATCH /{board}/ideas/{id}/vote/notify-email — add/change/clear the
        // optional notification email on an anonymous voter's EXISTING vote
        // (ADR 0004 UX follow-up). AuthZ: anon() at route level — the action
        // itself is anonymous-only and naturally 404s otherwise (no
        // matching voter_token_hash row). CSRF global. No dedicated rate
        // limit: only reachable once the harder cast-time gates on the vote
        // route above have already produced the vote row this requires.
        $app->patch(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/vote/notify-email',
            new VoteNotifyEmailAction($boardRepo, $ideaRepo, $voteRepo, $audit, $anonVoteCookie, $anonVoterHasher, $anonVoteEmailEncryption),
        )
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /{board}/ideas/{id}/status — set status.
        // AuthZ: accountModerate (owner|admin|moderator — one of
        // moderator's two allowed actions, anon → 401, missing account
        // role → 403); CSRF + BlockCheck global; per-action rate limit
        // idea:status. Board-scoping in the action via findInBoard (foreign idea → 404,
        // no mutation).
        $statusRateLimit = $config->rateLimit('idea:status');

        $app->post(
            $accountPrefix . '/{board}/ideas/{id:[0-9]+}/status',
            new IdeaStatusAction($boardRepo, $ideaRepo, $boardStatusRepo, $ideaStatusChanger),
        )
        ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:status',
            $statusRateLimit['limit'],
            $statusRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // PATCH /{board}/ideas/{id}/dates (Roadmap Gantt start_date/target_date)
        // is NOT a core route — it ships in the separate `roadmap-gantt`
        // AppExtension package (ADR 0004) and is registered by
        // RoadmapGanttExtension::register() exactly like CloudExtension
        // registers its own routes, via the same ExtensionContext.

        // POST /{board}/ideas/{id}/pin — pin/unpin.
        // AuthZ: accountModerate (owner|admin|moderator, anon → 401,
        // missing account role → 403); CSRF + BlockCheck global; per-action rate limit
        // idea:pin. Board-scoping in the action via findInBoard (foreign idea → 404,
        // no mutation).
        $pinRateLimit = $config->rateLimit('idea:pin');

        $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/pin', new IdeaPinAction($boardRepo, $ideaRepo, $audit))
        ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:pin',
            $pinRateLimit['limit'],
            $pinRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        ));

        // POST /{board}/ideas/tag-suggestions — AI tag suggestions while the
        // submitter writes (P-35). AuthZ: user (guests get the 401 of
        // AuthZMiddleware::user()); own `ai:tag-suggest` rate-limit bucket.
        $tagSuggestRateLimit = $config->rateLimit('ai:tag-suggest');
        $app->post($accountPrefix . '/{board}/ideas/tag-suggestions', new IdeaTagSuggestAction($boardRepo, $tagRepo, $tagSuggester))
            ->add(AuthZMiddleware::user($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'ai:tag-suggest',
                $tagSuggestRateLimit['limit'],
                $tagSuggestRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        // Merging duplicate ideas (competitor-parity issue 10, all plans and
        // self-host): POST merge / POST unmerge / GET merge-candidates.
        // AuthZ: accountModerate (owner|admin|moderator, anon → 401, missing
        // account role → 403); CSRF + BlockCheck global on the POSTs; one
        // per-user rate-limit bucket idea:merge for all three. Board- and
        // account-scoping in the action (foreign idea/target → 404/422, no
        // mutation). The notification fan-out reuses the moderation mailer
        // above (same global notification-mail bucket).
        $mergeAction = new IdeaMergeAction(
            $conn,
            $boardRepo,
            $ideaRepo,
            $voteRepo,
            $commentRepo,
            $duplicateFinder,
            $audit,
            $notificationRepo,
            $userRepo,
            $ideaModerationMailer,
            $anonVoteEmailEncryption,
            new AccountMailLinks($accountRepoForPlan, $config->routingMode),
            $blockRepoForCheck,
            $webhookDispatcher,
        );
        $mergeRateLimit = $config->rateLimit('idea:merge');
        $mergeRoutes    = [
            $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/merge', $mergeAction->merge(...)),
            $app->post($accountPrefix . '/{board}/ideas/{id:[0-9]+}/unmerge', $mergeAction->unmerge(...)),
            $app->get($accountPrefix . '/{board}/ideas/{id:[0-9]+}/merge-candidates', $mergeAction->candidates(...)),
        ];
        foreach ($mergeRoutes as $route) {
            $route
                ->add(AuthZMiddleware::accountModerate($responseFactory, $accountMemberRepo, $authzDenialLogger))
                ->add(RateLimitMiddleware::perAction(
                    new RateLimiter($conn),
                    $responseFactory,
                    'idea:merge',
                    $mergeRateLimit['limit'],
                    $mergeRateLimit['window'],
                    static function (ServerRequestInterface $r): ?string {
                        $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                        return is_array($user) ? (string) ($user['id'] ?? '') : null;
                    },
                ));
        }

        return ['idea_create' => $ideaCreateAction, 'vote' => $voteAction];
    }
}
