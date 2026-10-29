<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * curl-based TranslationHttpClient (this codebase has no Guzzle/PSR HTTP
 * client dependency — see Security\OAuth\CurlOAuthHttpClient for the exact
 * same pattern this mirrors). Short, fixed timeouts; redirects are never
 * followed.
 */
final readonly class CurlTranslationHttpClient implements TranslationHttpClient
{
    private const TIMEOUT_MS = 5000;

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->request($url, http_build_query($params), array_merge(
            ['Content-Type: application/x-www-form-urlencoded'],
            $headers,
        ));
    }

    public function postJson(string $url, array $json, array $headers = []): array
    {
        return $this->request($url, (string) json_encode($json), array_merge(
            ['Content-Type: application/json'],
            $headers,
        ));
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: array<array-key, mixed>}
     */
    private function request(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new TranslationHttpException('translation: curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_USERAGENT         => 'Votepit-Translation',
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $body,
            CURLOPT_HTTPHEADER        => array_merge(['Accept: application/json'], $headers),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TranslationHttpException('translation: request failed: ' . $error);
        }

        /** @var int $status */
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var mixed $decoded */
        $decoded = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
