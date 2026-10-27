<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Pseudonym for a guest idea author (ADR 0006 §2).
 *
 * HMAC-SHA256 over the raw `vp_anon_author` cookie token, keyed by the
 * identity_server_key (never stored in the DB) and domain-separated from
 * every other hasher by the "anon-idea-author:" prefix. The account id is
 * part of the MAC input, so the same device yields unrelated hashes in
 * different accounts — ideas.anon_author_token_hash cannot be joined across
 * tenants, nor with votes.voter_token_hash (AnonymousVoterHasher).
 */
final readonly class AnonymousAuthorHasher
{
    public function __construct(private string $serverKey) {}

    /** 64 hex characters. */
    public function hashToken(int $accountId, string $rawToken): string
    {
        return hash_hmac('sha256', 'anon-idea-author:' . $accountId . ':' . $rawToken, $this->serverKey);
    }
}
