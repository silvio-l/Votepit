<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\BoardStatuses;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\IdeaSearchQuery;
use Votepit\Domain\Premoderation;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\ApiTokenScope;
use Votepit\Support\Clock;
use Votepit\Webhook\WebhookDispatcher;

/**
 * GET  /api/v1/ideas          — idea list (Agent API).
 * GET  /api/v1/ideas/{id}     — idea detail.
 * POST /api/v1/ideas          — create idea (per ADR: the only board-scoped
 *                                write endpoint of the Agent API, bot/agent idea
 *                                submission is the obvious use case).
 *
 * AuthZ: Bearer token (ApiTokenAuthMiddleware) — no slug/board in the path, the
 * board comes exclusively from the resolved token scope. Reads/writes the
 * same repository methods as the session paths (IdeaRepository), so no
 * query logic is duplicated — only the AuthZ gate differs.
 *
 * POST deliberately differs from IdeaCreateAction: no honeypot/time-trap
 * (those are browser-form defenses against automated bots — an
 * Agent-API call IS the intended "bot", authorized via a capability
 * instead of being disguised). The content filter (ContentModerationService)
 * runs unchanged — it protects the board content, not form integrity.
 * The author of every idea created via token is the token's created_by_user_id
 * (the admin who issued the token) — there is (still) no separate
 * "bot identity" for authored content (votes use one, see ApiIdeaWriteAction).
 *
 * Pre-moderation + publish side effects (competitor-parity issue 15): an idea
 * created with a `write` grant follows the board's pre-moderation rule like
 * any non-moderating member's idea — on a board with premoderation != 'off'
 * it is stored as pending (moderators get the aggregated notice, the
 * response carries `moderation_state: "pending"`, no webhook). Only the
 * explicit `moderate` grant publishes immediately, like a moderator's own
 * idea — and a `write` grant whose issuing user is a trusted author of the
 * account (broaden-use-cases issue 11), like that author's own idea. A
 * published idea fires the same `idea.created` webhook (and with it
 * the chat fan-out) as IdeaCreateAction. A frozen board rejects the write
 * (FrozenBoardGuard, 423) exactly like the session path.
 *
 * Reads: published ideas only, like every other read path. A token with the
 * `moderate` grant may additionally read pending ideas — `GET
 * /api/v1/ideas?moderation_state=pending` (the approval queue) and the
 * detail of a pending id (then carrying `moderation_state`). `q` is the
 * board search (IdeaSearchQuery, issue 09).
 *
 * resolveList()/resolveDetail()/submit() are pure domain methods without
 * HTTP framing — shared with the MCP resource wrapper
 * (`McpAction`), so validation/moderation/sort logic isn't
 * duplicated between REST and MCP. list()/detail()/create() remain the
 * HTTP handlers and only translate request → call → response.
 */
