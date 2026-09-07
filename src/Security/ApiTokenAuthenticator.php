<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ServerRequestInterface;
use Votepit\Persistence\ApiTokenRepository;

/**
 * Bearer token authentication for the Agent API (Agent API / Votepit MCP).
 * A NEW trust boundary alongside the session-cookie path:
 * a valid, non-revoked row from api_tokens replaces "logged-in
 * user + account role" with "bearer token that authorizes a set of boards
 * within one account, at a 'read' or 'write' scope" (see migration 0044).
 *
 * Crypto identical to TokenVault/LoginTokenRepository/InviteRepository (SHA-256
 * hash, constant-time comparison via the unique index in the DB, plaintext
 * NEVER stored/logged) — this service creates no token type of its own,
 * it only wires TokenVault to ApiTokenRepository.
 *
 * Pure domain/security logic, no PSR-15 — ApiTokenAuthMiddleware is the
 * thin HTTP wrapper around it (reads the header, calls resolve(), translates
 * null into 401 and attaches the result as a request attribute). A
 * subsequent MCP resource wrapper can reuse resolve() directly,
 * without going through the HTTP layer.
 */
final readonly class ApiTokenAuthenticator
{
    private const HEADER_PREFIX = 'Bearer ';

    public function __construct(
        private ApiTokenRepository $tokens,
        private TokenVault $vault,
    ) {}

    /**
     * Generates a new token pair (plaintext for the admin UI, hash for the DB).
     * Pure delegation to TokenVault — no second crypto scheme.
     *
     * @return array{token: string, hash: string}
     */
    public function generate(): array
    {
        return $this->vault->generate();
    }

    /**
     * Reads the Authorization header, verifies the bearer token, and returns
     * the resolved grant (account_id, board_scopes, token_id,
     * created_by_user_id, label) — or null on a missing/malformed header, or
     * an unknown or revoked token. board_scopes maps each granted board_id to
     * its OWN 'read'|'write' scope (migration 0047) — there is no single
     * token-wide scope anymore.
     *
     * Fail-secure: every doubtful case (no header, wrong scheme, lookup
     * error) ends in null, NEVER in an implicit "let through".
     *
     * @return array{token_id: int, account_id: int, board_scopes: array<int, string>, created_by_user_id: int, label: string}|null
     */
    public function resolve(ServerRequestInterface $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, self::HEADER_PREFIX)) {
            return null;
        }

        $candidate = trim(substr($header, strlen(self::HEADER_PREFIX)));
        if ($candidate === '') {
            return null;
        }

        $hash = $this->vault->hash($candidate);

        try {
            $row = $this->tokens->findByHash($hash);
        } catch (\Doctrine\DBAL\Exception) {
            return null; // fail-secure: lookup error → never authenticate
        }

        if ($row === null) {
            return null;
        }

        return [
            'token_id'           => $row['id'],
            'account_id'         => $row['account_id'],
            'board_scopes'       => $row['board_scopes'],
            'created_by_user_id' => $row['created_by_user_id'],
            'label'              => $row['label'],
        ];
    }
}
