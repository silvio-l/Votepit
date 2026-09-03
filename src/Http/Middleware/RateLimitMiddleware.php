<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\ClientIp;
use Votepit\Security\RateLimiter;

/**
 * Rate limiting as PSR-15 middleware (arch.md §3, security.md §6).
 *
 * Two variants via static constructors — analogous to AuthZMiddleware, which
 * is also set per route:
 *   - perIp()     : coarse, global per client IP (bucket "ip:<addr>").
 *   - perAction() : fine-grained, per action+identity (e.g.
 *                   "magiclink:email:<mail>"), set by the respective
 *                   mutating route.
 *
 * Fail-open: if the limiter throws (DB unreachable), the request is LET
 * THROUGH. This is a deliberate, narrowly scoped exception to the general
 * fail-secure rule: a rate limiter protects availability, not an integrity
 * gate — running it fail-closed would turn a DB hiccup into a total outage
 * of the (read) paths. Auth/CSRF remain strictly fail-secure.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    private function __construct(
        private RateLimiter $limiter,
        private ResponseFactoryInterface $responseFactory,
        private string $bucketPrefix,
        private int $limit,
        private int $window,
        /** @var \Closure(ServerRequestInterface):?string */
        private \Closure $identity,
        /** @var (\Closure(string):void)|null */
        private ?\Closure $onLimitExceeded = null,
    ) {}

    /**
     * Coarse, per client IP (global in the pipeline or per route).
     *
     * $onLimitExceeded see perAction() — here it is called with the resolved
     * client IP as the sole argument.
     *
     * @param (\Closure(string):void)|null $onLimitExceeded
     */
    public static function perIp(
        RateLimiter $limiter,
        ResponseFactoryInterface $rf,
        int $limit,
        int $window,
        bool $trustCloudflareIp = false,
        ?\Closure $onLimitExceeded = null,
    ): self {
        return new self($limiter, $rf, 'ip', $limit, $window, static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $trustCloudflareIp), $onLimitExceeded);
    }

    /**
     * Fine-grained, per action + caller-specific identity. The route
     * supplies the identity resolution (e.g. email from the body, user ID
     * from the attribute).
     *
     * $onLimitExceeded is an optional best-effort hook that fires exactly
     * when this middleware itself responds with 429, with the resolved
     * identity (e.g. IP or email) as its argument — e.g. an audit-log
     * entry. It runs AFTER the decision and must
     * not influence its outcome; an error in it must be treated fail-open
     * for the same reason as the rate limiter itself (see below).
     *
     * @param \Closure(ServerRequestInterface):?string $identity
     * @param (\Closure(string):void)|null $onLimitExceeded
     */
    public static function perAction(
        RateLimiter $limiter,
        ResponseFactoryInterface $rf,
        string $action,
        int $limit,
        int $window,
        \Closure $identity,
        ?\Closure $onLimitExceeded = null,
    ): self {
        return new self($limiter, $rf, $action, $limit, $window, $identity, $onLimitExceeded);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = ($this->identity)($request);

        // No resolvable identity (e.g. missing IP) → do not rate limit.
        if ($identity === null || $identity === '') {
            return $handler->handle($request);
        }

        try {
            $allowed = $this->limiter->hit($this->bucketPrefix . ':' . $identity, $this->limit, $this->window);
        } catch (DbalException) {
            $allowed = true; // fail-open, see class doc
        }

        if (!$allowed) {
            if ($this->onLimitExceeded instanceof \Closure) {
                try {
                    ($this->onLimitExceeded)($identity);
                } catch (\Throwable) {
                    // Best-effort — a broken hook must not turn the
                    // already-made 429 decision into a 500.
                }
            }

            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write('Rate limit exceeded.');
            return $response->withHeader('Retry-After', (string) $this->window);
        }

        return $handler->handle($request);
    }

}
