<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * IP pseudonymization for security-critical audit-log entries (OWASP/BSI
 * audit remediation issue 11) — HMAC-SHA256 keyed by the same server-side
 * secret as IdentityHasher/ViewDedupHasher (identity_server_key, never
 * stored in the DB, independently rotatable from app_key). "audit-ip:"
 * prefixes the HMAC input for domain separation from the other hashers that
 * currently share the same key.
 *
 * An IP must never reach AuditLogger in plaintext — call sites hash it here
 * first and pass the result under an `ip_hmac` context key (the `_hmac`
 * suffix AuditLogger::mask() already treats as pre-pseudonymized).
 */
final readonly class AuditIpHasher
{
    public function __construct(private string $serverKey) {}

    public function hash(string $ip): string
    {
        return hash_hmac('sha256', 'audit-ip:' . $ip, $this->serverKey);
    }
}
