<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Email pseudonymization (ADR 0002 at the repo root — "email only as
 * HMAC-SHA256(email, serverKey)").
 *
 * Email addresses are a low-entropy space: a plain hash (even salted)
 * in the same DB could be brute-forced against known address lists.
 * The HMAC key ("serverKey", `identity_server_key` in config) lives
 * outside the DB and separate from app_key (independent rotation) — a plain
 * DB leak therefore only yields unlinkable hashes, no plaintext mapping.
 *
 * normalize() unifies email spellings (trim, lowercase, NFC
 * normal form via ext-intl) BEFORE hashing, so the same logical address in
 * different spellings (upper/lower case, composition
 * variants) produces the same HMAC.
 */
final readonly class IdentityHasher
{
    public function __construct(private string $serverKey) {}

    /** Unifies an email address BEFORE hashing (trim, lowercase, NFC). */
    public function normalize(string $email): string
    {
        $trimmed = trim($email);
        $lower   = mb_strtolower($trimmed, 'UTF-8');
        $normalized = \Normalizer::normalize($lower, \Normalizer::FORM_C);

        return $normalized === false ? $lower : $normalized;
    }

    /** HMAC-SHA256(normalize($email), serverKey) — 64 hex characters (fits CHAR(64)). */
    public function hash(string $email): string
    {
        return hash_hmac('sha256', $this->normalize($email), $this->serverKey);
    }
}
