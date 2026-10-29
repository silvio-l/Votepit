<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardResultsCsv;
use Votepit\Domain\BoardStatuses;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;

/**
 * GET /admin/boards/{slug}/export — the board's ideas with their results
 * as one CSV download (broaden-use-cases issue 07). All tiers.
 *
 * AuthZ: accountAdmin (route), per-user rate limit `board:export`. The
 * board is resolved account-scoped (findBySlugForAccount — a foreign
 * account's board → 404) before any idea is read; ideas then go through
 * IdeaRepository's shared list WHERE clause, so deleted, pending
 * (pre-moderation) and — unless explicitly filtered — archived ideas never
 * appear. Three statements regardless of idea count (board, ideas, tags).
 *
 * Query parameters (all optional, unknown values fall back to defaults):
 *   status      category filter (allow-list, as on the board list)
 *   status_key  exact status filter (wins over status)
 *   sort        top (default) | newest
 *   lang        de → German status labels and `;` separator; else English + `,`
 *   sep         semicolon | comma — overrides the language default
 *
 * No author identity is exported (see BoardResultsCsv). Audit:
 * board.results.exported (board, actor, row count, filters — no content).
 */
final readonly class BoardResultsExportAction
{
    private const SEPARATORS = ['semicolon' => ';', 'comma' => ','];

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private TagRepository $tagRepo,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $params    = $request->getQueryParams();
        $rawStatus = is_string($params['status'] ?? null) ? $params['status'] : null;
        $status    = $rawStatus !== null && in_array($rawStatus, IdeaRepository::ALLOWED_STATUSES, true) ? $rawStatus : null;
        $statusKey = is_string($params['status_key'] ?? null) ? $params['status_key'] : null;
        $rawSort   = is_string($params['sort'] ?? null) ? $params['sort'] : '';
        $sort      = isset(IdeaRepository::SORT_AXES[$rawSort]) ? $rawSort : 'top';
        $locale    = ($params['lang'] ?? null) === 'de' ? 'de' : 'en';
        $rawSep    = is_string($params['sep'] ?? null) ? $params['sep'] : '';
        $separator = self::SEPARATORS[$rawSep] ?? ($locale === 'de' ? ';' : ',');

        $boardId = (int) $board['id'];
        $ideas   = $this->ideaRepo->listForResultsExport($boardId, $status, $statusKey, $sort, $locale);
        $ids     = array_map(static fn (array $idea): int => (int) $idea['id'], $ideas);
        $csv     = BoardResultsCsv::build($ideas, $this->tagRepo->tagsForIdeas($ids), $separator);

        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $this->audit->log('board.results.exported', [
            'board_id'   => $boardId,
            'actor_id'   => is_array($user) ? (int) ($user['id'] ?? 0) : 0,
            'rows'       => count($ideas),
            'status'     => $status,
            'status_key' => $statusKey !== null && preg_match(BoardStatuses::KEY_PATTERN, $statusKey) === 1 ? $statusKey : null,
            'sort'       => $sort,
        ]);

        $response->getBody()->write($csv);
        $stamp = (new \DateTimeImmutable())->format('Ymd-His');

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="votepit-results-%s-%s.csv"', (string) $board['slug'], $stamp))
            ->withHeader('Cache-Control', 'no-store');
    }
}
