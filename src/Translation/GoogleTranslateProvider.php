<?php

declare(strict_types=1);

namespace Votepit\Translation;

use Votepit\Logging\AuditLogger;

/**
 * Calls Cloud Translation Advanced (v3) against the EU multi-regional
 * endpoint (translate-eu.googleapis.com) — never the global endpoint,
 * never Basic (v2), since neither offers EU data residency (see the
 * translation PRD's GDPR correction). Authenticated via a GCP service
 * account: a signed JWT (RS256) exchanged for a short-lived OAuth2 access
 * token — v3 does not accept a bare API key.
 *
 * GDPR posture (load-bearing, not just a guideline — see the PRD): the
 * request/response payload (the actual idea/comment text and its
 * translation) is NEVER written to $audit in plaintext — only metadata
 * (target locale, provider, success/failure, latency) is logged, via
 * logCall(). Every exit path of translate() funnels through it.
 */
final readonly class GoogleTranslateProvider implements TranslationProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/cloud-translation';
    private const JWT_TTL   = 3600;

    public function __construct(
        private TranslationHttpClient $http,
        private string $projectId,
        private string $clientEmail,
        private string $privateKeyPem,
        private string $region = 'eu',
        private ?AuditLogger $audit = null,
    ) {}

    public function translate(string $text, string $targetLocale, ?string $sourceLocale = null): array
    {
        $startedAt = microtime(true);

        try {
            $accessToken = $this->fetchAccessToken();
            $result      = $this->callTranslateApi($accessToken, $text, $targetLocale, $sourceLocale);

            $this->logCall($targetLocale, true, $startedAt);

            return $result;
        } catch (\Throwable $e) {
            $this->logCall($targetLocale, false, $startedAt);

            if ($e instanceof TranslationUnavailableException) {
                throw $e;
            }

            throw new TranslationUnavailableException('translation: google translate call failed', 0, $e);
        }
    }

    /**
     * Signed-JWT (RS256) → OAuth2 access-token exchange (form-encoded POST,
     * grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer). Any failure
     * here (transport, non-2xx, missing access_token) is an auth failure —
     * surfaced as TranslationUnavailableException.
     */
    private function fetchAccessToken(): string
    {
        $jwt = $this->buildSignedJwt();

        try {
            $response = $this->http->postForm(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);
        } catch (TranslationHttpException $e) {
            throw new TranslationUnavailableException('translation: token exchange transport failure', 0, $e);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new TranslationUnavailableException('translation: token exchange returned status ' . $response['status']);
        }

        $accessToken = $response['body']['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new TranslationUnavailableException('translation: token exchange response missing access_token');
        }

        return $accessToken;
    }

    private function buildSignedJwt(): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + self::JWT_TTL,
        ];

        $signingInput = $this->base64UrlEncode((string) json_encode($header))
            . '.' . $this->base64UrlEncode((string) json_encode($claims));

        $signature = '';
        $signed    = openssl_sign($signingInput, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new TranslationUnavailableException('translation: failed to sign JWT with the configured service-account key');
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Google's translate-<region>.googleapis.com multi-regional hostnames
     * (e.g. 'eu') don't accept that same identifier as the `locations/`
     * resource segment in the request path — the API rejects it with
     * "Unsupported location 'eu'. Must be 'europe-west1'." (found by a real
     * call against the live API, 2026-09-08; the mocked HTTP client in tests
     * never exercised this). Map the multi-region identifiers this codebase
     * documents/supports to their concrete backing location; anything else
     * (a self-hoster naming a concrete region directly, e.g. 'europe-west1')
     * passes through unchanged.
     */
    private const LOCATION_FOR_REGION = [
        'eu' => 'europe-west1',
        'us' => 'us-central1',
    ];

    /**
     * @return array{text: string, detected_source_locale: string}
     */
    private function callTranslateApi(string $accessToken, string $text, string $targetLocale, ?string $sourceLocale): array
    {
        $host     = 'translate-' . $this->region . '.googleapis.com';
        $location = self::LOCATION_FOR_REGION[$this->region] ?? $this->region;
        $url      = "https://{$host}/v3/projects/{$this->projectId}/locations/{$location}:translateText";

        $body = [
            'contents'           => [$text],
            'targetLanguageCode' => $targetLocale,
            'mimeType'           => 'text/plain',
        ];
        if ($sourceLocale !== null && $sourceLocale !== '') {
            $body['sourceLanguageCode'] = $sourceLocale;
        }

        try {
            $response = $this->http->postJson($url, $body, [
                'Authorization: Bearer ' . $accessToken,
            ]);
        } catch (TranslationHttpException $e) {
            throw new TranslationUnavailableException('translation: translate call transport failure', 0, $e);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new TranslationUnavailableException('translation: translate call returned status ' . $response['status']);
        }

        $translations = $response['body']['translations'] ?? null;
        if (!is_array($translations) || !isset($translations[0]) || !is_array($translations[0])) {
            throw new TranslationUnavailableException('translation: malformed translate response');
        }

        $translatedText = $translations[0]['translatedText'] ?? null;
        if (!is_string($translatedText) || $translatedText === '') {
            throw new TranslationUnavailableException('translation: empty translate response');
        }

        // With an explicit $sourceLocale, the API skips detection entirely
        // and omits detectedLanguageCode from the response — report back
        // the locale we told it to assume rather than an empty string.
        $detectedLocale = $sourceLocale ?? ($translations[0]['detectedLanguageCode'] ?? '');

        return [
            'text'                    => $translatedText,
            'detected_source_locale'  => is_string($detectedLocale) ? $detectedLocale : '',
        ];
    }

    /**
     * Metadata-only logging (GDPR posture, see class doc) — NEVER the
     * translated text or the response body, only $targetLocale (a locale
     * code, not content), a success flag and latency.
     */
    private function logCall(string $targetLocale, bool $success, float $startedAt): void
    {
        $this->audit?->log('translation.google_call', [
            'provider'      => 'google',
            'target_locale' => $targetLocale,
            'success'       => $success,
            'latency_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
