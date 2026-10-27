<?php

declare(strict_types=1);

namespace Votepit\Webhook\Chat;

use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\RateLimiter;
use Votepit\Security\Webhook\WebhookSenderInterface;
use Votepit\Security\Webhook\WebhookSendResult;
use Votepit\Security\Webhook\WebhookTarget;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * Pushes board events to the board's configured Slack/Discord incoming
 * webhooks. Called from WebhookDispatcher::dispatchAsync(), so every board
 * event the generic webhook fan-out knows (idea.created,
 * idea.status_changed, and idea.merged once that event exists) reaches
 * chat without touching the triggering actions.
 *
 * Reuses the board-webhook delivery building blocks — WebhookUrlGuard (IP
 * pin / DNS-rebinding defense), WebhookSenderInterface/CurlWebhookSender
 * (https only, FOLLOWLOCATION off), the defer runner and RateLimiter — but
 * is stricter than the generic webhook:
 *   - the URL must pass the provider's exact host allowlist
 *     (ChatProvider::urlPolicy()) at storage AND at delivery time;
 *   - redirects are never followed (a 3xx counts as a failed delivery);
 *   - short timeouts, at most 2 attempts (retry only on transport errors,
 *     429 and 5xx).
 *
 * Plan gate: PlanPolicy::chatNotificationsAllowed() is re-checked on every
 * dispatch, so a downgraded account stops sending while its stored config
 * stays (the admin can re-enable by upgrading or delete it).
 *
 * Fail-safe for the triggering request: dispatchAsync() catches
 * everything — a DB error, a broken ciphertext or an unreachable chat
 * service never surfaces in the user's request. The HTTP work itself
 * always runs via $deferRunner (after the response, see WebhookDispatcher).
 */
final readonly class ChatNotificationDispatcher
{
    private const MAX_ATTEMPTS      = 2;
    private const CONNECT_TIMEOUT_S = 2;
    private const TIMEOUT_S         = 4;
    private const DEFAULT_RETRY_BACKOFF_US = [1_000_000];
    private const RATE_LIMIT_MAX_PER_WINDOW = 30;
    private const RATE_LIMIT_WINDOW_S       = 60;

    /** @var list<int> */
    private array $retryBackoffUs;

    /**
     * @param \Closure(\Closure(): void): void $deferRunner
     * @param null|list<int> $retryBackoffUs microseconds between attempts (test-only override)
     */
    public function __construct(
        private BoardChatIntegrationRepository $repo,
        private WebhookUrlGuard $guard,
        private WebhookSenderInterface $sender,
        private EncryptionService $urlEnc,
        private PlanPolicy $planPolicy,
        private AccountPlanResolver $plans,
        private ChatMessageFormatter $formatter,
        private \Closure $deferRunner,
        ?array $retryBackoffUs = null,
        private ?RateLimiter $rateLimiter = null,
    ) {
        $this->retryBackoffUs = $retryBackoffUs ?? self::DEFAULT_RETRY_BACKOFF_US;
    }

    /**
     * @param array<string, mixed> $data same contract as WebhookDispatcher::dispatchAsync()
     */
    public function dispatchAsync(int $boardId, string $event, array $data): void
    {
        if (!in_array($event, ChatMessageFormatter::SUPPORTED_EVENTS, true)) {
            return;
        }

        try {
            $jobs = [];
            foreach ($this->repo->findForDispatch($boardId) as $row) {
                $provider = ChatProvider::tryFrom($row['provider']);
                if ($provider === null || !$this->planPolicy->chatNotificationsAllowed($this->plans->forAccount($row['account_id'], null))) {
                    continue;
                }

                $body = $this->formatter->format($provider, $event, $row['board_name'], $row['account_slug'], $row['board_slug'], $data);
                if ($body !== null) {
                    $jobs[] = [$row['id'], $provider, $row['url_encrypted'], $body];
                }
            }
            if ($jobs === [] || !$this->allowedByRateLimit($boardId)) {
                return;
            }

            ($this->deferRunner)(function () use ($jobs): void {
                foreach ($jobs as [$id, $provider, $urlEncrypted, $body]) {
                    $this->deliver($id, $provider, $urlEncrypted, $body);
                }
            });
        } catch (\Throwable) {
            // Notification side-channel only — never break the user's request.
        }
    }

    /**
     * One delivery (≤ MAX_ATTEMPTS tries) of an already-formatted body.
     * Public so tests can drive it directly.
     */
    public function deliver(int $integrationId, ChatProvider $provider, string $urlEncrypted, string $body): void
    {
        try {
            $url = $this->urlEnc->decrypt($urlEncrypted);
            if ($url === null || !$provider->urlPolicy()->allows($url)) {
                $this->record($integrationId, false, $url === null ? 'decrypt_failed' : 'blocked_by_url_policy');
                return;
            }

            $target = $this->guard->resolveForDelivery($url);
            if (!$target instanceof WebhookTarget) {
                $this->record($integrationId, false, 'blocked_by_ssrf_guard');
                return;
            }

            $headers = ['Content-Type: application/json', 'User-Agent: Votepit-Chat/1.0'];
            $attempt = 0;

            do {
                ++$attempt;
                $result = $this->sender->send($target, $headers, $body, self::CONNECT_TIMEOUT_S, self::TIMEOUT_S);

                if ($result->isSuccess()) {
                    $this->record($integrationId, true, null);
                    return;
                }

                $retry = $attempt < self::MAX_ATTEMPTS && $this->isRetryable($result);
                if ($retry) {
                    usleep($this->retryBackoffUs[$attempt - 1] ?? 1_000_000);
                }
            } while ($retry);

            $this->record($integrationId, false, $this->errorCode($result));
        } catch (\Throwable) {
            // Best-effort, post-response — nothing left to protect.
        }
    }

    private function isRetryable(WebhookSendResult $result): bool
    {
        if (!$result->transportOk) {
            return true;
        }
        $code = (int) $result->statusCode;
        return $code === 429 || $code >= 500;
    }

    private function errorCode(WebhookSendResult $result): string
    {
        if (!$result->transportOk) {
            return 'transport_error';
        }
        $code = (int) $result->statusCode;
        return $code >= 300 && $code < 400 ? 'redirect_not_followed' : 'http_' . $code;
    }

    private function allowedByRateLimit(int $boardId): bool
    {
        if (!$this->rateLimiter instanceof RateLimiter) {
            return true;
        }

        try {
            return $this->rateLimiter->hit('chat_delivery:board:' . $boardId, self::RATE_LIMIT_MAX_PER_WINDOW, self::RATE_LIMIT_WINDOW_S);
        } catch (\Throwable) {
            return true; // fail-open, same reasoning as WebhookDispatcher
        }
    }

    private function record(int $integrationId, bool $success, ?string $error): void
    {
        try {
            $this->repo->recordDelivery($integrationId, $success, $error);
        } catch (\Throwable) {
            // Status is telemetry only.
        }
    }
}