final readonly class ApiIdeaAction
{
    public function __construct(
        private IdeaRepository $ideaRepo,
        private BoardRepository $boardRepo,
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private Clock $clock,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
        private ?WebhookDispatcher $webhookDispatcher = null,
        private ?PremoderationNotifier $premoderationNotifier = null,
        private ?UserRepository $userRepo = null,
        private ?AccountMemberStandingRepository $standingRepo = null,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $boardId = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $params  = $request->getQueryParams();

        if (self::wantsPending($params)) {
            if (!self::canModerate($request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN))) {
                return self::insufficientScope($response, 'Reading pending ideas requires the moderate grant.');
            }
            $response->getBody()->write((string) json_encode($this->resolvePending($boardId)));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->resolveList($boardId, $params);

        $response->getBody()->write((string) json_encode($result));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $params */
    public static function wantsPending(array $params): bool
    {
        return ($params['moderation_state'] ?? null) === Premoderation::STATE_PENDING;
    }

    /** Whether the resolved token grant (ATTR_TOKEN shape) carries the moderate level. */
    public static function canModerate(mixed $token): bool
    {
        return is_array($token) && is_string($token['scope'] ?? null) && ApiTokenScope::allowsModerate($token['scope']);
    }

    /**
     * The board's approval queue (moderate grant only, checked by the
     * caller) — the same capped, oldest-first list the moderator UI shows.
     *
     * @return array{ideas: list<array<string, mixed>>, moderation_state: string}
     */
    public function resolvePending(int $boardId): array
    {
        $ideas = array_map(static function (array $row): array {
            $row['moderation_state'] = Premoderation::STATE_PENDING;
            return $row;
        }, $this->withAuthorType($this->ideaRepo->listPendingByBoard($boardId)));

        return ['ideas' => $ideas, 'moderation_state' => Premoderation::STATE_PENDING];
    }

    /**
     * Pure list resolution (no HTTP framing) — used by both list() and the
     * MCP `list_ideas` tool. $params can come from either query
     * parameters (REST, everything as string) or MCP tool arguments
     * (JSON, possibly already typed).
     *
     * @param array<string, mixed> $params
     * `status` filters by CATEGORY (canonical slug, matches every status of
     * that category, broaden-use-cases issue 03); `status_key` filters one
     * exact status key and wins over `status`.
     *
     * @return array{ideas: list<array<string, mixed>>, active_status: ?string, active_status_key: ?string, active_sort: string, page: int, total_pages: int}
     */
    public function resolveList(int $boardId, array $params): array
    {
        $rawStatus    = is_string($params['status'] ?? null) ? $params['status'] : null;
        $activeStatus = ($rawStatus !== null && in_array($rawStatus, IdeaRepository::ALLOWED_STATUSES, true))
            ? $rawStatus
            : null;

        $rawStatusKey    = is_string($params['status_key'] ?? null) ? $params['status_key'] : null;
        $activeStatusKey = $rawStatusKey !== null && preg_match(BoardStatuses::KEY_PATTERN, $rawStatusKey) === 1 ? $rawStatusKey : null;
        if ($activeStatusKey !== null) {
            $activeStatus = null;
        }

        $rawPage = isset($params['page']) ? (int) $params['page'] : 1;
        $page    = max(1, $rawPage);
        $limit   = IdeaRepository::DEFAULT_PAGE_SIZE;
        $offset  = ($page - 1) * $limit;

        $rawSort    = is_string($params['sort'] ?? null) ? $params['sort'] : IdeaRepository::DEFAULT_SORT;
        $activeSort = array_key_exists($rawSort, IdeaRepository::SORT_AXES) ? $rawSort : IdeaRepository::DEFAULT_SORT;

        // Board search (issue 09): term-less input (only punctuation/
        // wildcards) or a non-string → null = no search.
        $search = IdeaSearchQuery::fromRaw(is_string($params['q'] ?? null) ? $params['q'] : null);

        $ideas = $this->ideaRepo->listByBoard($boardId, $activeStatus, $limit, $offset, $activeSort, null, $search, $activeStatusKey);

        $totalPages = 1;
        if (count($ideas) === $limit || $page > 1) {
            $total      = $this->ideaRepo->countListed($boardId, $activeStatus, $search, $activeStatusKey);
            $totalPages = max(1, (int) ceil($total / $limit));
        }

        return [
            'ideas'         => $this->withAuthorType($ideas),
            'active_status' => $activeStatus,
            'active_status_key' => $activeStatusKey,
            'active_sort'   => $activeSort,
            'active_query'  => $search?->text(),
            'page'          => $page,
            'total_pages'   => $totalPages,
        ];
    }

    /** @param array<string, mixed> $args */
    public function detail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $boardId = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $id      = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        $idea = $this->resolveDetail($boardId, $id, self::canModerate($request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN)));
        if ($idea === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode(['idea' => $idea]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Pure detail resolution (no HTTP framing) — used by both detail() and
     * the MCP `get_idea` tool. $includePending (moderate grant only) also
     * resolves a pending idea; the row then carries `moderation_state`.
     *
     * @return array<string, mixed>|null
     */
    public function resolveDetail(int $boardId, int $id, bool $includePending = false): ?array
    {
        $idea = $id > 0 ? $this->ideaRepo->findInBoard($boardId, $id, includePending: $includePending) : null;
        return is_array($idea) ? $this->withAuthorType([$idea])[0] : null;
    }

    /**
     * `author_type` (`guest`|`member`, ADR 0006 §1) on every idea row the
     * API/MCP returns — output only. The device hash is never selected by
     * these reads, so it cannot leak here.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withAuthorType(array $rows): array
    {
        $guestId = $rows !== [] ? $this->userRepo?->guestPlaceholderId() : null;

        return array_map(static function (array $row) use ($guestId): array {
            $row['author_type'] = $guestId !== null && (int) ($row['author_id'] ?? 0) === $guestId ? 'guest' : 'member';
            return $row;
        }, $rows);
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        /** @var array{token_id: int, account_id: int, scope: string, created_by_user_id: int, label: string} $token */
        $token = $request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);

        if (!ApiTokenScope::allowsWrite($token['scope'])) {
            return self::insufficientScope($response, 'This token is read-only.');
        }

        $boardId = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);

        $parsed   = $request->getParsedBody();
        $rawTitle = is_array($parsed) && is_scalar($parsed['title'] ?? null) ? trim((string) $parsed['title']) : '';
        $rawBody  = is_array($parsed) && is_scalar($parsed['body'] ?? null) ? trim((string) $parsed['body']) : '';

        $result = $this->submit($boardId, $token, $rawTitle, $rawBody, 'api_token');

        if (isset($result['error'])) {
            $response->getBody()->write((string) json_encode(['error' => $result['error']]));
            return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode(['ok' => true] + $result));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public static function insufficientScope(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'insufficient_scope', 'message' => $message],
        ]));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Pure idea creation (validation + moderation + persistence + audit log
     * + pre-moderation + publish webhook, no HTTP framing) — used by both
     * create() and the MCP `create_idea` tool. $token is the resolved grant
     * (ApiTokenAuthMiddleware::ATTR_TOKEN shape, write scope already
     * checked by the caller); the author is its issuing user. $via marks
     * the audit-log origin ("api_token" REST vs. "mcp"), the
     * authorization/moderation logic is identical.
     *
     * Optional submission window (broaden-use-cases issue 04, migration
     * 0082): Agent-API tokens are BOUND by it like members (423
     * submissions_closed / submissions_not_open_yet), even when the token was
     * issued by an owner/admin or carries the moderate grant — fail-secure
     * default: an automated client must not keep filling a board whose owner
     * closed submissions. Only the interactive owner/admin session path
     * (IdeaCreateAction) is exempt.
     *
     * @param array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string} $token
     * @return array{id: int, moderation_state?: string}|array{error: array{key: string, message: string, fields: array<string, string>}, status: int}
     */
    public function submit(int $boardId, array $token, string $rawTitle, string $rawBody, string $via = 'api_token'): array
    {
        $accountId = $token['account_id'];
        $authorId  = $token['created_by_user_id'];
        $tokenId   = $token['token_id'];

        // Structurally unreachable for a live grant (the middleware resolved
        // this board id from the token) — fail-secure instead of a 500.
        $board = $this->boardRepo->findByIdForAccount($boardId, $accountId);
        if (!is_array($board)) {
            return ['error' => ['key' => 'not_found', 'message' => 'Board not found.', 'fields' => []], 'status' => 404];
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return ['error' => ['key' => 'board_frozen', 'message' => 'This board is frozen (read-only) and cannot be edited at the moment.', 'fields' => []], 'status' => 423];
        }

        $submissionRejection = BoardPhases::fromBoard($board, $this->clock->now())->submissionRejection();
        if ($submissionRejection !== null) {
            return [
                'error' => [
                    'key'     => $submissionRejection,
                    'message' => $submissionRejection === 'submissions_closed'
                        ? 'Submitting ideas on this board has closed.'
                        : 'Submitting ideas on this board has not opened yet.',
                    'fields'  => [],
                ],
                'status' => 423,
            ];
        }

        $validator   = Validation::createValidator();
        $titleErrors = $validator->validate($rawTitle, [
            new Assert\NotBlank(message: 'The title must not be empty.'),
            new Assert\Length(
                min: 3,
                max: 200,
                minMessage: 'The title must be at least {{ limit }} characters long.',
                maxMessage: 'The title must be at most {{ limit }} characters long.',
            ),
        ]);
        $bodyErrors = $validator->validate($rawBody, [
            new Assert\NotBlank(message: 'The description must not be empty.'),
            new Assert\Length(
                min: 10,
                minMessage: 'The description must be at least {{ limit }} characters long.',
            ),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($titleErrors as $e) {
            $fields['title'] = (string) $e->getMessage();
            break;
        }
        foreach ($bodyErrors as $e) {
            $fields['body'] = (string) $e->getMessage();
            break;
        }

        if ($fields !== []) {
            return [
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $fields,
                ],
                'status' => 422,
            ];
        }

        $moderationEnabled = !$this->moderationConfigRepo instanceof ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawTitle, $rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            $this->audit->log('idea.moderation_blocked', [
                'board_id'  => $boardId,
                'hit_count' => count($modResult['hits']),
                'via'       => $via,
            ]);

            return [
                'error' => [
                    'key'     => 'moderation_blocked',
                    'message' => 'Your text contains disallowed terms. Please rephrase it.',
                    'fields'  => [],
                ],
                'status' => 422,
            ];
        }

        $titleNormalized = $this->normalizer->normalize($rawTitle);

        // Pre-moderation (see class doc): the grant level decides, not the
        // issuing user's own role — `write` acts as a regular member, and
        // like a member it skips approval when its author is trusted in this
        // account (issue 11). The standing lookup only runs when it can
        // change the outcome; without the repository nobody is trusted.
        $mode          = Premoderation::normalizeMode($board['premoderation'] ?? null);
        $moderateGrant = ApiTokenScope::allowsModerate($token['scope']);
        $trusted       = !$moderateGrant && $mode !== Premoderation::MODE_OFF
            && Premoderation::isTrusted($this->standingRepo?->findFor($accountId, $authorId));
        $pending       = Premoderation::requiresApproval($mode, $moderateGrant ? 'moderator' : null, trusted: $trusted);

        $ideaId = $this->ideaRepo->create(
            $boardId,
            $authorId,
            $rawTitle,
            $titleNormalized,
            $rawBody,
            $pending ? Premoderation::STATE_PENDING : Premoderation::STATE_PUBLISHED,
        );

        $slug = (string) $board['slug'];

        if ($pending) {
            $this->audit->log('idea.created', [
                'board_id'         => $boardId,
                'idea_id'          => $ideaId,
                'via'              => $via,
                'token_id'         => $tokenId,
                'moderation_state' => Premoderation::STATE_PENDING,
            ]);
            $this->premoderationNotifier?->notifyModerators($accountId, $slug, (string) $board['name'], $authorId);

            return ['id' => $ideaId, 'moderation_state' => Premoderation::STATE_PENDING];
        }

        $this->audit->log('idea.created', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'via'      => $via,
            'token_id' => $tokenId,
        ]);

        // Same publish side effect as IdeaCreateAction (webhook + chat
        // fan-out, best-effort and deferred, no PII).
        $this->webhookDispatcher?->dispatchAsync($boardId, $slug, 'idea.created', [
            'idea_id' => $ideaId,
            'title'   => $rawTitle,
            'status'  => 'open',
        ]);

        return ['id' => $ideaId];
    }
}
