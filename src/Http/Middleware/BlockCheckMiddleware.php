<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Persistence\BlockRepository;

/**
 * BlockCheck: blocked users may NOT trigger any mutation.
 *
 * Applies only to mutating verbs (POST/PUT/PATCH/DELETE). Reads (GET/HEAD/OPTIONS)
 * remain allowed. This is the central lockout from security.md A01.
 *
 * Two mechanisms that work independently of each other:
 *  1. The global, installation-wide kill switch (`users.is_blocked`) — blocks
 *     a user on ALL accounts/boards.
 *  2. The targeted, account-wide block (`blocked_users` with board_id IS
 *     NULL) — blocks a user only in the currently resolved account
 *     (ATTR_ACCOUNT_ID). $blocks is optional (null in the DB-less smoke
 *     test), in which case only mechanism 1 applies.
 *
 * Without a hydrated user, ATTR_USER is null → BlockCheck is effectively a
 * noop. The logic takes effect once user hydration is in place.
 */
final readonly class BlockCheckMiddleware implements MiddlewareInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private ?BlockRepository $blocks = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        if (in_array($method, self::MUTATING, true) && is_array($user)) {
            if ((bool) ($user['is_blocked'] ?? false)) {
                return $this->blockedResponse();
            }

            $accountId = $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
            $userId    = (int) ($user['id'] ?? 0);

            if (
                $this->blocks instanceof BlockRepository
                && is_int($accountId)
                && $userId > 0
                && $this->blocks->isBlocked($accountId, $userId, null)
            ) {
                return $this->blockedResponse();
            }
        }

        return $handler->handle($request);
    }

    private function blockedResponse(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write('Account blocked.');
        return $response;
    }
}
