<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use Votepit\Persistence\AccountRepository;

/**
 * AccountContext: stores the account context (ATTR_ACCOUNT_ID) as a request
 * attribute — the single contract from which board lookups (BoardRepository::findBySlugForAccount())
 * and AuthZMiddleware::accountAdmin() obtain the account context.
 *
 * Mode (Config::routingMode, cloud path routing):
 * - 'self-host' (default): ALWAYS resolves to the default account — self-host
 *   runs exactly one account (ADR 0001 §2a). No route carries an
 *   {account} segment in this mode, so it's never looked for.
 * - 'cloud': resolves the account from the {account} path segment (AppFactory
 *   registers account-/board-scoped routes there with a leading
 *   `/{account}`, see there). Unknown slug → 404 (fail-secure: NEVER
 *   silently fall back to the default account — that would be a
 *   cross-tenant leak). Reserved words (SlugValidator) can never match
 *   as {account}, because the associated system routes (login, admin/smtp,
 *   api/v1/*, …) are structurally registered WITHOUT an {account} prefix and,
 *   being fully static FastRoute routes, match ahead of any variable route
 *   anyway — the reserved-word check itself is load-bearing at
 *   account CREATION (prevents an account from ever claiming one of these
 *   slugs), not at routing.
 *
 * Must run BEFORE any route that does board lookups (including the login flow) —
 * therefore hooked into the pipeline globally, analogous to AuthNMiddleware/SessionMiddleware.
 * Requires Slim's RoutingMiddleware to run BEFORE it in the pipeline (AppFactory
 * calls $app->addRoutingMiddleware() placed accordingly), otherwise the
 * {account} route argument isn't resolved yet.
 */
final readonly class AccountContextMiddleware implements MiddlewareInterface
{
    public const ATTR_ACCOUNT_ID = 'account_id';

    public const MODE_SELF_HOST = 'self-host';
    public const MODE_CLOUD     = 'cloud';

    public function __construct(
        private AccountRepository $accounts,
        private string $routingMode = self::MODE_SELF_HOST,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(self::ATTR_ACCOUNT_ID) === null) {
            $accountId = $this->routingMode === self::MODE_CLOUD
                ? $this->resolveFromAccountSegment($request)
                : $this->accounts->defaultAccountId();

            $request = $request->withAttribute(self::ATTR_ACCOUNT_ID, $accountId);
        }

        return $handler->handle($request);
    }

    /**
     * Cloud mode: resolves {account} from the route already resolved by Slim's
     * RoutingMiddleware. If the route carries no {account} argument (e.g.
     * the global system routes /login, /api/v1/*, /admin/smtp), this
     * falls back to the default account — consistent with self-host semantics
     * for routes that deliberately carry no account reference in their path.
     */
    private function resolveFromAccountSegment(ServerRequestInterface $request): int
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $slug  = $route?->getArgument('account');

        if ($slug === null) {
            return $this->accounts->defaultAccountId();
        }

        $account = $this->accounts->findBySlug($slug);
        if ($account === null) {
            // Fail-secure: unknown account slug → 404, NEVER silently
            // fall back to the default account (a cross-tenant leak would be the
            // worst failure mode here, ADR 0001 §5b).
            throw new HttpNotFoundException($request);
        }

        return (int) $account['id'];
    }
}
