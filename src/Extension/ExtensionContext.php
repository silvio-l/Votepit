<?php

declare(strict_types=1);

namespace Votepit\Extension;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\App;
use Votepit\Config;
use Votepit\Domain\PlanPolicy;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\LoginSessionIssuer;

/**
 * Everything AppFactory hands to AppExtension::register() and
 * AppExtension::routeMiddleware().
 *
 * Extensions add routes to $app exactly like core does: with
 * AuthZMiddleware::accountOwner()/accountAdmin() for session-authenticated
 * admin routes, RateLimitMiddleware::perAction() for per-action buckets
 * (Config::rateLimit() reads the bucket from config), and $accountPrefix in
 * front of every account-scoped path ('' in self-host mode, '/{account}' in
 * cloud mode — AccountContextMiddleware resolves the segment).
 *
 * $sessionIssuer is the ONE sanctioned way for an extension to sign a
 * visitor in: it is the same code path core's magic-link, password and 2FA
 * logins end in, so an extension-issued session carries the same cookie
 * attributes, token_version binding and cloud redirect rules as any other.
 *
 * The global PSR-15 pipeline (session, AuthN, CSRF, security headers, IP
 * rate limit) is already installed on $app and applies to extension routes
 * unchanged — an extension cannot bypass it.
 */
final readonly class ExtensionContext
{
    /** @param App<null> $app */
    public function __construct(
        public App $app,
        public Config $config,
        public Connection $conn,
        public ResponseFactoryInterface $responseFactory,
        public AuditLogger $audit,
        public PlanPolicy $planPolicy,
        public string $accountPrefix,
        public AccountRepository $accounts,
        public AccountMemberRepository $accountMembers,
        public BoardRepository $boards,
        public ApiTokenRepository $apiTokens,
        public UserRepository $users,
        public LoginSessionIssuer $sessionIssuer,
    ) {}
}
