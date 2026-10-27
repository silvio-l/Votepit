<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-scoped CORS WITH credentials, for exactly the routes that opt in
 * (today: GET /api/v1/session/badge, read by a separate marketing origin to
 * show the visitor's sign-in state).
 *
 * Deliberately NOT a global CORS layer: the app is same-origin by default
 * (CLAUDE.md, shared-origin invariant) and only the listed route carries
 * this middleware. Rules:
 *   - `Access-Control-Allow-Origin` is reflected ONLY when the request's
 *     `Origin` header matches one of the configured origins EXACTLY (scheme +
 *     host + port; no wildcard, no suffix or prefix matching, no `null`).
 *     Unlisted and absent origins get neither CORS header.
 *   - `Access-Control-Allow-Credentials: true` accompanies the origin only
 *     for an allowed origin; it is never sent with `*`.
 *   - `Vary: Origin` is ensured on every response so caches key on the origin.
 *   - No preflight handling: the endpoint is GET-only and the client sends
 *     no custom request headers, so every request is a CORS "simple request".
 *     An OPTIONS request is not answered with any allow header here.
 *
 * SecurityHeaderMiddleware's `Cross-Origin-Resource-Policy: same-origin` only
 * constrains no-cors embedding and does not affect this CORS-mode fetch.
 *
 * @see \Votepit\Cloud\Marketing\CorsAllowlistMiddleware (cloud, credential-less precedent)
 */
final readonly class CredentialedCorsAllowlistMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins exact origins, e.g. ['https://votepit.com'] (empty = CORS off) */
    public function __construct(private array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->hasOriginVary($response)) {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || !in_array($origin, $this->allowedOrigins, true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    private function hasOriginVary(ResponseInterface $response): bool
    {
        foreach (explode(',', $response->getHeaderLine('Vary')) as $token) {
            if (strcasecmp(trim($token), 'Origin') === 0) {
                return true;
            }
        }

        return false;
    }
}
