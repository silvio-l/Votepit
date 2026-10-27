<?php

declare(strict_types=1);

namespace Votepit\Translation;

use Votepit\Resilience\CircuitBreaker;
use Votepit\Resilience\CircuitOpenException;

/**
 * TranslationHttpClient decorator (OWASP/BSI audit remediation issue 13):
 * after $failureThreshold consecutive transport-level failures (timeouts,
 * DNS/connection errors — TranslationHttpException, never a mere non-2xx
 * HTTP status, which the inner client already returns as a normal {status,
 * body} result rather than throwing), the circuit opens and every further
 * call fails fast with CircuitOpenException for $cooldownSeconds instead of
 * retrying a Google Translate outage on every idea/comment translation
 * request.
 */
final readonly class CircuitBreakingTranslationHttpClient implements TranslationHttpClient
{
    private const SERVICE = 'translation';
    private const DEFAULT_FAILURE_THRESHOLD = 3;
    private const DEFAULT_COOLDOWN_SECONDS  = 120;

    public function __construct(
        private TranslationHttpClient $inner,
        private CircuitBreaker $breaker,
        private int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        private int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
    ) {}

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->call(fn (): array => $this->inner->postForm($url, $params, $headers));
    }

    public function postJson(string $url, array $json, array $headers = []): array
    {
        return $this->call(fn (): array => $this->inner->postJson($url, $json, $headers));
    }

    /**
     * @param \Closure(): array{status: int, body: array<array-key, mixed>} $call
     * @return array{status: int, body: array<array-key, mixed>}
     */
    private function call(\Closure $call): array
    {
        if ($this->breaker->isOpen(self::SERVICE, $this->cooldownSeconds)) {
            throw new CircuitOpenException(self::SERVICE);
        }

        try {
            $result = $call();
        } catch (TranslationHttpException $e) {
            $this->breaker->recordFailure(self::SERVICE, $this->failureThreshold);
            throw $e;
        }

        $this->breaker->recordSuccess(self::SERVICE);
        return $result;
    }
}
