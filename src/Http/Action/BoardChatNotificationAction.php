<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\EncryptionService;
use Votepit\Webhook\Chat\ChatProvider;

/**
 * Slack/Discord chat-notification settings per board (AuthZ: accountAdmin,
 * same tier as the board webhook).
 *
 *   GET    /admin/boards/{slug}/chat-notifications             — { allowed, integrations: { slack, discord } }
 *   PUT    /admin/boards/{slug}/chat-notifications/{provider}  — { url } set/replace (plan-gated)
 *   DELETE /admin/boards/{slug}/chat-notifications/{provider}  — remove (never plan-gated, so a
 *                                                                 downgraded account can clean up)
 *
 * The incoming-webhook URL is a bearer secret: it is validated against the
 * provider's exact host allowlist, stored encrypted, and never returned —
 * the client only ever sees url_hint.
 */
final readonly class BoardChatNotificationAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private BoardChatIntegrationRepository $repo,
        private EncryptionService $urlEnc,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }
        $boardId = (int) $board['id'];

        $integrations = [];
        foreach (ChatProvider::cases() as $provider) {
            $integrations[$provider->value] = null;
        }
        foreach ($this->repo->listForAccountBoard($this->accountId($request), $boardId) as $row) {
            if (array_key_exists($row['provider'], $integrations)) {
                $integrations[$row['provider']] = $row;
            }
        }

        return $this->json($response, 200, [
            'allowed'      => $this->allowed($request),
            'integrations' => $integrations,
        ]);
    }

    /** @param array<string, mixed> $args */
    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board    = $this->resolveBoard($request, $args);
        $provider = ChatProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        if ($board === null || $provider === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }
        $boardId = (int) $board['id'];

        if (!$this->allowed($request)) {
            return $this->json($response, 422, ['error' => [
                'key'     => 'plan_limit_chat_notifications',
                'message' => 'Slack/Discord notifications are not available on your current plan.',
            ]]);
        }

        $parsed = $request->getParsedBody();
        $url    = is_array($parsed) && is_string($parsed['url'] ?? null) ? trim($parsed['url']) : '';
        if (!$provider->urlPolicy()->allows($url)) {
            return $this->json($response, 422, ['error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => ['url' => $provider === ChatProvider::Slack
                    ? 'Enter a Slack incoming-webhook URL (https://hooks.slack.com/services/…).'
                    : 'Enter a Discord webhook URL (https://discord.com/api/webhooks/…).'],
            ]]);
        }

        $hint  = $provider->urlHint($url);
        $saved = $this->repo->save($this->accountId($request), $boardId, $provider->value, $this->urlEnc->encrypt($url), $hint);
        if (!$saved) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
        }

        $this->audit->log('board.chat_notification_saved', ['board_id' => $boardId, 'provider' => $provider->value]);

        return $this->json($response, 200, ['ok' => true, 'provider' => $provider->value, 'url_hint' => $hint]);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board !== null && FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }
        $boardId  = is_array($board) ? (int) $board['id'] : null;
        $provider = ChatProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        if ($boardId === null || $provider === null || !$this->repo->delete($this->accountId($request), $boardId, $provider->value)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
        }

        $this->audit->log('board.chat_notification_deleted', ['board_id' => $boardId, 'provider' => $provider->value]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        $plan    = $this->plans->forAccount($this->accountId($request), $request->getAttribute(AuthNMiddleware::ATTR_USER));

        return $this->planPolicy->chatNotificationsAllowed($plan);
    }

    private function accountId(ServerRequestInterface $request): int
    {
        return (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function resolveBoard(ServerRequestInterface $request, array $args): ?array
    {
        $slug = is_string($args['slug'] ?? null) ? $args['slug'] : '';

        return $this->boardRepo->findBySlugForAccount($slug, $this->accountId($request));
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
