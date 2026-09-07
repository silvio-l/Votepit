<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
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
 * Exposes four MCP tools, mapping 1:1 onto the four REST endpoints. Deliberately
 * a thin adapter: read tools call `ApiBoardAction::resolveBoard()` /
 * `ApiIdeaAction::resolveList()` / `ApiIdeaAction::resolveDetail()`, and the
 * write tool calls `ApiIdeaAction::submit()` — the exact same pure Domain
 * methods the REST handlers use, so no query/validation/moderation logic is
 * duplicated between REST and MCP.
 *
 * Rate limiting mirrors the REST discipline with the same buckets, keyed by
 * token_id: the whole endpoint sits behind the route-level `apitoken:read`
 * bucket (`RateLimitMiddleware`, registered in `AppFactory`, covers
 * discovery calls and all four tools), and `create_idea` additionally spends
 * from the `apitoken:write` bucket here — a single JSON-RPC endpoint can't
 * be split per-method at the route level, so the write bucket is checked
 * directly via `RateLimiter::hit()` using the identical bucket key format
 * ("apitoken:write:<token_id>") the REST `POST /api/v1/ideas` route uses,
 * so a token shares one write budget across REST and MCP.
 *
 * Transport: every JSON-RPC response (success or protocol error) is
 * returned as HTTP 200 with a JSON-RPC envelope in the body — malformed
 * input (parse error, invalid request, unknown method/tool) never produces
 * a 500, it produces a JSON-RPC error object (fail-secure discipline
 * applies to AuthN via the middleware, not to protocol framing).
 *
 * Not implemented (deliberately out of scope for this minimal server):
 * JSON-RPC batch requests (removed from the current MCP spec's streamable
 * HTTP transport), `resources/*` (the four tools already cover every
 * capability Part 1 built; a resources surface would just be a second name
 * for the same two read tools), and SSE/streaming (single request/response
 * per call is sufficient for board/idea reads and one idea-create).
 */
final readonly class McpAction
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /** @var list<string> */
    private const TOOL_NAMES = ['get_board', 'list_ideas', 'get_idea', 'create_idea'];

    public function __construct(
        private ApiBoardAction $boardAction,
        private ApiIdeaAction $ideaAction,
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
        return [
            [
                'name'        => 'get_board',
                'description' => "Read the board this API token is scoped to (id, slug, name, intro).",
                'inputSchema' => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'list_ideas',
                'description' => 'List ideas on the token\'s board, optionally filtered by status and sorted, paginated.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'description' => 'Filter by idea status.'],
                        'sort'   => ['type' => 'string', 'description' => 'Sort axis.'],
                        'page'   => ['type' => 'integer', 'minimum' => 1, 'description' => '1-based page number.'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name'        => 'get_idea',
                'description' => 'Read a single idea by id, scoped to the token\'s board (404-equivalent error for foreign-board ids).',
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
                'description' => 'Submit a new idea to the token\'s board. Attributed to the admin who created the token.',
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
        $authorId  = is_array($token) ? $token['created_by_user_id'] : 0;
        $tokenId   = is_array($token) ? $token['token_id'] : 0;

        if ($name === 'create_idea' && is_array($token) && $token['scope'] !== 'write') {
            return $this->rpcError($response, $id, -32000, 'This token is read-only');
        }

        if ($name === 'create_idea' && !$this->consumeWriteBudget($tokenId)) {
            return $this->rpcError($response, $id, -32000, 'Rate limit exceeded');
        }

        $result = match ($name) {
            'get_board'   => $this->callGetBoard($accountId, $boardId),
            'list_ideas'  => ['data' => $this->ideaAction->resolveList($boardId, $args), 'isError' => false],
            'get_idea'    => $this->callGetIdea($boardId, $args),
            'create_idea' => $this->callCreateIdea($boardId, $authorId, $tokenId, $args),
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
    private function callGetIdea(int $boardId, array $args): array
    {
        $rawId = $args['id'] ?? null;
        $id    = is_numeric($rawId) ? (int) $rawId : 0;

        $idea = $this->ideaAction->resolveDetail($boardId, $id);
        if ($idea === null) {
            return ['data' => ['error' => ['key' => 'not_found', 'message' => 'Idea not found.']], 'isError' => true];
        }

        return ['data' => ['idea' => $idea], 'isError' => false];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{data: array<string, mixed>, isError: bool}
     */
    private function callCreateIdea(int $boardId, int $authorId, int $tokenId, array $args): array
    {
        $title = is_string($args['title'] ?? null) ? trim($args['title']) : '';
        $body  = is_string($args['body'] ?? null) ? trim($args['body']) : '';

        $result = $this->ideaAction->submit($boardId, $authorId, $title, $body, 'mcp', $tokenId);

        if (isset($result['error'])) {
            return ['data' => ['error' => $result['error']], 'isError' => true];
        }

        return ['data' => ['ok' => true, 'id' => $result['id']], 'isError' => false];
    }

    /**
     * Consumes one unit of the per-token `apitoken:write` budget, using the
     * same bucket key format `RateLimitMiddleware::perAction()` uses for
     * `POST /api/v1/ideas` — a token shares one write budget across REST and
     * MCP. Fail-open on a DB hiccup, mirroring `RateLimitMiddleware`'s own
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
