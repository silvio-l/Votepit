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
use Votepit\Http\Action\OAuthConsentAction;
use Votepit\Http\Action\OAuthServerAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\OAuthAuthorizationCodeRepository;
use Votepit\Persistence\OAuthClientRepository;
use Votepit\Persistence\OAuthTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\OAuthServer\AuthorizationRequestValidator;
use Votepit\Security\OAuthServer\OAuthGrantEligibility;
use Votepit\Security\OAuthServer\OAuthServerMetadata;
use Votepit\Security\OAuthServer\OAuthTokenService;
use Votepit\Security\RateLimiter;
use Votepit\Security\TokenVault;
use Votepit\Support\Clock;

/**
 * MCP OAuth 2.1 authorization server (issue 21) — lets an MCP client
 * (Claude, ChatGPT, …) obtain an Agent API grant through a browser consent
 * instead of a copied static token. All routes are unprefixed (group 1 in
 * AppFactory, registered before the first {account} route — FastRoute).
 *
 * Anonymous machine endpoints (AuthZ anon, per-IP rate limits; the three
 * routes are CSRF-exempt by route name and get no cookie, see
 * CsrfMiddleware::SESSIONLESS_MACHINE_ROUTES):
 *   GET  /.well-known/oauth-authorization-server              RFC 8414
 *   GET  /.well-known/oauth-protected-resource                RFC 9728
 *   GET  /.well-known/oauth-protected-resource/api/v1/mcp     RFC 9728 (path-suffixed form)
 *   POST /oauth/register   RFC 7591 (+ global registration cap)
 *   POST /oauth/token
 *   POST /oauth/revoke     RFC 7009
 *
 * Session endpoints behind the SPA consent page /oauth/authorize (AuthZ
 * user; the decision POST is CSRF-checked like every session POST and
 * rate-limited per user):
 *   GET  /api/oauth/authorize
 *   POST /api/oauth/authorize
 */
final class OAuthServerRoutes
{
    /** @param App<null> $app */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        TokenVault $vault,
        Clock $clock,
        ApiTokenRepository $apiTokenRepo,
        AccountRepository $accountRepo,
        AccountMemberRepository $accountMemberRepo,
        UserRepository $userRepo,
        BoardRepository $boardRepo,
        PlanPolicy $planPolicy,
    ): void {
        $metadata    = new OAuthServerMetadata($config->appUrl);
        $clients     = new OAuthClientRepository($conn);
        $codes       = new OAuthAuthorizationCodeRepository($conn);
        $accountPlans = new AccountPlanResolver($accountRepo, $accountMemberRepo, $planPolicy);
        $eligibility = new OAuthGrantEligibility($accountRepo, $accountPlans, $accountMemberRepo, $userRepo, $planPolicy);
        $tokenService = new OAuthTokenService(
            $conn,
            $apiTokenRepo,
            $codes,
            new OAuthTokenRepository($conn),
            $eligibility,
            $vault,
            $clock,
            $audit,
        );

        $server  = new OAuthServerAction($metadata, $clients, $tokenService, $clock, $audit);
        $consent = new OAuthConsentAction(
            new AuthorizationRequestValidator($clients, $metadata),
            $eligibility,
            $accountRepo,
            $boardRepo,
            $codes,
            $vault,
            $clock,
            $audit,
        );

        $perIp = static function (string $action) use ($conn, $responseFactory, $config): RateLimitMiddleware {
            $limit = $config->rateLimit($action);

            return RateLimitMiddleware::perIp(new RateLimiter($conn), $responseFactory, $action, $limit['limit'], $limit['window'], $config->trustCloudflareIp);
        };

        $app->get('/.well-known/oauth-authorization-server', $server->authorizationServerMetadata(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->setName('core.oauth_server.metadata');
        $app->get('/.well-known/oauth-protected-resource', $server->protectedResourceMetadata(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->setName('core.oauth_server.resource_metadata');
        $app->get('/.well-known/oauth-protected-resource/api/v1/mcp', $server->protectedResourceMetadata(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->setName('core.oauth_server.resource_metadata_mcp');

        // Registration: per-IP AND a global cap — registrations are anonymous
        // by protocol, the global bucket bounds table growth even under a
        // distributed flood.
        $registerGlobal = $config->rateLimit('oauth:register:global');
        $app->post('/oauth/register', $server->register(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'oauth:register:global',
                $registerGlobal['limit'],
                $registerGlobal['window'],
                static fn (ServerRequestInterface $r): string => 'all',
            ))
            ->add($perIp('oauth:register'))
            ->setName('core.oauth_server.register');

        $app->post('/oauth/token', $server->token(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add($perIp('oauth:token'))
            ->setName('core.oauth_server.token');

        $app->post('/oauth/revoke', $server->revoke(...))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add($perIp('oauth:revoke'))
            ->setName('core.oauth_server.revoke');

        $app->get('/api/oauth/authorize', $consent->context(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $consentLimit = $config->rateLimit('oauth:consent');
        $app->post('/api/oauth/authorize', $consent->decide(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'oauth:consent',
                $consentLimit['limit'],
                $consentLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);

                    return is_array($user) && isset($user['id']) ? 'user:' . (int) $user['id'] : null;
                },
            ))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
    }
}
