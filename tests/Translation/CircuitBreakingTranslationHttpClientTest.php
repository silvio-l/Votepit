<?php

declare(strict_types=1);

namespace Votepit\Tests\Translation;

use Votepit\Resilience\CircuitBreaker;
use Votepit\Resilience\CircuitOpenException;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Translation\CircuitBreakingTranslationHttpClient;
use Votepit\Translation\InMemoryTranslationHttpClient;
use Votepit\Translation\TranslationHttpException;

/** OWASP/BSI audit remediation issue 13: Google Translate circuit breaker. */
final class CircuitBreakingTranslationHttpClientTest extends IntegrationTestCase
{
    public function test_passes_calls_through_while_closed(): void
    {
        $inner = new InMemoryTranslationHttpClient();
        $inner->stub('https://example.test/translate', ['status' => 200, 'body' => ['ok' => true]]);
        $client = new CircuitBreakingTranslationHttpClient($inner, new CircuitBreaker($this->conn));

        $result = $client->postJson('https://example.test/translate', ['q' => 'hi']);

        self::assertSame(1, $inner->callCount('https://example.test/translate'));
        self::assertSame(['status' => 200, 'body' => ['ok' => true]], $result);
    }

    public function test_opens_after_the_threshold_and_fails_fast_without_calling_inner(): void
    {
        $inner = new InMemoryTranslationHttpClient();
        $inner->stubFailure('https://example.test/translate');
        $client = new CircuitBreakingTranslationHttpClient($inner, new CircuitBreaker($this->conn), failureThreshold: 2);

        foreach ([1, 2] as $_) {
            try {
                $client->postJson('https://example.test/translate', []);
                self::fail('expected exception');
            } catch (TranslationHttpException) {
            }
        }
        self::assertSame(2, $inner->callCount('https://example.test/translate'));

        // Third call: circuit open — CircuitOpenException, inner is never
        // called a third time.
        $this->expectException(CircuitOpenException::class);
        $client->postJson('https://example.test/translate', []);
        self::assertSame(2, $inner->callCount('https://example.test/translate'));
    }

    public function test_a_non_2xx_response_does_not_open_the_circuit(): void
    {
        $inner = new InMemoryTranslationHttpClient();
        $inner->stub('https://example.test/translate', ['status' => 500, 'body' => []]);
        $breaker = new CircuitBreaker($this->conn);
        $client  = new CircuitBreakingTranslationHttpClient($inner, $breaker, failureThreshold: 2);

        for ($i = 0; $i < 5; $i++) {
            $result = $client->postJson('https://example.test/translate', []);
            self::assertSame(500, $result['status']);
        }

        self::assertFalse($breaker->isOpen('translation', 120));
        self::assertSame(5, $inner->callCount('https://example.test/translate'));
    }
}
