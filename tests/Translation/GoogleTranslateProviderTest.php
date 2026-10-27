<?php

declare(strict_types=1);

namespace Votepit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Votepit\Logging\AuditLogger;
use Votepit\Translation\GoogleTranslateProvider;
use Votepit\Translation\InMemoryTranslationHttpClient;
use Votepit\Translation\TranslationUnavailableException;

/**
 * GoogleTranslateProvider — Cloud Translation Advanced (v3), EU endpoint,
 * service-account auth. Every test uses InMemoryTranslationHttpClient
 * exclusively — no real network call. The private key is a throwaway
 * RSA key generated for the test only, JWT signature content is never
 * asserted (the fake doesn't parse the request), only that the seam
 * produces a usable result/failure.
 */
final class GoogleTranslateProviderTest extends TestCase
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Verified against the real API (2026-09-08): the 'eu' multi-regional
     * hostname stays 'eu', but the `locations/` resource segment must be the
     * concrete backing region ('europe-west1'), and the method is
     * `:translateText`, not `:translate` — both of the previous (untested
     * against a real endpoint) values 404'd for real.
     */
    private const TRANSLATE_URL = 'https://translate-eu.googleapis.com/v3/projects/test-project/locations/europe-west1:translateText';

    private string $privateKeyPem;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource, 'test fixture: RSA key generation must succeed');
        openssl_pkey_export($resource, $pem);
        $this->privateKeyPem = $pem;
    }

    private function makeProvider(InMemoryTranslationHttpClient $http, ?AuditLogger $audit = null): GoogleTranslateProvider
    {
        return new GoogleTranslateProvider($http, 'test-project', 'svc@test-project.iam.gserviceaccount.com', $this->privateKeyPem, 'eu', $audit);
    }

    private function stubSuccessfulToken(InMemoryTranslationHttpClient $http): void
    {
        $http->stub(self::TOKEN_URL, ['status' => 200, 'body' => ['access_token' => 'fake-access-token', 'expires_in' => 3600]]);
    }

    public function test_successful_round_trip_returns_translated_text_and_detected_locale(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Hallo Welt', 'detectedLanguageCode' => 'en']]],
        ]);

        $result = $this->makeProvider($http)->translate('Hello world', 'de');

        self::assertSame('Hallo Welt', $result['text']);
        self::assertSame('en', $result['detected_source_locale']);
    }

    public function test_translate_without_source_locale_omits_it_from_the_request(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Hallo Welt', 'detectedLanguageCode' => 'en']]],
        ]);

        $this->makeProvider($http)->translate('Hello world', 'de');

        $sent = $http->jsonBodiesFor(self::TRANSLATE_URL)[0];
        self::assertArrayNotHasKey('sourceLanguageCode', $sent);
    }

    /**
     * Regression: an idea's title (e.g. "Votepit als native Mobile App
     * (iOS & Android)") is short and dominated by English loanwords, so
     * Google's own per-call language detection can misclassify it as
     * English and translate it as a no-op — while the same idea's BODY
     * (more text, correctly detected as German) gets translated fine.
     * Passing $sourceLocale forces the API to skip its own (unreliable)
     * detection and translate from the given locale instead.
     */
    public function test_translate_with_source_locale_forces_it_in_the_request_and_response(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            // Google omits detectedLanguageCode entirely when a source was given.
            'body'   => ['translations' => [['translatedText' => 'Native Mobile App'], ]],
        ]);

        $result = $this->makeProvider($http)->translate('Native Mobile App', 'en', 'de');

        $sent = $http->jsonBodiesFor(self::TRANSLATE_URL)[0];
        self::assertSame('de', $sent['sourceLanguageCode']);
        self::assertSame('de', $result['detected_source_locale']);
    }

    public function test_concrete_region_passes_through_unchanged_in_location_path(): void
    {
        $translateUrl = 'https://translate-europe-west1.googleapis.com/v3/projects/test-project/locations/europe-west1:translateText';

        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub($translateUrl, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Hallo Welt', 'detectedLanguageCode' => 'en']]],
        ]);

        $provider = new GoogleTranslateProvider($http, 'test-project', 'svc@test-project.iam.gserviceaccount.com', $this->privateKeyPem, 'europe-west1');

        self::assertSame('Hallo Welt', $provider->translate('Hello world', 'de')['text']);
    }

    public function test_non_2xx_translate_response_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, ['status' => 500, 'body' => []]);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    public function test_token_exchange_failure_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $http->stub(self::TOKEN_URL, ['status' => 401, 'body' => ['error' => 'invalid_grant']]);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    public function test_token_exchange_transport_failure_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $http->stubFailure(self::TOKEN_URL);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    public function test_malformed_translate_response_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, ['status' => 200, 'body' => ['unexpected' => true]]);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    public function test_empty_translated_text_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => '', 'detectedLanguageCode' => 'en']]],
        ]);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    public function test_translate_transport_failure_throws_unavailable(): void
    {
        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stubFailure(self::TRANSLATE_URL);

        $this->expectException(TranslationUnavailableException::class);
        $this->makeProvider($http)->translate('Hello world', 'de');
    }

    // --- GDPR posture: content must never be logged -----------------------------

    public function test_successful_call_logs_only_metadata_never_content(): void
    {
        $logFile = sys_get_temp_dir() . '/votepit-test-translation-audit-' . uniqid() . '.log';
        $audit   = new AuditLogger($logFile);

        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, [
            'status' => 200,
            'body'   => ['translations' => [['translatedText' => 'Ein sehr geheimer Text', 'detectedLanguageCode' => 'en']]],
        ]);

        $this->makeProvider($http, $audit)->translate('A very secret text', 'de');

        $log = (string) file_get_contents($logFile);
        self::assertStringContainsString('translation.google_call', $log);
        self::assertStringContainsString('"target_locale":"de"', $log);
        self::assertStringContainsString('"success":true', $log);
        self::assertStringNotContainsString('secret text', $log);
        self::assertStringNotContainsString('geheimer Text', $log);
        self::assertStringNotContainsString('fake-access-token', $log);

        unlink($logFile);
    }

    public function test_failed_call_logs_failure_metadata_never_content(): void
    {
        $logFile = sys_get_temp_dir() . '/votepit-test-translation-audit-' . uniqid() . '.log';
        $audit   = new AuditLogger($logFile);

        $http = new InMemoryTranslationHttpClient();
        $this->stubSuccessfulToken($http);
        $http->stub(self::TRANSLATE_URL, ['status' => 500, 'body' => []]);

        try {
            $this->makeProvider($http, $audit)->translate('Another secret idea body', 'de');
        } catch (TranslationUnavailableException) {
            // expected
        }

        $log = (string) file_get_contents($logFile);
        self::assertStringContainsString('"success":false', $log);
        self::assertStringNotContainsString('secret idea body', $log);

        unlink($logFile);
    }
}
