<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Support\Clock;

/**
 * Operator worklist (P-06): one account-wide view of the ideas that need a
 * human decision or answer.
 *
 * GET {accountPrefix}/admin/worklist/summary — header counts only: `unanswered`
 * (the weekly digest number, same predicate), `unanswered_over_7_days`,
 * `pending_moderation`, `decision_due`. Optional `board` = slug of a board of
 * this account (foreign or unknown slug → 404, counts are not computed).
 *
 * GET {accountPrefix}/admin/worklist — the same counts plus one page of rows
 * (`ideas`, 50 per page) in one response, so header and rows never disagree.
 * Query: category=all|unanswered|pending_moderation|decision_due (default
 * all), age=all|over_7_days (default all), sort=oldest|votes (default oldest:
 * longest waiting first; votes: most votes first, then oldest), board=<slug>,
 * cursor=<opaque, from next_cursor>. Unknown parameter or value → 422.
 * Rows carry no PII: no author, e-mail, user id, IP or guest token (only
 * `author_type` for pending ideas).
 *
 * POST {accountPrefix}/admin/worklist/ideas/{id}/dismiss and .../undismiss — "Nur ausblenden"
 * (P-06 part B): hide an unanswered or decision-due idea until it gets activity (see
 * IdeaRepository::DISMISSED_IN_FORCE), and undo. Both are account-scoped and both
 * are blocked on a frozen board (423 board_frozen, FrozenBoardGuard, owner rule 11b).
 * A dismissal notifies nobody and does not close voting; it only hides the row.

 * AuthZ: accountModerate (owner|admin|moderator), registered in
 * Http\Routes\WorklistRoutes. Account scoping: the account id comes from the
 * request context, every query binds it through the boards join.
 */
