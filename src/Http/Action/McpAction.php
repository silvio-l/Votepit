<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\ApiTokenScope;
use Votepit\Security\RateLimiter;

/**
 * POST /api/v1/mcp — MCP (Model Context Protocol) resource wrapper for the
 * Agent API (Votepit MCP).
 *
 * Hand-rolled minimal JSON-RPC 2.0 server (no MCP SDK dependency — the
 * protocol surface exposed here is small and well-defined: `initialize`,
 * `tools/list`, `tools/call`, and `ping`). Same Bearer-token trust boundary
 * as the REST endpoints (`ApiTokenAuthMiddleware`, applied at the route
 * level exactly like `/api/v1/*`) — a token can structurally never reach a
 * different board via MCP than via REST, because both paths resolve the
 * board exclusively from `ApiTokenAuthMiddleware::ATTR_BOARD_ID`.
 *
 * Exposes one MCP tool per REST endpoint (see TOOL_NAMES). Deliberately a
 * thin adapter: read tools call `ApiBoardAction::resolveBoard()` /
 * `ApiIdeaAction::resolveList()` / `resolveDetail()` / `resolvePending()`,
 * `create_idea` calls `ApiIdeaAction::submit()`, and the idea write tools
 * (vote, comment, status, tags, merge — competitor-parity issue 15) invoke
 * the very same `ApiIdeaWriteAction` handlers the REST routes use, with the
 * tool arguments as the parsed body — so no query/validation/moderation/
 * grant logic is duplicated between REST and MCP, and the tool result is
 * the REST response body (`isError` for any HTTP status >= 400).
 *
 * Rate limiting mirrors the REST discipline with the same buckets, keyed by
 * token_id: the whole endpoint sits behind the route-level `apitoken:read`
 * bucket (`RateLimitMiddleware`, registered in `ApiV1Routes`, covers
 * discovery calls and every tool), and every write tool (WRITE_TOOLS)
 * additionally spends from the `apitoken:write` bucket here — a single
 * JSON-RPC endpoint can't be split per-method at the route level, so the
 * write bucket is checked directly via `RateLimiter::hit()` using the
 * identical bucket key format ("apitoken:write:<token_id>") the REST write
 * routes use, so a token shares one write budget across REST and MCP.
 *
 * Transport: every JSON-RPC response (success or protocol error) is
 * returned as HTTP 200 with a JSON-RPC envelope in the body — malformed
 * input (parse error, invalid request, unknown method/tool) never produces
 * a 500, it produces a JSON-RPC error object (fail-secure discipline
 * applies to AuthN via the middleware, not to protocol framing).
 *
 * Not implemented (deliberately out of scope for this minimal server):
 * JSON-RPC batch requests (removed from the current MCP spec's streamable
 * HTTP transport), `resources/*` (the tools already cover every capability;
 * a resources surface would just be a second name for the read tools), and
 * SSE/streaming (single request/response per call is sufficient).
 */
