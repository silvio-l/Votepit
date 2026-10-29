<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use Votepit\Logging\AuditLogger;
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
     * Embeddable widget routes (ADR 0005) are identified by route NAME, not
     * by path: their `…/{board}/widget/…` path shape could also be produced
     * by an ordinary board route (a board slug "widget"), a route name
     * cannot. They run cookie-less inside a third-party iframe: a Bearer
     * header (identify JWT or widget token, verified by
     * WidgetIdentifyAction / WidgetTokenAuthMiddleware, which never fall
     * back to the session) exempts them like /api/v1/*, and no CSRF cookie
     * is ever issued on them.
     */
    public const WIDGET_ROUTE_NAME_PREFIX = 'core.widget.';

    /**
     * Core's own signed machine webhooks (inbound Linear/GitHub status sync),
     * identified by route NAME => signature header: same reasoning as the
     * extension header exemptions — the provider's POST carries neither
     * cookie nor CSRF field, and IssueTrackerWebhookMiddleware verifies the
     * HMAC in that header on the route itself before anything runs. Exempt
     * only when the header is present; no CSRF cookie is ever issued there.
     */
    public const SIGNED_WEBHOOK_ROUTES = [
        'core.issue_tracker_webhook.linear' => 'Linear-Signature',
        'core.issue_tracker_webhook.github' => 'X-Hub-Signature-256',
    ];

    /**
     * Session-less machine endpoints of MCP OAuth (issue 21), identified by
     * route NAME: discovery metadata, client registration, token,
     * revocation — and the MCP endpoint itself. They are called by an MCP
     * client, never with ambient browser credentials: none of them reads
     * the session or a cookie (the OAuth actions take their credential —
     * code + PKCE verifier, refresh token — from the body; the MCP route is
     * gated solely by ApiTokenAuthMiddleware), so there is nothing a
     * cross-site request could ride on. Exempt unconditionally, and no CSRF
     * cookie is issued there.
     *
     * The MCP route needs this even without a Bearer header: an MCP client
     * starts OAuth discovery from the 401 + `WWW-Authenticate:
     * resource_metadata` of an unauthenticated call — a CSRF 403 there
     * would cut discovery off before it begins.
     */
    public const SESSIONLESS_MACHINE_ROUTES = [
        'core.oauth_server.metadata',
        'core.oauth_server.resource_metadata',
        'core.oauth_server.resource_metadata_mcp',
        'core.oauth_server.register',
        'core.oauth_server.token',
        'core.oauth_server.revoke',
        'core.api_v1.mcp',
        // RFC 8058 one-click unsubscribe of the participant mails: the mail
        // client's POST carries no cookie and no CSRF field. Anonymous by
        // design; the signed token in the query string is the capability and
        // can only switch mails off (ParticipantUnsubscribeAction, never reads
        // the session).
        'core.participant_mail.unsubscribe',
        // Cloud route name (cloud/src/Marketing/MarketingMailUnsubscribeAction): the same
        // one-click pattern for the marketing mails. Listed here because a mail client's
        // POST carries no header that a header exemption could match.
        'cloud.marketing_mail.unsubscribe',
    ];

    /**
     * @param array<string, string> $headerExemptions Exact request path => header name
     *        whose presence exempts a mutating request on that path (an
     *        extension-owned middleware on that route verifies the header).
     */
    public function __construct(
        private CsrfService $csrf,
        private ResponseFactoryInterface $responseFactory,
        private array $headerExemptions = [],
        private ?AuditLogger $audit = null,
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
        $route         = $request->getAttribute(RouteContext::ROUTE);
        $routeName     = $route instanceof RouteInterface ? (string) $route->getName() : '';
        $isWidgetRoute = str_starts_with($routeName, self::WIDGET_ROUTE_NAME_PREFIX);
        $webhookHeader = self::SIGNED_WEBHOOK_ROUTES[$routeName] ?? null;
        $isWebhook     = $webhookHeader !== null;
        $isBearerAuth  = str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')
            && (str_starts_with($path, self::BEARER_EXEMPT_PREFIX) || $isWidgetRoute);
        $exemptHeader  = $this->headerExemptions[$path] ?? null;
        $isHeaderAuth  = ($exemptHeader !== null && $request->getHeaderLine($exemptHeader) !== '')
            || ($webhookHeader !== null && $request->getHeaderLine($webhookHeader) !== '');
        $isSessionless = in_array($routeName, self::SESSIONLESS_MACHINE_ROUTES, true);
        $isExemptAuth  = $isBearerAuth || $isHeaderAuth || $isSessionless;

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
                $this->audit?->log('csrf.mismatch', ['path' => $path, 'method' => strtoupper($request->getMethod())]);
                $response = $this->responseFactory->createResponse(403);
                $response->getBody()->write('CSRF token invalid.');
                return $response;
            }
        }

        $response = $handler->handle($request->withAttribute(self::ATTR_TOKEN, $token));

        return $isNew && !$isWidgetRoute && !$isWebhook && !$isSessionless ? $this->csrf->issue($response, $token) : $response;
    }
}