final readonly class WorklistAction
{
    private const CATEGORIES = ['all', 'unanswered', 'pending_moderation', 'decision_due'];

    private const AGES = ['all', 'over_7_days'];

    private const SORTS = ['oldest', 'votes'];

    private const PARAMETERS = ['category', 'board', 'age', 'sort', 'cursor'];

    private const CURSOR_TIMESTAMP = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private UserRepository $userRepo,
        private Clock $clock,
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $unknown = array_diff(array_keys($params), self::PARAMETERS);
        if ($unknown !== []) {
            return JsonError::respond($response, 422, 'unknown_parameter', 'Unknown query parameter.', ['parameters' => array_values($unknown)]);
        }

        $category = $this->choice($params, 'category', self::CATEGORIES, 'all');
        $age      = $this->choice($params, 'age', self::AGES, 'all');
        $sort     = $this->choice($params, 'sort', self::SORTS, 'oldest');
        if ($category === null || $age === null || $sort === null) {
            return JsonError::respond($response, 422, 'invalid_filter', 'Unknown category, age or sort value.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board = $this->resolveBoard($params, $accountId);
        if ($board === false) {
            return JsonError::respond($response, 422, 'invalid_board', 'Board must be a board slug.');
        }
        if ($board === null && array_key_exists('board', $params)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $after = null;
        if (array_key_exists('cursor', $params)) {
            $after = $this->decodeCursor($params['cursor'], $sort);
            if ($after === null) {
                return JsonError::respond($response, 422, 'invalid_cursor', 'Cursor is not valid for this list.');
            }
        }

        $now     = $this->clock->now();
        $boardId = $board['id'] ?? null;
        $counts  = $this->ideaRepo->worklistCounts($accountId, $boardId, $now);
        $rows    = $this->ideaRepo->worklistRows($accountId, $boardId, $category, $age === 'over_7_days', $sort, $after, $now, IdeaRepository::WORKLIST_PAGE_SIZE + 1);

        $hasMore = count($rows) > IdeaRepository::WORKLIST_PAGE_SIZE;
        $rows    = array_slice($rows, 0, IdeaRepository::WORKLIST_PAGE_SIZE);

        $guestId = null;
        foreach ($rows as $row) {
            if ((string) $row['moderation_state'] === 'pending') {
                $guestId = $this->userRepo->guestPlaceholderId();
                break;
            }
        }

        $ideas = array_map(static fn (array $row): array => self::row($row, $now, $guestId), $rows);

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = $this->encodeCursor($sort, (string) $last['created_at'], (int) $last['id'], (int) $last['up_count'] + (int) $last['down_count']);
        }

        return $this->json($response, 200, [
            'counts'      => $counts,
            'board'       => is_array($board) ? $board['slug'] : null,
            'ideas'       => $ideas,
            'next_cursor' => $nextCursor,
        ]);
    }

    public function summary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $unknown = array_diff(array_keys($params), ['board']);
        if ($unknown !== []) {
            return JsonError::respond($response, 422, 'unknown_parameter', 'Unknown query parameter.', ['parameters' => array_values($unknown)]);
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board = $this->resolveBoard($params, $accountId);
        if ($board === false) {
            return JsonError::respond($response, 422, 'invalid_board', 'Board must be a board slug.');
        }
        if ($board === null && array_key_exists('board', $params)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $counts = $this->ideaRepo->worklistCounts($accountId, $board['id'] ?? null, $this->clock->now());

        return $this->json($response, 200, ['counts' => $counts, 'board' => is_array($board) ? $board['slug'] : null]);
    }

    /**
     * POST {accountPrefix}/admin/worklist/ideas/{id}/dismiss — hides an idea
     * that needs no reaction (P-06 part B). 404 for a foreign or unknown idea,
     * 422 for an idea that is not waiting or due (pending ideas are approved or
     * rejected instead). No body, no audit entry: the change is reversible.
     *
     * @param array<string, string> $args route parameters (id)
     */
    public function dismiss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $ideaId    = (int) ($args['id'] ?? 0);

        $board = $this->ideaRepo->worklistIdeaBoard($accountId, $ideaId);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $result = $this->ideaRepo->dismissWorklistIdea($accountId, $ideaId, $this->clock->now());
        if ($result === 'not_found') {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }
        if ($result === 'not_dismissable') {
            return JsonError::respond($response, 422, 'not_dismissable', 'Only ideas that wait for an answer or a decision can be hidden. Approve or reject pending ideas instead.');
        }

        return $this->json($response, 200, ['ok' => true, 'idea_id' => $ideaId, 'dismissed' => true]);
    }

    /**
     * POST {accountPrefix}/admin/worklist/ideas/{id}/undismiss — undo of dismiss.
     * 404 for a foreign or unknown idea; a missing dismissal is not an error.
     *
     * @param array<string, string> $args route parameters (id)
     */
    public function undismiss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $ideaId    = (int) ($args['id'] ?? 0);

        $board = $this->ideaRepo->worklistIdeaBoard($accountId, $ideaId);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        if ($this->ideaRepo->restoreWorklistIdea($accountId, $ideaId) === 'not_found') {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        return $this->json($response, 200, ['ok' => true, 'idea_id' => $ideaId, 'dismissed' => false]);
    }

    /**
     * The optional `board` filter (scoped to the account, so a foreign board
     * is "not found"): null when the parameter is absent or the slug is
     * unknown (the caller tells the two apart by the parameter's presence),
     * false when the value is not a non-empty string, the board otherwise.
     *
     * @param array<string, mixed> $params
     * @return array{id: int, slug: string}|false|null
     */
    private function resolveBoard(array $params, int $accountId): array|false|null
    {
        if (!array_key_exists('board', $params)) {
            return null;
        }
        $slug = $params['board'];
        if (!is_string($slug) || $slug === '') {
            return false;
        }
        $board = $this->boardRepo->findBySlugForAccount($slug, $accountId);

        return $board === null ? null : ['id' => (int) $board['id'], 'slug' => $slug];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $allowed
     */
    private function choice(array $params, string $key, array $allowed, string $default): ?string
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }
        $value = $params[$key];

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row, \DateTimeImmutable $now, ?int $guestId): array
    {
        $up      = (int) $row['up_count'];
        $down    = (int) $row['down_count'];
        $pending = (string) $row['moderation_state'] === 'pending';
        $created = new \DateTimeImmutable((string) $row['created_at']);

        return [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'board_slug'       => (string) $row['board_slug'],
            'board_name'       => (string) $row['board_name'],
            'status'           => (string) $row['status_key'],
            // The board's own status row (LEFT JOIN, board_statuses): category, system flag and owner labels.
            'status_category'  => (string) $row['status_category'],
            'status_is_system' => (int) $row['status_is_system'] === 1,
            'status_label_de'  => $row['status_label_de'] === null ? null : (string) $row['status_label_de'],
            'status_label_en'  => $row['status_label_en'] === null ? null : (string) $row['status_label_en'],
            'moderation_state' => (string) $row['moderation_state'],
            'up_count'         => $up,
            'down_count'       => $down,
            'voter_count'      => $up + $down,
            'created_at'       => $created->format('Y-m-d H:i:s'),
            'age_days'         => max(0, intdiv($now->getTimestamp() - $created->getTimestamp(), 86400)),
            'categories'       => [
                'unanswered'         => (int) $row['flag_unanswered'] === 1,
                'pending_moderation' => (int) $row['flag_pending_moderation'] === 1,
                'decision_due'       => (int) $row['flag_decision_due'] === 1,
            ],
            'author_type'      => $pending ? ((int) $row['author_id'] === $guestId ? 'guest' : 'member') : null,
            // A frozen board is read-only: the SPA hides the row's write actions (FrozenBoardGuard).
            'board_frozen'     => FrozenBoardGuard::isFrozen($row),
        ];
    }

    /**
     * Opaque cursor: base64url of the sort key and the position of the last
     * row (created_at, id, and the vote total for sort=votes).
     */
    private function encodeCursor(string $sort, string $createdAt, int $id, int $votes): string
    {
        $payload = ['s' => $sort, 'c' => $createdAt, 'i' => $id, 'v' => $sort === 'votes' ? $votes : null];

        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * @return array{created_at: string, id: int, votes: int|null}|null null when the cursor is malformed or for another sort
     */
    private function decodeCursor(mixed $raw, string $sort): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $padded  = strtr($raw, '-_', '+/') . str_repeat('=', (4 - strlen($raw) % 4) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || ($data['s'] ?? null) !== $sort) {
            return null;
        }
        $createdAt = $data['c'] ?? null;
        $id        = $data['i'] ?? null;
        if (!is_string($createdAt) || preg_match(self::CURSOR_TIMESTAMP, $createdAt) !== 1 || !is_int($id) || $id < 1) {
            return null;
        }
        $votes = null;
        if ($sort === 'votes') {
            $votes = $data['v'] ?? null;
            if (!is_int($votes) || $votes < 0) {
                return null;
            }
        }

        return ['created_at' => $createdAt, 'id' => $id, 'votes' => $votes];
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
