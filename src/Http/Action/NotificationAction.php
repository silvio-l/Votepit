<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\NotificationRepository;

/**
 * GET    /notifications           — the caller's inbox (own account-scoped
 *                                    notifications + every broadcast).
 * POST   /notifications/{id}/read — mark one notification read.
 * DELETE /notifications/{id}      — dismiss (remove) one notification from
 *                                    the caller's own inbox, permanently.
 *
 * AuthZ: user() — any logged-in user, since listForUser() itself already
 * scopes to the caller's own account memberships (NotificationRepository).
 */
final readonly class NotificationAction
{
    public function __construct(private NotificationRepository $notifications) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);

        $notifications = array_map(
            $this->present(...),
            $this->notifications->listForUser($userId),
        );

        return $this->json($response, 200, ['notifications' => $notifications]);
    }

    /** @param array<string, mixed> $args */
    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId($request);
        $id     = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        if ($id <= 0 || !$this->notifications->isVisibleToUser($id, $userId)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Notification not found.']]);
        }

        $this->notifications->markRead($id, $userId);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function dismiss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId($request);
        $id     = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        if ($id <= 0 || !$this->notifications->isVisibleToUser($id, $userId)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Notification not found.']]);
        }

        $this->notifications->dismiss($id, $userId);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'scope'      => (string) $row['scope'],
            'type'       => (string) $row['type'],
            'title'      => (string) $row['title'],
            'body'       => (string) $row['body'],
            'link_path'  => $row['link_path'],
            'created_at' => $row['created_at'],
            'is_read'    => (bool) $row['is_read'],
        ];
    }

    private function userId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
