<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ServerRequestInterface;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Security\OAuthServer\OAuthTokens;
use Votepit\Support\Clock;
use Votepit\Support\SystemClock;

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
 *
 * Two kinds of bearer credential resolve to the SAME grant shape: a static
 * API token (api_tokens.token_hash) and an MCP OAuth access token (prefix
 * OAuthTokens::ACCESS_PREFIX, oauth_tokens — its grant is an api_tokens row
 * with oauth_client_id, migration 0088). Everything downstream therefore
 * authorizes both identically — but OAuth access tokens only on the MCP
 * resource they were issued for (see resolve()).
 */
final readonly class ApiTokenAuthenticator
{
    private const HEADER_PREFIX = 'Bearer ';

    private Clock $clock;

    public function __construct(
        private ApiTokenRepository $tokens,
        private TokenVault $vault,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

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
     * its OWN 'read'|'write'|'moderate' scope (migration 0047) — there is no single
     * token-wide scope anymore.
     *
     * Fail-secure: every doubtful case (no header, wrong scheme, lookup
     * error) ends in null, NEVER in an implicit "let through".
     *
     * OAuth access tokens are audience-bound to the MCP resource (RFC 8707 /
     * MCP authorization spec: the protected resource metadata names
     * /api/v1/mcp only), so they resolve only when the caller opts in via
     * $acceptOAuthAccessTokens — everywhere else they are unknown tokens.
     *
     * @return array{token_id: int, account_id: int, board_scopes: array<int, string>, created_by_user_id: int, label: string}|null
     */
    public function resolve(ServerRequestInterface $request, bool $acceptOAuthAccessTokens = false): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, self::HEADER_PREFIX)) {
            return null;
        }

        $candidate = trim(substr($header, strlen(self::HEADER_PREFIX)));
        if ($candidate === '') {
            return null;
        }

        $isOAuthAccessToken = str_starts_with($candidate, OAuthTokens::ACCESS_PREFIX);
        if ($isOAuthAccessToken && !$acceptOAuthAccessTokens) {
            return null;
        }

        $hash = $this->vault->hash($candidate);

        try {
            $row = $isOAuthAccessToken
                ? $this->tokens->findByOAuthAccessTokenHash($hash, $this->clock->now())
                : $this->tokens->findByHash($hash);
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
