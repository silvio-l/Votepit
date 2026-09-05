<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * GET /{board}/ideas/search-duplicates?title=... — as-you-type duplicate
 * recall for the idea-submit form.
 *
 * AuthZ: user (in AppFactory) — matches the surrounding submit flow's trust
 * level (the form itself already requires auth). RateLimit `dupsearch:user`:
 * per-action rate limit (attached in AppFactory).
 *
 * Board-scoped via BoardRepository::findBySlugForAccount() (unknown slug
 * → 404) and IdeaRepository::findDuplicateCandidates() (bound to board_id) —
 * a duplicate in a foreign board/account can structurally never appear.
 *
 * Surfacing only — no auto-merge (roadmap's explicit "Not included").
 */
final readonly class IdeaSearchDuplicatesAction
{
    /** Recall pool size fetched from IdeaRepository before reranking. */
    private const RECALL_LIMIT = 30;

    /** Max candidates returned to the client after reranking. */
    private const RESULT_LIMIT = 5;

    /** Below this trimmed length, skip the search entirely (too little signal for as-you-type). */
    private const MIN_QUERY_LENGTH = 3;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private DuplicateDetectionService $duplicateDetection,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $queryParams = $request->getQueryParams();
        $title       = trim((string) ($queryParams['title'] ?? ''));

        if (mb_strlen($title) < self::MIN_QUERY_LENGTH) {
            $response->getBody()->write((string) json_encode(['candidates' => []]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $boardId = (int) $board['id'];
        $recall  = $this->ideaRepo->findDuplicateCandidates($boardId, $title, self::RECALL_LIMIT);
        $ranked  = $this->duplicateDetection->rank($title, $recall, self::RESULT_LIMIT);

        $candidates = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'status'     => (string) $row['status'],
            'up_count'   => (int) ($row['up_count'] ?? 0),
            'down_count' => (int) ($row['down_count'] ?? 0),
            'similarity' => (float) $row['similarity'],
        ], $ranked);

        $response->getBody()->write((string) json_encode(['candidates' => $candidates]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
