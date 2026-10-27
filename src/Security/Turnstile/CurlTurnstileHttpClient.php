<?php

declare(strict_types=1);

namespace Votepit\Security\Turnstile;

/**
 * curl-based TurnstileHttpClient (production/self-host default). Short,
 * fixed timeout — an unreachable Cloudflare must not stall the guarded
 * action for long; TurnstileGuard fails secure (rejects) on any failure
 * this returns as null.
 */
final readonly class CurlTurnstileHttpClient implements TurnstileHttpClient
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT_MS = 5000;

    public function verify(string $secretKey, string $token, ?string $remoteIp): ?array
    {
        $ch = curl_init(self::VERIFY_URL);
        if ($ch === false) {
            return null;
        }

        $params = ['secret' => $secretKey, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $params['remoteip'] = $remoteIp;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => http_build_query($params),
            CURLOPT_USERAGENT         => 'Votepit-TurnstileGuard',
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return null;
        }

        /** @var int $status */
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
