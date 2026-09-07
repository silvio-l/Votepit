<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;

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
 * "bot identity" (deliberately deferred to a later step).
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
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $boardId = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $result  = $this->resolveList($boardId, $request->getQueryParams());

        $response->getBody()->write((string) json_encode($result));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Pure list resolution (no HTTP framing) — used by both list() and the
     * MCP `list_ideas` tool. $params can come from either query
     * parameters (REST, everything as string) or MCP tool arguments
     * (JSON, possibly already typed).
     *
     * @param array<string, mixed> $params
     * @return array{ideas: list<array<string, mixed>>, active_status: ?string, active_sort: string, page: int, total_pages: int}
     */
    public function resolveList(int $boardId, array $params): array
    {
        $rawStatus    = is_string($params['status'] ?? null) ? $params['status'] : null;
        $activeStatus = ($rawStatus !== null && in_array($rawStatus, IdeaRepository::ALLOWED_STATUSES, true))
            ? $rawStatus
            : null;

        $rawPage = isset($params['page']) ? (int) $params['page'] : 1;
        $page    = max(1, $rawPage);
        $limit   = IdeaRepository::DEFAULT_PAGE_SIZE;
        $offset  = ($page - 1) * $limit;

        $rawSort    = is_string($params['sort'] ?? null) ? $params['sort'] : IdeaRepository::DEFAULT_SORT;
        $activeSort = array_key_exists($rawSort, IdeaRepository::SORT_AXES) ? $rawSort : IdeaRepository::DEFAULT_SORT;

        $ideas = $this->ideaRepo->listByBoard($boardId, $activeStatus, $limit, $offset, $activeSort);

        $totalPages = 1;
        if (count($ideas) === $limit || $page > 1) {
            $total      = $this->ideaRepo->countByBoard($boardId, $activeStatus);
            $totalPages = max(1, (int) ceil($total / $limit));
        }

        return [
            'ideas'         => $ideas,
            'active_status' => $activeStatus,
            'active_sort'   => $activeSort,
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

        $idea = $this->resolveDetail($boardId, $id);
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
     * the MCP `get_idea` tool.
     *
     * @return array<string, mixed>|null
     */
    public function resolveDetail(int $boardId, int $id): ?array
    {
        $idea = $id > 0 ? $this->ideaRepo->findInBoard($boardId, $id) : null;
        return is_array($idea) ? $idea : null;
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        /** @var array{token_id: int, account_id: int, scope: string, created_by_user_id: int, label: string} $token */
        $token = $request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);

        if ($token['scope'] !== 'write') {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'insufficient_scope', 'message' => 'This token is read-only.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $boardId  = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $authorId = $token['created_by_user_id'];

        $parsed   = $request->getParsedBody();
        $rawTitle = is_array($parsed) ? trim((string) ($parsed['title'] ?? '')) : '';
        $rawBody  = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';

        $result = $this->submit($boardId, $authorId, $rawTitle, $rawBody, 'api_token', $token['token_id']);

        if (isset($result['error'])) {
            $response->getBody()->write((string) json_encode(['error' => $result['error']]));
            return $response->withStatus($result['status'])->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $result['id']]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Pure idea creation (validation + moderation + persistence + audit log,
     * no HTTP framing) — used by both create() and the MCP `create_idea`
     * tool. $via marks the audit-log origin
     * ("api_token" REST vs. "mcp"), the authorization/moderation logic is
     * identical.
     *
     * @return array{id: int}|array{error: array{key: string, message: string, fields: array<string, string>}, status: int}
     */
    public function submit(int $boardId, int $authorId, string $rawTitle, string $rawBody, string $via = 'api_token', ?int $tokenId = null): array
    {
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
        $ideaId          = $this->ideaRepo->create($boardId, $authorId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.created', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'via'      => $via,
            'token_id' => $tokenId,
        ]);

        return ['id' => $ideaId];
    }
}
