<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\CsrfService;

/**
 * CSRF protection via synchronizer token (ADR-6, amendment — see CsrfService).
 *
 * Safe verbs (GET/HEAD/OPTIONS) generate a token when needed and expose it
 * as the request attribute ATTR_TOKEN (Twig mirrors it into the form).
 * Mutating verbs (POST/PUT/PATCH/DELETE) MUST match the token from the
 * signed cookie against the form field (_csrf) in constant time — otherwise
 * 403 with no side effect (fail-secure, arch.md §1).
 *
 * Exception (Agent API): an `Authorization: Bearer <token>` header makes the
 * CSRF check moot — CSRF exploits ambient authority (the browser cookie is
 * sent automatically), whereas a bearer token is a capability the client
 * explicitly attaches, which no browser ever sends on its own. Same
 * reasoning as the one-time token in /login/verify (class doc there: "the
 * token itself is the capability"). ApiTokenAuthMiddleware verifies the
 * token itself and rejects it with 401 if invalid — this exception therefore
 * opens no gap, it only shifts the check to an already-present, stricter
 * gate.
 *
 * Second exception class: header-authenticated machine endpoints that an
 * extension registers (AppExtension::csrfExemptions() — e.g. a payment
 * provider's signed webhook). Identical reasoning: such a POST naturally
 * carries neither the session cookie nor a CSRF form field; the extension
 * verifies the header (typically an HMAC over the raw body) itself in its
 * own route middleware and rejects it before the route is ever reached. The
 * exception applies only to exactly the configured path AND only when the
 * named header is actually set. As with the bearer token, this is not a
 * cross-origin leak: an arbitrary cross-origin client cannot send such a
 * custom header "ambiently" (no browser attaches it on its own), and even a
 * header set via JS would fail at the preflight without server-side CORS
 * allowance. Without an extension the list is empty — the Community Edition
 * only knows the bearer exception.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const ATTR_TOKEN = 'csrf_token';

    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /** Bearer exemption applies only below this prefix (ApiTokenAuthMiddleware gate). */
    private const BEARER_EXEMPT_PREFIX = '/api/v1/';

    /**
     * @param array<string, string> $headerExemptions Exact request path => header name
     *        whose presence exempts a mutating request on that path (an
     *        extension-owned middleware on that route verifies the header).
     */
    public function __construct(
        private CsrfService $csrf,
        private ResponseFactoryInterface $responseFactory,
        private array $headerExemptions = [],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[$this->csrf->cookieName()] ?? null;
        $token  = $this->csrf->read(is_string($cookie) ? $cookie : null);
        $isNew  = $token === null;
        if ($isNew) {
            $token = $this->csrf->generate();
        }

        // Security review (defense in depth): header-based exemptions are
        // only sound where the stricter gate they defer to actually runs
        // (ApiTokenAuthMiddleware on /api/v1/*, the extension's own
        // middleware on an exempted path). Scope them to exactly those paths
        // so a session-cookie request to any other mutating route cannot opt
        // out of the CSRF check merely by attaching a bogus header.
        $path          = $request->getUri()->getPath();
        $isBearerAuth  = str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')
            && str_starts_with($path, self::BEARER_EXEMPT_PREFIX);
        $exemptHeader  = $this->headerExemptions[$path] ?? null;
        $isHeaderAuth  = $exemptHeader !== null && $request->getHeaderLine($exemptHeader) !== '';
        $isExemptAuth  = $isBearerAuth || $isHeaderAuth;

        if (!$isExemptAuth && !in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            $parsed    = $request->getParsedBody();
            $submitted = is_array($parsed) ? ($parsed[$this->csrf->fieldName()] ?? null) : null;

            // SPA fallback: also accept the token as an X-CSRF-Token header.
            if (!is_string($submitted)) {
                $header    = $request->getHeaderLine('X-CSRF-Token');
                $submitted = $header !== '' ? $header : null;
            }

            // No valid prior token (cookie missing/tampered) or field/header mismatch → reject.
            if ($isNew || !is_string($submitted) || !hash_equals($token, $submitted)) {
                $response = $this->responseFactory->createResponse(403);
                $response->getBody()->write('CSRF token invalid.');
                return $response;
            }
        }

        $response = $handler->handle($request->withAttribute(self::ATTR_TOKEN, $token));

        return $isNew ? $this->csrf->issue($response, $token) : $response;
    }
}
