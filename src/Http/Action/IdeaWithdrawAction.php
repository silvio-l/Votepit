<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/withdraw — Idee zurückziehen (Hard-Delete).
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: global erzwungen (CsrfMiddleware im POST-Pfad).
 *
 * Ownership-Check in der Action (nicht im Pipeline-Guard):
 *   - Idee nicht im Board → 404
 *   - Idee vorhanden aber anderer Autor → 403
 *   - Anonym → AuthZMiddleware::user() gibt 401 zurück (bevor Action läuft)
 *   - Blockierter Nutzer → BlockCheckMiddleware gibt 403 zurück (bevor Action läuft)
 *
 * Nach Hard-Delete: PRG-Redirect (302) auf die Board-Home (Ideenliste).
 */
final readonly class IdeaWithdrawAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write('Board not found.');

            return $response->withStatus(404);
        }

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');

            return $response->withStatus(404);
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write('Forbidden.');

            return $response->withStatus(403);
        }

        $boardId  = (int) $board['id'];
        $authorId = (int) ($user['id'] ?? 0);

        $this->ideaRepo->withdraw($ideaId, $authorId, $boardId);
        $this->audit->log('idea.withdrawn', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
