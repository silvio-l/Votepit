<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Test double for TranslationHttpClient (mirrors Security\OAuth\
 * InMemoryOAuthHttpClient) — returns canned responses per URL, no real
 * network calls. stub() registers a default response, stubFailure()
 * registers a default transport-level failure (TranslationHttpException)
 * for the given URL.
 *
 * queueResponse()/queueFailure() additionally support per-call sequencing:
 * successive calls to the same URL consume the queue in FIFO order before
 * falling back to the default stub()/stubFailure() — needed to simulate a
 * bulk request where only SOME calls to the (necessarily identical)
 * translate URL fail (CommentTranslateActionTest's per-comment partial
 * failure case).
 *
 * Also counts calls per URL so tests can assert a cache hit did NOT call
 * the provider a second time (ticket 05/06's acceptance criterion).
 */
final class InMemoryTranslationHttpClient implements TranslationHttpClient
{
    /** @var array<string, array{status: int, body: array<array-key, mixed>}> */
    private array $responses = [];

    /** @var array<string, true> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $callCounts = [];

    /** @var array<string, list<array<array-key, mixed>>> every postJson() body sent to a URL, in call order */
    private array $jsonBodies = [];

    /** @var array<string, list<array{status: int, body: array<array-key, mixed>}|null>> null entry = a queued failure */
    private array $queues = [];

    /** @param array{status: int, body: array<array-key, mixed>} $response */
    public function stub(string $url, array $response): void
    {
        $this->responses[$url] = $response;
    }

    public function stubFailure(string $url): void
    {
        $this->failures[$url] = true;
    }

    /** @param array{status: int, body: array<array-key, mixed>} $response */
    public function queueResponse(string $url, array $response): void
    {
        $this->queues[$url][] = $response;
    }

    public function queueFailure(string $url): void
    {
        $this->queues[$url][] = null;
    }

    public function callCount(string $url): int
    {
        return $this->callCounts[$url] ?? 0;
    }

    public function postForm(string $url, array $params, array $headers = []): array
    {
        return $this->respond($url);
    }

    public function postJson(string $url, array $json, array $headers = []): array
    {
        $this->jsonBodies[$url][] = $json;

        return $this->respond($url);
    }

    /** @return list<array<array-key, mixed>> every postJson() body sent to $url, in call order */
    public function jsonBodiesFor(string $url): array
    {
        return $this->jsonBodies[$url] ?? [];
    }

    /** @return array{status: int, body: array<array-key, mixed>} */
    private function respond(string $url): array
    {
        $this->callCounts[$url] = ($this->callCounts[$url] ?? 0) + 1;

        if (isset($this->queues[$url]) && count($this->queues[$url]) > 0) {
            $next = array_shift($this->queues[$url]);
            if ($next === null) {
                throw new TranslationHttpException("translation: queued transport failure for {$url}");
            }

            return $next;
        }

        if (isset($this->failures[$url])) {
            throw new TranslationHttpException("translation: stubbed transport failure for {$url}");
        }

        return $this->responses[$url] ?? throw new TranslationHttpException("translation: no stub registered for {$url}");
    }
}
