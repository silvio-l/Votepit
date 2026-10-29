<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared JSON error-response helper (OWASP/BSI audit remediation issue 19:
 * response consistency) — before this, several actions wrote plain-text
 * error bodies with no Content-Type header on error paths (e.g. VoteAction,
 * IdeaWithdrawAction), while sibling actions (IdeaCreateAction, IdeaEditAction)
 * already returned the correct `{"error": {"key", "message"}}` JSON shape
 * with `Content-Type: application/json`. This centralizes that established
 * shape, mirroring the FrozenBoardGuard::reject() pattern for a single
 * specific error case.
 */
final class JsonError
{
    /** @param array<string, mixed> $extra additional fields merged into the `error` object (e.g. `site_key`) */
    public static function respond(ResponseInterface $response, int $status, string $key, string $message, array $extra = []): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message, ...$extra],
        ]));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
