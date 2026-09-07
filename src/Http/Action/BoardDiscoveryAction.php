<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Persistence\BoardRepository;

/**
 * GET /discover — public, cross-tenant listing of `visibility = 'public'`
 * boards, meant to be linked from the marketing landing page ("browse public
 * boards"). Anon and platform-wide (no account scoping — that IS the point
 * of this route, unlike every /admin/* route in this app).
 *
 * Delegates every trust decision to
 * BoardRepository::listPublicDiscovery()/countPublicDiscovery()/
 * spotlightBoards() (the chokepoint): `unlisted`/`private` boards, boards of
 * unconfirmed/locked/deletion-scheduled accounts and operator-locked boards
 * are structurally absent from the underlying queries, not filtered here.
 * This action only clamps pagination input and shapes the response — it
 * never talks to the database directly and never adds a second, parallel
 * visibility check.
 *
 * Response carries the fields the discovery UI needs (slug, name,
 * account_slug, intro, idea_count, vote_count) — no board/account IDs,
 * nothing that isn't already implied by the public board URL itself. `intro`
 * is safe to expose as-is: it's plaintext-only, already validated at write
 * time (CLAUDE.md's shared-origin invariant).
 *
 * `spotlight` (the algorithmic, fully-automatic "5 boards" band shown above
 * the list — 2026-09-04 product decision, no manual/editorial override) is
 * only computed for page 1: it's a once-per-day-rotating band meant to be
 * rendered above the paginated list, not part of the list itself, so later
 * pages skip the extra query.
 */
final readonly class BoardDiscoveryAction
{
    private const DEFAULT_LIMIT     = 24;
    private const MAX_LIMIT         = 100;
    private const SPOTLIGHT_COUNT   = 5;

    public function __construct(
        private BoardRepository $boardRepo,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit']) && ctype_digit((string) $query['limit'])) {
            $limit = max(1, min(self::MAX_LIMIT, (int) $query['limit']));
        }

        $page = 1;
        if (isset($query['page']) && ctype_digit((string) $query['page'])) {
            $page = max(1, (int) $query['page']);
        }

        $offset = ($page - 1) * $limit;

        $boards    = $this->boardRepo->listPublicDiscovery($limit, $offset);
        $total     = $this->boardRepo->countPublicDiscovery();
        $spotlight = $page === 1 ? $this->boardRepo->spotlightBoards(self::SPOTLIGHT_COUNT) : [];

        $response->getBody()->write((string) json_encode([
            'ok'        => true,
            'boards'    => $boards,
            'spotlight' => $spotlight,
            'total'     => $total,
            'page'      => $page,
            'limit'     => $limit,
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
