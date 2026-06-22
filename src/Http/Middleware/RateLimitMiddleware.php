<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Security\RateLimiter;

/**
 * Rate-Limiting als PSR-15-Middleware (arch.md §3, security.md §6).
 *
 * Zwei Ausprägungen über Static-Konstruktoren — analog zu AuthZMiddleware, das
 * ebenfalls per-Route gesetzt wird:
 *   - perIp()     : grob, global pro Client-IP (Bucket "ip:<addr>").
 *   - perAction() : fein, pro Aktion+Identität (z. B. "magiclink:email:<mail>"),
 *                   von der jeweiligen mutierenden Route in Sprint 2+ gesetzt.
 *
 * Fail-open: Wirft der Limiter (DB nicht erreichbar), wird der Request
 * DURCHGELASSEN. Das ist eine bewusste, eng begrenzte Ausnahme von der pauschalen
 * Fail-secure-Regel: ein Rate-Limiter schützt Verfügbarkeit, kein Integritäts-
 * Gate — ihn fail-closed zu fahren würde einen DB-Schluckauf in einen
 * Totalausfall der (Lese-)Pfade verwandeln. Auth/CSRF bleiben strikt fail-secure.
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
        private \Closure $identity
    ) {}

    /** Grob, pro Client-IP (global in der Pipeline). */
    public static function perIp(
        RateLimiter $limiter,
        ResponseFactoryInterface $rf,
        int $limit,
        int $window,
    ): self {
        return new self($limiter, $rf, 'ip', $limit, $window, static fn (ServerRequestInterface $r): ?string => self::clientIp($r));
    }

    /**
     * Fein, pro Aktion + aufrufer-spezifischer Identität. Die Route liefert die
     * Identitäts-Auflösung (z. B. E-Mail aus dem Body, User-ID aus dem Attribut).
     *
     * @param \Closure(ServerRequestInterface):?string $identity
     */
    public static function perAction(
        RateLimiter $limiter,
        ResponseFactoryInterface $rf,
        string $action,
        int $limit,
        int $window,
        \Closure $identity,
    ): self {
        return new self($limiter, $rf, $action, $limit, $window, $identity);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = ($this->identity)($request);

        // Keine auflösbare Identität (z. B. fehlende IP) → nicht limitieren.
        if ($identity === null || $identity === '') {
            return $handler->handle($request);
        }

        try {
            $allowed = $this->limiter->hit($this->bucketPrefix . ':' . $identity, $this->limit, $this->window);
        } catch (DbalException) {
            $allowed = true; // fail-open, siehe Klassen-Doc
        }

        if (!$allowed) {
            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write('Rate limit exceeded.');
            return $response->withHeader('Retry-After', (string) $this->window);
        }

        return $handler->handle($request);
    }

    private static function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $ip     = $params['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
