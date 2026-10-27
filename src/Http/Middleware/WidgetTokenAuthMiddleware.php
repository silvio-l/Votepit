<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Votepit\Http\Support\WidgetGate;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\WidgetTokenService;

/**
 * AuthN for the embeddable widget's write/personalised routes
 * (`…/{board}/widget/ideas…`, ADR 0005 §5). Sits OUTERMOST on those routes;
 * an AuthZMiddleware::user() (plus a route-level BlockCheck) runs after it.
 *
 * Deny by default, no session fallback — EVER:
 *  - requires `Authorization: Bearer <widget token>` (WidgetTokenService),
 *    missing/invalid/expired → 401;
 *  - the token's account and board must be exactly the account resolved by
 *    AccountContextMiddleware and the board of the `{board}` path segment
 *    (resolved through the public board chokepoint) — a token of another
 *    board or tenant is rejected with 401 (cross-tenant guard);
 *  - the widget must still be live (WidgetGate: plan + origins) → else 404,
 *    and the token's secret fingerprint must match the board's current
 *    widget secret (rotation revokes) → else 401;
 *  - the token user replaces whatever AuthNMiddleware hydrated from a
 *    cookie (the session attributes are cleared), and is stripped of every
 *    platform privilege flag (is_admin/is_operator/is_support): a
 *    customer-asserted identity must never act with installation-wide
 *    rights.
 *
 * Responses are marked cookie-less (SecurityHeaderMiddleware strips any
 * Set-Cookie, e.g. VoteAction's anonymous-voter cookie): the iframe runs
 * without cookies by design.
 */
final readonly class WidgetTokenAuthMiddleware implements MiddlewareInterface
{
    /** Request attribute: the verified widget token claims. */
    public const ATTR_WIDGET_TOKEN = 'widget_token';

    public function __construct(
        private WidgetTokenService $tokens,
        private BoardRepository $boards,
        private WidgetGate $gate,
        private UserRepository $users,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        $claims = str_starts_with($header, 'Bearer ')
            ? $this->tokens->verify(substr($header, 7), time())
            : null;
        if ($claims === null) {
            return $this->error(401, 'widget_token_invalid', 'A valid widget token is required.');
        }

        $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = RouteContext::fromRequest($request)->getRoute()?->getArgument('board');
        if (!is_int($accountId) || $accountId !== $claims['account_id'] || !is_string($slug)) {
            return $this->error(401, 'widget_token_invalid', 'A valid widget token is required.');
        }

        $board = $this->boards->findPublicBySlugForAccount($slug, $accountId);
        if (!is_array($board) || (int) $board['id'] !== $claims['board_id']) {
            return $this->error(401, 'widget_token_invalid', 'A valid widget token is required.');
        }

        $config = $this->gate->activeConfig($accountId, $claims['board_id']);
        if ($config === null) {
            return $this->error(404, 'not_found', 'Board not found.');
        }

        $secret = $config['secret_encrypted'];
        if ($secret === null || !hash_equals(WidgetTokenService::secretFingerprint($secret, $config['secret_rotated_at']), $claims['sv'])) {
            return $this->error(401, 'widget_token_invalid', 'A valid widget token is required.');
        }

        $user = $this->users->findById($claims['user_id']);
        if (!is_array($user)) {
            return $this->error(401, 'widget_token_invalid', 'A valid widget token is required.');
        }
        // Global kill switch on every method (BlockCheckMiddleware only
        // covers mutating requests): a blocked user keeps no widget session.
        if ((bool) ($user['is_blocked'] ?? false)) {
            return $this->error(403, 'blocked', 'Account blocked.');
        }
        $user['is_admin']    = 0;
        $user['is_operator'] = 0;
        $user['is_support']  = 0;

        $request = $request
            ->withAttribute(AuthNMiddleware::ATTR_USER, $user)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, null)
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, null)
            ->withAttribute(self::ATTR_WIDGET_TOKEN, $claims);

        return $handler->handle($request)
            ->withoutHeader('Set-Cookie')
            ->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
    }

    private function error(int $status, string $key, string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode(['error' => ['key' => $key, 'message' => $message]]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
    }
}
