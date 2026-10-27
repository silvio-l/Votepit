<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Pseudonymization for anonymous ("no account") voting (ADR 0004).
 *
 * hashToken(): the anonymous voter's cookie token -> votes.voter_token_hash.
 * HMAC-SHA256, domain-separated ("anon-vote-token:" prefix) from every
 * other hasher keyed by the same server-side secret as
 * IdentityHasher/AuditIpHasher/ViewDedupHasher (identity_server_key, never
 * stored in the DB). Only the hash is ever persisted; the raw token lives
 * solely in the voter's httpOnly cookie, so a DB leak cannot forge or
 * replay a vote (the attacker would still need the raw cookie value, and
 * cannot derive one that hashes to a stolen value without the server key).
 *
 * The OPTIONAL notification email an anonymous voter may leave is NOT
 * handled here — unlike the token, it must remain recoverable to actually
 * send the promised status-change notification, so it is reversibly
 * ENCRYPTED (EncryptionService, context 'anon_vote_email') rather than
 * hashed — see migrations/0069_replace_voter_email_hash_with_encryption.sql
 * and IdeaStatusAction's fan-out.
 */
final readonly class AnonymousVoterHasher
{
    public function __construct(private string $serverKey) {}

    /** HMAC-SHA256("anon-vote-token:" . $rawToken, serverKey) — 64 hex characters. */
    public function hashToken(string $rawToken): string
    {
        return hash_hmac('sha256', 'anon-vote-token:' . $rawToken, $this->serverKey);
    }

    /** Cryptographically random, URL-safe token for the voter cookie's value. */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
