<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardWebhookRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\Webhook\WebhookUrlGuard;

/**
 * Board-webhooks admin endpoints (AuthZ: accountAdmin — owner|admin, same
 * tier as board SMTP settings/API tokens).
 *
 *   GET   /admin/boards/{slug}/webhook          — current config (never the secret) + recent deliveries.
 *   PUT   /admin/boards/{slug}/webhook          — set/replace the URL; ALWAYS issues + returns a fresh
 *                                                   secret (shown exactly once, in this response only —
 *                                                   never retrievable again afterwards, same guarantee as
 *                                                   ApiTokenAction::create()).
 *   PATCH /admin/boards/{slug}/webhook          — { active: bool } — pause/resume without rotating the
 *                                                   URL or secret.
 *   DELETE /admin/boards/{slug}/webhook         — removes the configuration entirely.
 */
final readonly class BoardWebhookAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BoardWebhookRepository $webhookRepo,
        private WebhookUrlGuard $urlGuard,
        private EncryptionService $secretEnc,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board === null) {
            return $this->notFound($response);
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $board['id'];
        $row       = $this->webhookRepo->findForAccountBoard($accountId, $boardId);

        $response->getBody()->write((string) json_encode([
            'configured'  => $row !== null,
            'url'         => $row['url'] ?? null,
            'active'      => $row['active'] ?? false,
            'created_at'  => $row['created_at'] ?? null,
            'updated_at'  => $row['updated_at'] ?? null,
            'deliveries'  => $this->webhookRepo->recentDeliveries($accountId, $boardId, 20),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board === null) {
            return $this->notFound($response);
        }

        $parsed = $request->getParsedBody();
        $url    = is_array($parsed) ? trim((string) ($parsed['url'] ?? '')) : '';

        $urlError = $this->urlGuard->validateForStorage($url);
        if ($urlError !== null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => ['url' => $urlError]],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $boardId = (int) $board['id'];
        $secret  = bin2hex(random_bytes(32));
        $this->webhookRepo->save($boardId, $url, $this->secretEnc->encrypt($secret));

        $this->audit->log('board.webhook_saved', ['board_id' => $boardId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'secret' => $secret]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function patch(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board === null) {
            return $this->notFound($response);
        }

        $parsed = $request->getParsedBody();
        if (!is_array($parsed) || !array_key_exists('active', $parsed)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => ['active' => 'Missing "active".']],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $board['id'];
        $active    = (bool) $parsed['active'];

        $updated = $this->webhookRepo->setActive($accountId, $boardId, $active);
        if (!$updated) {
            return $this->notFound($response);
        }

        $this->audit->log('board.webhook_active_set', ['board_id' => $boardId, 'active' => $active]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'active' => $active]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->resolveBoard($request, $args);
        if ($board === null) {
            return $this->notFound($response);
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $board['id'];

        $deleted = $this->webhookRepo->delete($accountId, $boardId);
        if (!$deleted) {
            return $this->notFound($response);
        }

        $this->audit->log('board.webhook_deleted', ['board_id' => $boardId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function resolveBoard(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        return is_array($board) ? $board : null;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