final readonly class McpAction
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /** @var list<string> */
    private const TOOL_NAMES = [
        'get_board', 'list_ideas', 'search_ideas', 'get_idea', 'create_idea',
        'vote_idea', 'comment_on_idea', 'set_idea_status', 'add_idea_tag', 'remove_idea_tag', 'merge_ideas', 'unmerge_idea',
    ];

    /** Tools that spend from the shared `apitoken:write` budget. */
    private const WRITE_TOOLS = [
        'create_idea', 'vote_idea', 'comment_on_idea', 'set_idea_status', 'add_idea_tag', 'remove_idea_tag', 'merge_ideas', 'unmerge_idea',
    ];

    public function __construct(
        private ApiBoardAction $boardAction,
        private ApiIdeaAction $ideaAction,
        private ApiIdeaWriteAction $writeAction,
        private ResponseFactoryInterface $responseFactory,
        private RateLimiter $rateLimiter,
        private int $writeLimit,
        private int $writeWindow,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $decoded = json_decode((string) $request->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->rpcError($response, null, -32700, 'Parse error');
        }

        // Batch requests (a JSON array of request objects) are not supported
        // by this minimal server — see class doc.
        if (array_is_list($decoded)) {
            return $this->rpcError($response, null, -32600, 'Batch requests are not supported');
        }

        $id     = $decoded['id'] ?? null;
        $method = $decoded['method'] ?? null;
        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        if (($decoded['jsonrpc'] ?? null) !== '2.0' || !is_string($method) || $method === '') {
            return $this->rpcError($response, $id, -32600, 'Invalid Request');
        }

        return match ($method) {
            'initialize' => $this->rpcResult($response, $id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities'    => ['tools' => new \stdClass()],
                'serverInfo'      => ['name' => 'votepit-mcp', 'version' => '1.0.0'],
            ]),
            'ping'       => $this->rpcResult($response, $id, new \stdClass()),
            'tools/list' => $this->rpcResult($response, $id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->handleToolCall($request, $response, $id, $params),
            default      => $this->rpcError($response, $id, -32601, 'Method not found: ' . $method),
        };
    }

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        $ideaId = ['type' => 'integer', 'minimum' => 1, 'description' => 'Idea id (from list_ideas/search_ideas).'];

        return [
            [
                'name'        => 'get_board',
                'description' => 'Read the board this API token is scoped to: id, slug, name, intro, its status registry'
                    . ' (statuses[].key are the valid values for set_idea_status, including board-defined custom statuses)'
                    . ' and its tags (tags[].id for add_idea_tag/remove_idea_tag).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_ideas',
                'description' => 'List published ideas on the token\'s board, optionally filtered by status or a title search, sorted, paginated.'
                    . ' With moderation_state "pending" (moderate grant only) it returns the approval queue instead.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [
                            'type'        => 'string',
                            'enum'        => IdeaRepository::ALLOWED_STATUSES,
                            'description' => 'Filter by status category (canonical slug). Matches every status of that'
                                . ' category, including board-defined custom statuses (each idea also carries'
                                . ' status_key, status_label and status_category).',
                        ],
                        'status_key' => [
                            'type'        => 'string',
                            'pattern'     => '^[a-z0-9_]{1,16}$',
                            'description' => 'Filter by one exact status key (an idea\'s status_key); wins over status.',
                        ],
                        'q'      => ['type' => 'string', 'maxLength' => 100, 'description' => 'Title search, every word must match.'],
                        'sort'   => ['type' => 'string', 'enum' => array_keys(IdeaRepository::SORT_AXES), 'description' => 'Sort axis.'],
                        'page'   => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based page number.'],
                        'moderation_state' => [
                            'type'        => 'string',
                            'enum'        => ['pending'],
                            'description' => 'Only "pending": list ideas awaiting approval (requires the moderate grant).',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'search_ideas',
                'description' => 'Search published ideas on the token\'s board by title (every word must match, prefix matching) —'
                    . ' use it to find existing ideas or duplicates before creating a new one.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'q'    => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'Search text.'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based page number.'],
                    ],
                    'required'             => ['q'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_idea',
                'description' => 'Read a single idea by id, scoped to the token\'s board (not-found error for foreign-board ids).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Idea id.'],
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'create_idea',
                'description' => 'Submit a new idea to the token\'s board (write grant). Attributed to the admin who created the token.'
                    . ' On a board with pre-moderation the idea waits for approval (moderation_state "pending") unless the token has the moderate grant.'
                    . ' Refused (423) while the board\'s optional submission window is not open.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Idea title (3-200 characters).'],
                        'body'  => ['type' => 'string', 'description' => 'Idea description (>= 10 characters).'],
                    ],
                    'required'             => ['title', 'body'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'vote_idea',
                'description' => 'Set this token\'s own vote on an idea (write grant). The token is one voter: one vote per token per idea,'
                    . ' separate from any user\'s vote. "up"/"down" cast or change it, "none" removes it; repeating is a no-op.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => $ideaId,
                        'value' => ['type' => 'string', 'enum' => ['up', 'down', 'none'], 'description' => 'Desired vote.'],
                    ],
                    'required'             => ['id', 'value'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'comment_on_idea',
                'description' => 'Post a plain-text comment on an idea (write grant), attributed to the admin who created the token.'
                    . ' Rejected if that user already wrote the latest comment on the idea.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => $ideaId,
                        'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'Comment text (plain text, max 500 characters).'],
                    ],
                    'required'             => ['id', 'body'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'set_idea_status',
                'description' => 'Change an idea\'s status (moderate grant). status must be a key from get_board\'s statuses;'
                    . ' voters, commenters and the author are notified and webhooks fire as in the app.'
                    . ' Optional reason (plaintext, max 1000), next_step (plaintext, max 280) and responsible_role'
                    . ' (team | moderation) are shown publicly on the idea with the decision.'
                    . ' A board may require a reason: then a change without reason is refused (status_reason_required).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'               => $ideaId,
                        'status'           => ['type' => 'string', 'pattern' => '^[a-z0-9_]{1,16}$', 'description' => 'Target status key.'],
                        'reason'           => ['type' => 'string', 'maxLength' => 1000, 'description' => 'Optional public reason for the decision.'],
                        'next_step'        => ['type' => 'string', 'maxLength' => 280, 'description' => 'Optional public next step.'],
                        'responsible_role' => ['type' => 'string', 'enum' => ['team', 'moderation'], 'description' => 'Optional responsible role label.'],
                    ],
                    'required'             => ['id', 'status'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'add_idea_tag',
                'description' => 'Assign one of the board\'s tags to an idea (moderate grant); idempotent. Tag ids come from get_board.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $ideaId,
                        'tag_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Tag id.'],
                    ],
                    'required'             => ['id', 'tag_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'remove_idea_tag',
                'description' => 'Remove a tag from an idea (moderate grant); idempotent.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $ideaId,
                        'tag_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Tag id.'],
                    ],
                    'required'             => ['id', 'tag_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'merge_ideas',
                'description' => 'Merge a duplicate idea (id) into another published idea on the same board (target_id), moderate grant.'
                    . ' Votes are combined (one per voter), the duplicate is hidden and its followers are notified. Reversible with unmerge_idea.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => $ideaId,
                        'target_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the idea to merge into.'],
                    ],
                    'required'             => ['id', 'target_id'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'unmerge_idea',
                'description' => 'Undo a merge (moderate grant): the merged duplicate (id) becomes a separate idea again.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => $ideaId],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $params */
    private function handleToolCall(
        ServerRequestInterface $request,
        ResponseInterface $response,
        mixed $id,
        array $params,
    ): ResponseInterface {
        $name = is_string($params['name'] ?? null) ? $params['name'] : null;
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === null) {
            return $this->rpcError($response, $id, -32602, 'Invalid params: "name" is required');
        }

        if (!in_array($name, self::TOOL_NAMES, true)) {
            return $this->rpcError($response, $id, -32601, 'Unknown tool: ' . $name);
        }

        /** @var array{token_id: int, account_id: int, scope: string, created_by_user_id: int, label: string}|null $token */
        $token     = $request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $tokenId   = is_array($token) ? $token['token_id'] : 0;

        if ($name === 'create_idea' && is_array($token) && !ApiTokenScope::allowsWrite($token['scope'])) {
            return $this->rpcError($response, $id, -32000, 'This token is read-only');
        }

        if (in_array($name, self::WRITE_TOOLS, true) && !$this->consumeWriteBudget($tokenId)) {
            return $this->rpcError($response, $id, -32000, 'Rate limit exceeded');
        }

        $canModerate = ApiIdeaAction::canModerate($token);
        $inner       = $request->withAttribute(ApiIdeaWriteAction::ATTR_VIA, 'mcp');

        $result = match ($name) {
            'get_board'       => $this->callGetBoard($accountId, $boardId),
            'list_ideas'      => $this->callListIdeas($boardId, $args, $canModerate),
            'search_ideas'    => $this->callListIdeas($boardId, ['q' => $args['q'] ?? null, 'page' => $args['page'] ?? null], $canModerate),
            'get_idea'        => $this->callGetIdea($boardId, $args, $canModerate),
            'create_idea'     => is_array($token)
                ? $this->callCreateIdea($boardId, $token, $args)
                : ['data' => ['error' => ['key' => 'unauthorized', 'message' => 'No token.']], 'isError' => true],
            'vote_idea'       => $this->callWrite($this->writeAction->vote(...), $inner, $args, ['value']),
            'comment_on_idea' => $this->callWrite($this->writeAction->comment(...), $inner, $args, ['body']),
            'set_idea_status' => $this->callWrite($this->writeAction->status(...), $inner, $args, ['status', 'reason', 'next_step', 'responsible_role']),
            'add_idea_tag'    => $this->callWrite($this->writeAction->assignTag(...), $inner, $args, ['tag_id']),
            'remove_idea_tag' => $this->callWrite($this->writeAction->removeTag(...), $inner, ['id' => $args['id'] ?? null, 'tagId' => $args['tag_id'] ?? null], []),
            'merge_ideas'     => $this->callWrite($this->writeAction->mergeInto(...), $inner, $args, ['target_id']),
            'unmerge_idea'    => $this->callWrite($this->writeAction->unmerge(...), $inner, $args, []),
        };

        return $this->rpcResult($response, $id, [
            'content' => [['type' => 'text', 'text' => (string) json_encode($result['data'])]],
            'isError' => $result['isError'],
        ]);
    }

    /** @return array{data: array<string, mixed>, isError: bool} */
    private function callGetBoard(int $accountId, int $boardId): array
    {
        $board = $this->boardAction->resolveBoard($accountId, $boardId);
        if ($board === null) {
            return ['data' => ['error' => ['key' => 'not_found', 'message' => 'Board not found.']], 'isError' => true];
        }

        return ['data' => ['board' => $board], 'isError' => false];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{data: array<string, mixed>, isError: bool}
     */
    private function callListIdeas(int $boardId, array $args, bool $canModerate): array
    {
        if (ApiIdeaAction::wantsPending($args)) {
            return $canModerate
                ? ['data' => $this->ideaAction->resolvePending($boardId), 'isError' => false]
                : ['data' => ['error' => ['key' => 'insufficient_scope', 'message' => 'Reading pending ideas requires the moderate grant.']], 'isError' => true];
        }

        return ['data' => $this->ideaAction->resolveList($boardId, $args), 'isError' => false];
    }

    /**
     * Runs an ApiIdeaWriteAction REST handler with the tool arguments as
     * the parsed body; the REST response body is the tool result.
     *
     * @param callable(ServerRequestInterface, ResponseInterface, array<string, mixed>): ResponseInterface $handler
     * @param array<string, mixed> $args
     * @param list<string> $bodyFields
     * @return array{data: array<string, mixed>, isError: bool}
     */
    private function callWrite(callable $handler, ServerRequestInterface $request, array $args, array $bodyFields): array
    {
        $body = [];
        foreach ($bodyFields as $field) {
            if (array_key_exists($field, $args)) {
                $body[$field] = $args[$field];
            }
        }

        $routeArgs = [];
        foreach (['id', 'tagId'] as $key) {
            if (is_int($args[$key] ?? null) || (is_string($args[$key] ?? null) && ctype_digit($args[$key]))) {
                $routeArgs[$key] = (string) $args[$key];
            }
        }
        if (!isset($routeArgs['id']) || (array_key_exists('tagId', $args) && !isset($routeArgs['tagId']))) {
            return ['data' => ['error' => ['key' => 'invalid_arguments', 'message' => 'Numeric id arguments are required.']], 'isError' => true];
        }

        $result  = $handler($request->withParsedBody($body), $this->responseFactory->createResponse(), $routeArgs);
        $decoded = json_decode((string) $result->getBody(), true);

        return [
            'data'    => is_array($decoded) ? $decoded : [],
            'isError' => $result->getStatusCode() >= 400,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{data: array<string, mixed>, isError: bool}
     */
    private function callGetIdea(int $boardId, array $args, bool $canModerate): array
    {
        $rawId = $args['id'] ?? null;
        $id    = is_numeric($rawId) ? (int) $rawId : 0;

        $idea = $this->ideaAction->resolveDetail($boardId, $id, $canModerate);
        if ($idea === null) {
            return ['data' => ['error' => ['key' => 'not_found', 'message' => 'Idea not found.']], 'isError' => true];
        }

        return ['data' => ['idea' => $idea], 'isError' => false];
    }

    /**
     * @param array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string} $token
     * @param array<string, mixed> $args
     * @return array{data: array<string, mixed>, isError: bool}
     */
    private function callCreateIdea(int $boardId, array $token, array $args): array
    {
        $title = is_string($args['title'] ?? null) ? trim($args['title']) : '';
        $body  = is_string($args['body'] ?? null) ? trim($args['body']) : '';

        $result = $this->ideaAction->submit($boardId, $token, $title, $body, 'mcp');

        if (isset($result['error'])) {
            return ['data' => ['error' => $result['error']], 'isError' => true];
        }

        return ['data' => ['ok' => true] + $result, 'isError' => false];
    }

    /**
     * Consumes one unit of the per-token `apitoken:write` budget, using the
     * same bucket key format `RateLimitMiddleware::perAction()` uses for
     * the REST write routes — a token shares one write budget across REST
     * and MCP. Fail-open on a DB hiccup, mirroring `RateLimitMiddleware`'s own
     * documented exception to fail-secure (availability, not an AuthZ gate).
     */
    private function consumeWriteBudget(int $tokenId): bool
    {
        if ($tokenId <= 0) {
            return true; // no resolvable identity — nothing to throttle
        }

        try {
            return $this->rateLimiter->hit('apitoken:write:' . $tokenId, $this->writeLimit, $this->writeWindow);
        } catch (DbalException) {
            return true;
        }
    }

    private function rpcResult(ResponseInterface $response, mixed $id, mixed $result): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function rpcError(ResponseInterface $response, mixed $id, int $code, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
