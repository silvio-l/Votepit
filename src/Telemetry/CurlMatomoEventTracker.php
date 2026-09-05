<?php

declare(strict_types=1);

namespace Votepit\Telemetry;

/**
 * Fires a single event at Matomo's HTTP Tracking API
 * (https://developer.matomo.org/api-reference/tracking-api) — the
 * server-side equivalent of the JS tracker's `_paq.push(['trackEvent', ...])`.
 * No cookies (server-to-server, none to send), no visitor/session
 * identification beyond Matomo's own random `_id` — same anonymity posture
 * as the client-side tracker.
 *
 * Deliberately fire-and-forget: a short connect+total timeout, every
 * failure (network, DNS, non-2xx) is swallowed. A webhook handler that
 * blocks on or fails because of an analytics side-effect would violate the
 * "never breaks the app" requirement — Matomo being down must never turn a
 * successful payment into a failed webhook response (a payment provider
 * would retry it).
 */
final readonly class CurlMatomoEventTracker implements MatomoEventTracker
{
    private const TIMEOUT_MS = 800;

    public function __construct(
        private string $matomoUrl,
        private string $siteId,
        private string $appUrl,
    ) {}

    public function track(string $category, string $action): void
    {
        if ($this->matomoUrl === '' || $this->siteId === '') {
            return;
        }

        $query = http_build_query([
            'idsite'  => $this->siteId,
            'rec'     => 1,
            'e_c'     => $category,
            'e_a'     => $action,
            'url'     => $this->appUrl,
            'rand'    => random_int(0, PHP_INT_MAX),
            'apiv'    => 1,
        ]);

        $ch = curl_init("{$this->matomoUrl}/matomo.php?{$query}");
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
            CURLOPT_FAILONERROR       => false,
        ]);

        try {
            curl_exec($ch);
        } catch (\Throwable) {
            // Swallowed by design — see class doc.
        } finally {
            curl_close($ch);
        }
    }
}
