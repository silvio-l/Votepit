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
 * CSRF-Schutz via Synchronizer-Token (ADR-6, Amendment — siehe CsrfService).
 *
 * Sichere Verben (GET/HEAD/OPTIONS) erzeugen bei Bedarf einen Token und stellen
 * ihn als Request-Attribut ATTR_TOKEN bereit (Twig spiegelt ihn ins Formular).
 * Mutierende Verben (POST/PUT/PATCH/DELETE) MÜSSEN den Token aus dem
 * signierten Cookie und dem Formularfeld (_csrf) konstant-zeitig matchen — sonst
 * 403 ohne Side-Effect (fail-secure, arch.md §1).
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const ATTR_TOKEN = 'csrf_token';

    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private CsrfService $csrf,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[$this->csrf->cookieName()] ?? null;
        $token  = $this->csrf->read(is_string($cookie) ? $cookie : null);
        $isNew  = $token === null;
        if ($isNew) {
            $token = $this->csrf->generate();
        }

        if (!in_array(strtoupper($request->getMethod()), self::SAFE, true)) {
            $parsed    = $request->getParsedBody();
            $submitted = is_array($parsed) ? ($parsed[$this->csrf->fieldName()] ?? null) : null;

            // Kein gültiger Vor-Token (Cookie fehlte/manipuliert) oder Feld-Mismatch → ablehnen.
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
