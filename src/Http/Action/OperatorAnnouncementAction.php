<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\NotificationRepository;

/**
 * GET    /operator/announcements       — every broadcast announcement.
 * POST   /operator/announcements       — post a new one (goes into every
 *                                         customer's inbox immediately).
 * DELETE /operator/announcements/{id}  — remove one.
 *
 * AuthZ: operator() only. This is the "news to customers" channel — the
 * in-app replacement for a mailing list (see
 * migrations/0024_add_notifications_remove_support_email.sql).
 */
final readonly class OperatorAnnouncementAction
{
    public function __construct(
        private NotificationRepository $notifications,
        private AuditLogger $audit,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $announcements = array_map(
            $this->present(...),
            $this->notifications->listAnnouncements(),
        );

        return $this->json($response, 200, ['announcements' => $announcements]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $title    = trim((string) ($body['title'] ?? ''));
        $bodyText = trim((string) ($body['body'] ?? ''));
        $linkPath = trim((string) ($body['link_path'] ?? ''));

        /** @var array<string, string> $errors */
        $errors = [];

        $validator = Validation::createValidator();

        foreach ($validator->validate($title, [
            new Assert\NotBlank(message: 'Please enter a title.'),
            new Assert\Length(max: 200, maxMessage: 'The title must be at most {{ limit }} characters long.'),
        ]) as $e) {
            $errors['title'] = (string) $e->getMessage();
            break;
        }

        foreach ($validator->validate($bodyText, [
            new Assert\NotBlank(message: 'Please enter a text.'),
            new Assert\Length(max: 4000, maxMessage: 'The text must be at most {{ limit }} characters long.'),
        ]) as $e) {
            $errors['body'] = (string) $e->getMessage();
            break;
        }

        if ($linkPath !== '' && !str_starts_with($linkPath, '/')) {
            $errors['link_path'] = 'The link must be an internal path (starting with "/").';
        }

        if ($errors !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $errors],
            ]);
        }

        $actorId = $this->actorId($request);
        $id      = $this->notifications->createBroadcast($actorId, $title, $bodyText, $linkPath !== '' ? $linkPath : null);

        $this->audit->log('operator.announcement.created', [
            'actor_tier'       => 'operator',
            'actor_id'         => $actorId,
            'notification_id'  => $id,
        ]);

        return $this->json($response, 201, ['ok' => true, 'id' => $id]);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $row = $id > 0 ? $this->notifications->findById($id) : null;
        if ($row === null || $row['scope'] !== 'broadcast') {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Announcement not found.']]);
        }

        $this->notifications->deleteAnnouncement($id);

        $this->audit->log('operator.announcement.deleted', [
            'actor_tier'      => 'operator',
            'actor_id'        => $this->actorId($request),
            'notification_id' => $id,
        ]);

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
            'title'      => (string) $row['title'],
            'body'       => (string) $row['body'],
            'link_path'  => $row['link_path'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => $row['created_at'],
        ];
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
