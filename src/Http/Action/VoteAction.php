<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;

/**
 * POST /{board}/ideas/{id}/vote — Stimme abgeben / ändern / zurücknehmen.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory; anon → 401).
 * CSRF: global erzwungen (CsrfMiddleware im POST-Pfad).
 * BlockCheck: global (gesperrter Nutzer → 403, bevor die Action läuft).
 * RateLimit: perAction('idea:vote') in AppFactory.
 *
 * Board-Scoping strukturell: Idee wird board-scoped via findInBoard() geladen —
 * unbekannter Slug oder Idee außerhalb des Boards → 404 (Cross-Board-Leak aus,
 * keine Vote-Zeile entsteht).
 *
 * Eingabe `value` ∈ {up,down} (bzw. +1/-1); andere Werte → 422, keine Mutation.
 *
 * Antwortet immer JSON { score, my_vote, up_count, down_count }, Status 200.
 * Durchläuft dieselbe Middleware-Pipeline (AuthZ, CSRF, BlockCheck, RateLimit).
 */
final readonly class VoteAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
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

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        $idea    = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write('Idea not found.');

            return $response->withStatus(404);
        }

        $value = $this->parseValue($request);
        if ($value === null) {
            $response->getBody()->write('Invalid vote value.');

            return $response->withStatus(422);
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        $result = $this->voteRepo->cast($boardId, $ideaId, $userId, $value);

        // Maskiertes Audit: Board-/Idee-ID, Richtung, Resultat — kein PII.
        $this->audit->log('vote.cast', [
            'board_id'  => $boardId,
            'idea_id'   => $ideaId,
            'direction' => $value > 0 ? 'up' : 'down',
            'result'    => $result['my_vote'],
        ]);

        $json = (string) json_encode([
            'score'      => $result['score'],
            'my_vote'    => $result['my_vote'],
            'up_count'   => $result['up_count'],
            'down_count' => $result['down_count'],
        ]);
        $response->getBody()->write($json);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Validiert das `value`-Feld strikt: nur {up,+1,1} → 1, {down,-1} → -1.
     * Alles andere → null (Action antwortet mit 422, keine Mutation).
     */
    private function parseValue(ServerRequestInterface $request): ?int
    {
        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) ? (string) ($parsed['value'] ?? '') : '';

        return match ($raw) {
            'up', '1', '+1' => 1,
            'down', '-1'    => -1,
            default         => null,
        };
    }
}
