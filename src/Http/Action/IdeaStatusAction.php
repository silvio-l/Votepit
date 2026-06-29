<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\StatusService;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * POST /{board}/ideas/{id}/status — Idea-Status setzen (Admin-only).
 *
 * AuthZ: admin (via AuthZMiddleware::admin() in AppFactory; anon → 401, non-admin → 403).
 * CSRF: global erzwungen (CsrfMiddleware im POST-Pfad).
 * BlockCheck: global (gesperrter Nutzer → 403, bevor die Action läuft).
 * RateLimit: perAction('idea:status') in AppFactory.
 *
 * Board-Scoping strukturell: Idee wird board-scoped via findInBoard() geladen —
 * unbekannter Slug oder Idee außerhalb des Boards → 404 (Cross-Board-Leak aus,
 * keine Status-Zeile entsteht).
 *
 * Eingabe `status` ∈ StatusService::VALID_STATUSES; ungültige Werte oder
 * nicht erlaubter Übergang → 422, Idee unverändert.
 *
 * Selbst→Selbst ist idempotenter No-op: 200, kein DB-Schreibvorgang,
 * kein Audit-Eintrag.
 *
 * Antwortet immer JSON { ok: true, status: string } (Status 200).
 */
final readonly class IdeaStatusAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private StatusService $statusService,
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
        // --- Board-Lookup (board-scoped, unbekannter Slug → 404) ---
        $slug  = is_string($args['board'] ?? null) ? $args['board'] : '';
        $board = $this->boardRepo->findBySlug($slug);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board nicht gefunden.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        // --- Idee board-scoped laden (fremde Idee → 404, kein Cross-Board-Leak) ---
        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idee nicht gefunden.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // --- Zielstatus aus Body lesen und validieren ---
        $parsed = $request->getParsedBody();
        $to     = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';

        if (!$this->statusService->isValidStatus($to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_status', 'message' => 'Invalid status.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $from = (string) ($idea['status'] ?? 'open');

        // --- Selbst→Selbst: idempotenter No-op (kein DB-Write, kein Audit) ---
        if ($from === $to) {
            $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        // --- Übergang prüfen ---
        if (!$this->statusService->canTransition($from, $to)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_transition', 'message' => 'Invalid transition.'],
            ]));

            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // --- Status persistieren (board-scoped Prepared-Statement, ADR-5-Invariante) ---
        $this->ideaRepo->updateStatus($boardId, $ideaId, $to);

        // --- Maskiertes Audit: board, idea, from→to, actor-ID — kein PII ---
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('idea.status.changed', [
            'board_id'    => $boardId,
            'idea_id'     => $ideaId,
            'status_from' => $from,
            'status_to'   => $to,
            'actor_id'    => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'status' => $to]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
