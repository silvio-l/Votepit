<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Server-side OAuth `state` (+ optional PKCE code_verifier, + the validated
 * `returnTo` the user started from) persistence — migrations/0052_add_oauth_login.sql.
 *
 * Deliberately a DEDICATED table, not a reuse of login_tokens: the semantics
 * differ (no user_id yet — a state is minted before we know who the user
 * is), and the payload shape differs (code_verifier, return_to). Only the
 * SHA-256 hash of the plaintext state is ever stored (same TokenVault
 * crypto as login_tokens.token_hash) — the plaintext only ever goes into the
 * `state` query parameter of the redirect to the provider.
 *
 * Single-use, enforced atomically in consumeActiveByHash(): the UPDATE ...
 * WHERE used_at IS NULL is the actual guard against a replayed/concurrently
 * consumed state, not the preceding SELECT.
 */
final readonly class OAuthStateRepository
{
    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function insert(
        string $stateHash,
        string $provider,
        ?string $codeVerifier,
        ?string $returnTo,
        string $expiresAt,
    ): void {
        $this->conn->insert('oauth_states', [
            'state_hash'    => $stateHash,
            'provider'      => $provider,
            'code_verifier' => $codeVerifier,
            'return_to'     => $returnTo,
            'expires_at'    => $expiresAt,
            'used_at'       => null,
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Finds an active (unused, not expired) state for this exact provider
     * and marks it used in the SAME call — a second call with the same
     * hash (replay, including a failed first attempt) always returns null
     * from here on, even if the caller never reaches a successful login.
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function consumeActiveByHash(string $stateHash, string $provider): ?array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $row = $this->conn->fetchAssociative(
            'SELECT id, provider, code_verifier, return_to, expires_at, used_at
             FROM oauth_states
             WHERE state_hash = :state_hash AND provider = :provider AND expires_at > :now',
            ['state_hash' => $stateHash, 'provider' => $provider, 'now' => $now],
        );
        if ($row === false) {
            return null;
        }

        $affected = $this->conn->executeStatement(
            'UPDATE oauth_states SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => $now, 'id' => $row['id']],
        );
        if ($affected !== 1) {
            // Already consumed by a concurrent/replayed request.
            return null;
        }

        return $row;
    }
}
