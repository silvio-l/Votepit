<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * View-count deduplication without cookies: HMAC-SHA256 over
 * idea+IP+User-Agent, keyed by the same server-side secret as IdentityHasher
 * (identity_server_key — never stored in the DB, independently rotatable
 * from app_key). "idea-view:" prefixes the HMAC input for domain separation
 * from IdentityHasher's email hashes, even though both currently share a key.
 *
 * Used by IdeaViewTracker as an opaque bucket suffix in the existing
 * rate_limits table — no plaintext IP/User-Agent is ever persisted, and no
 * new table/cleanup cron is needed (bin/cleanup-rate-limits.php already
 * prunes expired buckets).
 */
final readonly class ViewDedupHasher
{
    public function __construct(private string $serverKey) {}

    public function hash(int $ideaId, ?string $ip, string $userAgent): string
    {
        return hash_hmac('sha256', 'idea-view:' . $ideaId . '|' . ($ip ?? '') . '|' . $userAgent, $this->serverKey);
    }
}
