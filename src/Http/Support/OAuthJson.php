<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * JSON responses of the MCP OAuth server's machine endpoints (token,
 * revocation, registration): the RFC 6749 §5.2 error shape
 * `{"error", "error_description"}` and `Cache-Control: no-store` on every
 * response (§5.1 — they may carry credentials).
 */
final class OAuthJson
{
    /** @param array<string, mixed> $payload */
    public static function respond(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    public static function error(ResponseInterface $response, int $status, string $error, string $description): ResponseInterface
    {
        return self::respond($response, $status, ['error' => $error, 'error_description' => $description]);
    }
}
