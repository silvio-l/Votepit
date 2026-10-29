<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Http\Action\IdeaFollowAction;
use Votepit\Http\Action\ParticipantMailPreferencesAction;
use Votepit\Http\Action\ParticipantUnsubscribeAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\ClientIp;
use Votepit\Security\ParticipantUnsubscribeToken;
use Votepit\Security\RateLimiter;

/**
 * Routes of the participant mails (close-the-loop re-engagement B/C/F):
 * the cadence preferences, the one-click unsubscribe endpoint and the idea
 * follow routes.
 *
 * Route-level AuthZ: preferences and follow are user-scoped; the unsubscribe
 * POST is anonymous by design (the signed token is the capability) and is
 * rate-limited per IP, like the weekly digest's unsubscribe. CSRF applies to
 * the preferences and follow routes; the unsubscribe POST is exempt by route
 * name (CsrfMiddleware::SESSIONLESS_MACHINE_ROUTES), see the route below.
 */
final class ParticipantMailRoutes
{
    /** @param App<null> $app */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
    ): void {
        $mail = new ParticipantMailRepository($conn);

        $preferences = new ParticipantMailPreferencesAction($mail, $audit);
        $app->get('/account/participant-mail-preferences', $preferences->get(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->put('/account/participant-mail-preferences', $preferences->put(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // Shares the weekly digest's per-IP unsubscribe budget. Named so the
        // CSRF middleware can exempt it: the RFC 8058 one-click POST comes from
        // the recipient's mail client, which carries no session cookie.
        $unsubscribeRateLimit = $config->rateLimit('digest:unsubscribe');
        $unsubscribe          = new ParticipantUnsubscribeAction($mail, new ParticipantUnsubscribeToken($config->appKey), $audit);
        $app->post('/participant-mail/unsubscribe', $unsubscribe)
            ->setName('core.participant_mail.unsubscribe')
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'digest:unsubscribe',
                $unsubscribeRateLimit['limit'],
                $unsubscribeRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            ));

        $follow = new IdeaFollowAction(
            new BoardRepository($conn),
            new IdeaRepository($conn),
            new IdeaFollowRepository($conn),
            new AccountMemberRepository($conn),
        );
        $followPath = $accountPrefix . '/{board}/ideas/{id:[0-9]+}/follow';
        // Writes are rate-limited per user like idea:vote (added before AuthZ so
        // an anonymous request is answered 401 first, never counted).
        $followRateLimit = $config->rateLimit('idea:follow');
        $followWriteLimit = RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'idea:follow',
            $followRateLimit['limit'],
            $followRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);

                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        );
        $app->get($followPath, $follow->status(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->post($followPath, $follow->follow(...))
            ->add($followWriteLimit)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->delete($followPath, $follow->unfollow(...))
            ->add($followWriteLimit)
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
    }
}
