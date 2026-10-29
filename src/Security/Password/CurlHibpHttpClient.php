<?php

declare(strict_types=1);

namespace Votepit\Security\Password;

/**
 * curl-based HibpHttpClient (production/self-host default). Short, fixed
 * timeout — a slow/unreachable HIBP must not stall password-setting for
 * long; PasswordPolicy fails open on any failure this returns as null.
 */
final readonly class CurlHibpHttpClient implements HibpHttpClient
{
    private const TIMEOUT_MS = 3000;

    public function get(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
            CURLOPT_FOLLOWLOCATION    => false,
            // PHP's curl extension sends no User-Agent by default; some
            // APIs reject that outright (see CurlOAuthHttpClient's GitHub
            // note) — set one defensively even though HIBP itself doesn't
            // currently require it.
            CURLOPT_USERAGENT         => 'Votepit-PasswordPolicy',
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return null;
        }

        /** @var int $status */
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status === 200 ? (string) $raw : null;
    }
}
