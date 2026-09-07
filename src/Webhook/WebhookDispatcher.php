<?php

declare(strict_types=1);

namespace Votepit\Webhook;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Persistence\BoardWebhookRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookSignature;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * Fires the board-webhooks event fan-out for board events (idea created,
 * idea status changed, …). Board-webhooks feature — see the class docs on
 * WebhookUrlGuard (SSRF) and WebhookSignature (payload authenticity) for
 * the two security-critical pieces this class composes.
 *
 * Fail-secure vs. fail-open, deliberately per direction (project security
 * policy: "every layer denies on error" — read together with the "must
 * never block the triggering request" requirement, these two constraints
 * point the same way here):
 *   - the SSRF check is fail-CLOSED: any URL that doesn't resolve to a
 *     verified-public IP is never dialed, no exceptions, no retry.
 *   - webhook DELIVERY is fail-OPEN/best-effort: a broken or unreachable
 *     receiver must never affect the triggering user action (idea
 *     creation, a status change) — this is a notification side-channel,
 *     not part of the write's correctness.
 *
 * Never runs inline in the request/response cycle: dispatchAsync() defers
 * the actual HTTP work to $deferRunner (default: register_shutdown_function,
 * i.e. after the response body is already assembled — combined with
 * fastcgi_finish_request() where available, this runs after the client has
 * already received their response, so retries/backoff add zero perceived
 * latency to idea creation / status changes). $deferRunner is injectable so
 * tests can run delivery synchronously and deterministically instead of
 * waiting for PHP process shutdown.
 *
 * Per-board rate limiting (RateLimiter, same fixed-window/fail-open service
 * RateLimitMiddleware uses for HTTP routes): caps how many delivery attempts
 * a single board's webhook can trigger per window, so a receiver that a
 * malicious/compromised board owner points at itself — or simply a board
 * with a very high event volume — can never turn into a self-inflicted DoS
 * against this server's outbound connection pool. This gates dispatchAsync()
 * (one check per triggering event), not the bounded 3-attempt retry loop
 * inside a single delivery, which is already short-lived and capped.
 * Fail-open on a limiter DB error, deliberately: the rate limiter protects
 * availability, it must never itself become an availability problem (see
 * RateLimitMiddleware's class doc for the same reasoning).
 */
final readonly class WebhookDispatcher
{
    private const MAX_ATTEMPTS      = 3;
    private const MAX_REDIRECTS     = 3;
    private const CONNECT_TIMEOUT_S = 3;
    private const TIMEOUT_S         = 5;
    /** Default backoff between retry attempts, in microseconds (1s, 2s).
     *  Only ever runs post-response (see class doc), so blocking here is
     *  harmless in production; injectable so tests don't have to wait. */
    private const DEFAULT_RETRY_BACKOFF_US = [1_000_000, 2_000_000];
    /** Per-board delivery-dispatch cap: generous for legitimate use (bulk
     *  imports, busy boards) while still bounding a runaway event flood. */
    private const RATE_LIMIT_MAX_PER_WINDOW = 30;
    private const RATE_LIMIT_WINDOW_S       = 60;

    /** @var \Closure(\Closure(): void): void */
    private \Closure $deferRunner;

    /** @var list<int> */
    private array $retryBackoffUs;

    /**
     * @param null|\Closure(\Closure(): void): void $deferRunner
     * @param null|list<int> $retryBackoffUs microseconds between attempts (test-only override)
     */
    public function __construct(
        private BoardWebhookRepository $webhookRepo,
        private WebhookUrlGuard $guard,
        private WebhookSenderInterface $sender,
        private EncryptionService $secretEnc,
        ?\Closure $deferRunner = null,
        ?array $retryBackoffUs = null,
        private ?RateLimiter $rateLimiter = null,
    ) {
        $this->deferRunner   = $deferRunner ?? $this->defaultDeferRunner();
        $this->retryBackoffUs = $retryBackoffUs ?? self::DEFAULT_RETRY_BACKOFF_US;
    }

    /**
     * @param array<string, mixed> $data event-specific fields (idea id/title/status/…) — no PII, see class doc on the caller side.
     */
    public function dispatchAsync(int $boardId, string $boardSlug, string $event, array $data): void
    {
        $webhook = $this->webhookRepo->findActiveForBoard($boardId);
        if ($webhook === null) {
            return;
        }

        if (!$this->allowedByRateLimit($boardId)) {
            $this->recordDelivery($webhook['id'], $event, false, null, 'rate_limited');
            return;
        }

        $payload = json_encode([
            'event'      => $event,
            'timestamp'  => (new \DateTimeImmutable())->format(DATE_ATOM),
            'board_slug' => $boardSlug,
            'data'       => $data,
        ], JSON_THROW_ON_ERROR);

        ($this->deferRunner)(function () use ($webhook, $event, $payload): void {
            $this->deliver($webhook, $event, $payload);
        });
    }

    /**
     * Runs the full attempt/backoff/redirect loop for one webhook + one
     * already-built payload. Public so tests can call it directly, bypassing
     * $deferRunner entirely (deterministic, no process-shutdown timing).
     *
     * @param array{id: int, board_id: int, url: string, secret_encrypted: string} $webhook
     */
    public function deliver(array $webhook, string $event, string $payloadJson): void
    {
        $secret = $this->secretEnc->decrypt($webhook['secret_encrypted']);
        if ($secret === null || $secret === '') {
            $this->recordDelivery($webhook['id'], $event, false, null, 'secret_decrypt_failed');
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: Votepit-Webhook/1.0',
            'X-Votepit-Event: ' . $event,
            WebhookSignature::HEADER_NAME . ': ' . WebhookSignature::headerValue($secret, $payloadJson),
        ];

        $lastStatusCode = null;
        $lastError      = 'blocked_by_ssrf_guard';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $result = $this->attemptOnce($webhook['url'], $headers, $payloadJson);

            if (!$result instanceof \Votepit\Security\Webhook\WebhookSendResult) {
                // SSRF guard rejected the (possibly redirected-to) target —
                // not a transient failure, retrying changes nothing.
                $this->recordDelivery($webhook['id'], $event, false, null, $lastError);
                return;
            }

            $lastStatusCode = $result->statusCode;
            $lastError      = $result->error;

            if ($result->isSuccess()) {
                $this->recordDelivery($webhook['id'], $event, true, $lastStatusCode, null);
                return;
            }

            // 4xx (except explicit retryable ones) → the receiver rejected
            // the request; retrying the exact same payload won't help.
            if ($result->transportOk && $lastStatusCode !== null && $lastStatusCode >= 400 && $lastStatusCode < 500) {
                $this->recordDelivery($webhook['id'], $event, false, $lastStatusCode, 'client_error');
                return;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep($this->retryBackoffUs[$attempt - 1] ?? 1_000_000);
            }
        }

        $this->recordDelivery($webhook['id'], $event, false, $lastStatusCode, $lastError ?? 'delivery_failed');
    }

    /**
     * One full attempt, including following up to MAX_REDIRECTS hops — each
     * hop is re-validated by the SSRF guard from scratch before it's dialed.
     * Returns null when the guard rejects the URL (initial or any hop).
     *
     * @param list<string> $headers
     */
    private function attemptOnce(string $url, array $headers, string $payloadJson): ?WebhookSendResult
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $target = $this->guard->resolveForDelivery($currentUrl);
            if (!$target instanceof \Votepit\Security\Webhook\WebhookTarget) {
                return null;
            }

            $result = $this->sender->send($target, $headers, $payloadJson, self::CONNECT_TIMEOUT_S, self::TIMEOUT_S);

            if (!$result->isRedirect()) {
                return $result;
            }

            $location = (string) $result->locationHeader;
            // Relative Location headers are rejected outright — resolving
            // them against the current URL would need the same care as the
            // absolute case for no real-world benefit (webhook receivers
            // redirecting at all is already unusual).
            if (!str_starts_with($location, 'https://')) {
                return null;
            }

            $currentUrl = $location;
        }

        return WebhookSendResult::transportError('too_many_redirects');
    }

    private function allowedByRateLimit(int $boardId): bool
    {
        if (!$this->rateLimiter instanceof RateLimiter) {
            return true; // no limiter injected (e.g. some test setups) → not limited
        }

        try {
            return $this->rateLimiter->hit('webhook_delivery:board:' . $boardId, self::RATE_LIMIT_MAX_PER_WINDOW, self::RATE_LIMIT_WINDOW_S);
        } catch (DbalException) {
            return true; // fail-open — see class doc
        }
    }

    private function recordDelivery(int $webhookId, string $event, bool $success, ?int $statusCode, ?string $error): void
    {
        try {
            $this->webhookRepo->recordDelivery($webhookId, $event, $success, $statusCode, $error);
        } catch (\Throwable) {
            // Best-effort log — never let a broken delivery-log write
            // surface as a second failure on top of an already-failed
            // (or even a successful) delivery.
        }
    }

    /** @return \Closure(\Closure(): void): void */
    private function defaultDeferRunner(): \Closure
    {
        return static function (\Closure $work): void {
            register_shutdown_function(static function () use ($work): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $work();
            });
        };
    }
}
