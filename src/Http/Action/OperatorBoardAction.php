<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;

/**
 * GET  /operator/boards               — platform-wide board list.
 * POST /operator/boards/{id}/lock     — lock ANY board (reversible).
 * POST /operator/boards/{id}/unlock   — unlock ANY board.
 * POST /operator/boards/{id}/delete   — hard-delete ANY board.
 *
 * AuthZ: AuthZMiddleware::operator() — STRICTLY above account-scoping. Every
 * method below operates by board ID, resolved via BoardRepository::findByIdForAccount()
 * bypassed entirely — an operator acts on any tenant's board regardless of
 * ownership, so lookups here are by raw board ID with no account_id filter.
 *
 * lockBoard()/unlockBoard() take effect immediately on
 * BoardRepository::findPublicBySlugForAccount() (the single public-visibility
 * chokepoint) — a locked board stops being publicly reachable the instant this
 * runs, without touching visibility (an owner/plan-controlled tier feature,
 * semantically distinct from an operator override).
 */
final readonly class OperatorBoardAction
{
    public function __construct(
        private BoardRepository $boards,
        private AuditLogger $audit,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $response->getBody()->write((string) json_encode([
            'boards' => $this->boards->listAllForOperator(),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function lock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $boardId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        if ($boardId <= 0 || !is_array($this->boards->findByIdAny($boardId))) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $this->boards->lockBoard($boardId);

        $this->audit->log('operator.board.locked', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'board_id'   => $boardId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function unlock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $boardId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        if ($boardId <= 0 || !is_array($this->boards->findByIdAny($boardId))) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $this->boards->unlockBoard($boardId);

        $this->audit->log('operator.board.unlocked', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'board_id'   => $boardId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $boardId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        if ($boardId <= 0 || !is_array($this->boards->findByIdAny($boardId))) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        $this->boards->deleteBoard($boardId);

        $this->audit->log('operator.board.deleted', [
            'actor_tier' => 'operator',
            'actor_id'   => $this->actorId($request),
            'board_id'   => $boardId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
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
