<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Outbound HTTP seam for GoogleTranslateProvider's two calls — the
 * signed-JWT → OAuth2 access-token exchange (form-encoded, mirrors
 * Security\OAuth\OAuthHttpClient::postForm()) and the actual Cloud
 * Translation Advanced (v3) call (JSON body). Same
 * interface+curl-impl+in-memory-fake pattern as Security\OAuth — this
 * codebase has no Guzzle/PSR HTTP client dependency.
 *
 * Failures here must NOT be swallowed — see TranslationHttpException.
 */
interface TranslationHttpClient
{
    /**
     * @param array<string, string> $params  form-encoded body
     * @param list<string>          $headers raw header lines ("Name: value")
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws TranslationHttpException on a transport-level failure
     */
    public function postForm(string $url, array $params, array $headers = []): array;

    /**
     * @param array<array-key, mixed> $json
     * @param list<string>            $headers raw header lines ("Name: value")
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws TranslationHttpException on a transport-level failure
     */
    public function postJson(string $url, array $json, array $headers = []): array;
}
