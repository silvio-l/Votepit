<?php

declare(strict_types=1);

namespace Votepit\Security;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Persistence\IdeaRepository;

/**
 * Increments ideas.view_count on the idea detail page, at most once per
 * IP+User-Agent per 24h window — an X/Twitter-style "impressions" signal
 * without cookies or per-visitor DB records.
 *
 * Dedup reuses the existing rate_limits table via RateLimiter::count():
 * the bucket ("idea-view:<id>:<hmac>") only ever stores an HMAC-SHA256 of
 * IP+User-Agent (ViewDedupHasher), never plaintext — and rate_limits rows
 * are already pruned by the existing bin/cleanup-rate-limits.php cron, so
 * no new table or cron is needed.
 *
 * Fail-open throughout (both steps): a broken counter must never break the
 * page load itself — matches RateLimitMiddleware's own fail-open stance for
 * the same reason (availability, not an integrity gate).
 */
final readonly class IdeaViewTracker
{
    private const WINDOW_SECONDS = 86400; // 24h — a repeat visit within this window doesn't count again

    public function __construct(
        private RateLimiter $limiter,
        private IdeaRepository $ideaRepo,
        private ViewDedupHasher $hasher,
        private bool $trustCloudflareIp,
    ) {}

    public function recordView(ServerRequestInterface $request, int $ideaId): void
    {
        $ip        = ClientIp::resolve($request, $this->trustCloudflareIp);
        $userAgent = $request->getHeaderLine('User-Agent');
        $hash      = $this->hasher->hash($ideaId, $ip, $userAgent);
        $bucket    = 'idea-view:' . $ideaId . ':' . $hash;

        try {
            $isFirstViewInWindow = $this->limiter->count($bucket, self::WINDOW_SECONDS) === 1;
        } catch (DbalException) {
            return;
        }

        if (!$isFirstViewInWindow) {
            return;
        }

        try {
            $this->ideaRepo->incrementViewCount($ideaId);
        } catch (DbalException) {
            // best-effort — view count is a soft metric, not page-load-critical
        }
    }
}
