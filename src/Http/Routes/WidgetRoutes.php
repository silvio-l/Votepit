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
use Votepit\Http\Action\BoardHomeAction;
use Votepit\Http\Action\BoardWidgetAction;
use Votepit\Http\Action\CommentCreateAction;
use Votepit\Http\Action\IdeaCreateAction;
use Votepit\Http\Action\VoteAction;
use Votepit\Http\Action\WidgetIdentifyAction;
use Votepit\Http\Action\WidgetPageAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\BlockCheckMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Middleware\WidgetTokenAuthMiddleware;
use Votepit\Http\Support\WidgetGate;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\BoardWidgetRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\RateLimiter;
use Votepit\Security\WidgetJwtVerifier;
use Votepit\Security\WidgetTokenService;
use Votepit\Support\Clock;

/**
 * Embeddable widget (ADR 0005): the iframe page, signed identify, the
 * widget-token routes and the owner settings.
 *
 * Trust paths:
 *  - GET  {board}/widget           — iframe shell, AuthZ anon (public board
 *    read; 404 unless the widget is live). The only response with a
 *    relaxed frame-ancestors (SecurityHeaderMiddleware).
 *  - POST {board}/widget/identify  — AuthZ anon; the customer-signed HS256
 *    JWT is the capability; per-IP rate limit.
 *  - {board}/widget/ideas…         — WidgetTokenAuthMiddleware (Bearer
 *    widget token, never the session) → route-level BlockCheck → per-action
 *    rate limit → AuthZMiddleware::user() → the SAME action instances the
 *    first-party routes use (IdeaRoutes/CommentRoutes/BoardHomeAction), so
 *    validation, moderation, Turnstile, frozen-board and block checks are
 *    identical on both paths.
 *  - /admin/boards/{slug}/widget…  — AuthZMiddleware::accountAdmin().
 *
 * Every public widget route is named with CsrfMiddleware::
 * WIDGET_ROUTE_NAME_PREFIX: cookie-less, Bearer-authenticated, no CSRF
 * cookie. Unidentified visitors only read (the public board/idea GET
 * routes); every action opens the board in a new first-party tab.
 */
final class WidgetRoutes
{
    public const PAGE           = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'page';
    public const IDENTIFY       = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'identify';
    public const IDEAS          = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'ideas';
    public const IDEA_CREATE    = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'idea_create';
    public const VOTE           = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'vote';
    public const COMMENT_CREATE = CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX . 'comment_create';

    /**
     * Extension middleware attached to these core routes is applied to
     * their widget twin as well (AppFactory), so e.g. an abuse guard on
     * idea submit cannot be bypassed through the widget path.
     */
    public const TWINS = [
        CoreRoute::IDEA_CREATE    => self::IDEA_CREATE,
        CoreRoute::IDEA_VOTE      => self::VOTE,
        CoreRoute::COMMENT_CREATE => self::COMMENT_CREATE,
    ];

    /**
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
        string $widgetShellPath,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        TagRepository $tagRepo,
        BoardStatusRepository $boardStatusRepo,
        UserRepository $userRepo,
        BlockRepository $blockRepo,
        IdentityHasher $hasher,
        IdeaCreateAction $ideaCreateAction,
        VoteAction $voteAction,
        CommentCreateAction $commentCreateAction,
        Clock $clock,
    ): void {
        $widgetRepo       = new BoardWidgetRepository($conn);
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $brandResolver    = new BrandResolver($accountPlans, $planPolicy, new \Votepit\Persistence\BoardAssetRepository($conn));
        $gate             = new WidgetGate($widgetRepo, $accountPlans, $planPolicy);
        $secretEncryption = new EncryptionService($config->appKey, 'board_widget');
        $tokens           = new WidgetTokenService($config->appKey);
        $tokenAuth        = new WidgetTokenAuthMiddleware($tokens, $boardRepo, $gate, $userRepo, $responseFactory);
        $blockCheck       = new BlockCheckMiddleware($responseFactory, $blockRepo);

        $byUser = static function (ServerRequestInterface $r): ?string {
            $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
            return is_array($user) ? (string) ($user['id'] ?? '') : null;
        };
        $perUser = static function (string $bucket) use ($config, $conn, $responseFactory, $byUser): RateLimitMiddleware {
            $limit = $config->rateLimit($bucket);
            return RateLimitMiddleware::perAction(new RateLimiter($conn), $responseFactory, $bucket, $limit['limit'], $limit['window'], $byUser);
        };

        // ── Owner settings ────────────────────────────────────────────────
        // Fixed /admin/... paths, registered with the other account-scoped
        // admin routes (before any {board} catch-all).
        $settings = new BoardWidgetAction($boardRepo, $widgetRepo, $gate, $secretEncryption, $audit);

        $app->get($accountPrefix . '/admin/boards/{slug}/widget', $settings->get(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->put($accountPrefix . '/admin/boards/{slug}/widget', $settings->put(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/widget/secret', $settings->rotateSecret(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // ── Iframe page + identify (public, capability-based) ─────────────
        $app->get($accountPrefix . '/{board}/widget', new WidgetPageAction($boardRepo, $gate, $widgetRepo, $widgetShellPath, $brandResolver))
            ->setName(self::PAGE)
            ->add(AuthZMiddleware::anon($responseFactory));

        $identifyLimit = $config->rateLimit('widget:identify');
        $app->post(
            $accountPrefix . '/{board}/widget/identify',
            new WidgetIdentifyAction($boardRepo, $gate, $secretEncryption, new WidgetJwtVerifier(), $tokens, $hasher, $userRepo, $audit),
        )
            ->setName(self::IDENTIFY)
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'widget:identify',
                $identifyLimit['limit'],
                $identifyLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

        // ── Widget-token routes (identified visitor) ───────────────────────
        // Middleware order (outer → inner): WidgetTokenAuth → BlockCheck →
        // rate limit → AuthZ user → action. Slim runs the LAST added first.
        $app->get(
            $accountPrefix . '/{board}/widget/ideas',
            new BoardHomeAction($boardRepo, $ideaRepo, $accountMemberRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $tagRepo, $boardStatusRepo, $clock, $brandResolver),
        )
            ->setName(self::IDEAS)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add($perUser('widget:read'))
            ->add($blockCheck)
            ->add($tokenAuth);

        $app->post($accountPrefix . '/{board}/widget/ideas', $ideaCreateAction)
            ->setName(self::IDEA_CREATE)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add($perUser('idea:submit'))
            ->add($blockCheck)
            ->add($tokenAuth);

        $app->post($accountPrefix . '/{board}/widget/ideas/{id:[0-9]+}/vote', $voteAction)
            ->setName(self::VOTE)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add($perUser('idea:vote'))
            ->add($blockCheck)
            ->add($tokenAuth);

        $app->post($accountPrefix . '/{board}/widget/ideas/{id:[0-9]+}/comments', $commentCreateAction)
            ->setName(self::COMMENT_CREATE)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger))
            ->add($perUser('comment:user'))
            ->add($blockCheck)
            ->add($tokenAuth);
    }
}
