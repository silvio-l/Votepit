<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the MCP resource wrapper:
 * POST /api/v1/mcp — JSON-RPC 2.0 over the existing Bearer-token path.
 *
 * AC coverage (mirrors ApiTokenEndpointTest, see there):
 *   - initialize/tools-list work (discovery).
 *   - tools/call for each of the four capabilities returns board-scoped data.
 *   - A token from another board/account cannot read/write outside its
 *     scope (cross-tenant leak test).
 *   - An invalid/revoked token is rejected (401, no JSON-RPC envelope
 *     — same HTTP middleware layer as /api/v1/*).
 *   - Malformed JSON-RPC requests get a proper JSON-RPC error response
 *     (200 + error object), never a 500.
 */
final class McpEndpointTest extends IntegrationTestCase
{
    private function bearer(string $plaintextToken, mixed $rpcBody): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mcp')
            ->withHeader('Authorization', 'Bearer ' . $plaintextToken)
            ->withHeader('Content-Type', 'application/json');

        $request->getBody()->write((string) json_encode($rpcBody));
        $request->getBody()->rewind();

        return $request;
    }

    /** @return array{0: int, 1: string} */
    private function seedToken(int $accountId, int $boardId, int $createdByUserId, string $label = 'MCP-CI'): array
    {
        $vault = new TokenVault();
        $pair  = $vault->generate();
        $id    = $this->insertApiToken($accountId, $boardId, $createdByUserId, $pair['hash'], $label);

        return [$id, $pair['token']];
    }

    // -------------------------------------------------------------------------
    // Discovery — initialize / tools/list
    // -------------------------------------------------------------------------

    public function test_initialize_returns_protocol_info(): void
    {
        $boardId = $this->insertBoard('mcp-init');
        $userId  = $this->insertUser('mcp-init@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('2.0', $data['jsonrpc']);
        self::assertSame(1, $data['id']);
        self::assertArrayHasKey('protocolVersion', $data['result']);
        self::assertArrayHasKey('tools', $data['result']['capabilities']);
    }

    public function test_tools_list_returns_all_four_tools(): void
    {
        $boardId = $this->insertBoard('mcp-tools-list');
        $userId  = $this->insertUser('mcp-tools-list@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 'a',
            'method'  => 'tools/list',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data  = json_decode((string) $response->getBody(), true);
        $names = array_column($data['result']['tools'], 'name');
        self::assertSame(['get_board', 'list_ideas', 'get_idea', 'create_idea'], $names);
    }

    // -------------------------------------------------------------------------
    // tools/call — one per REST capability, board-scoped
    // -------------------------------------------------------------------------

    public function test_get_board_tool_returns_own_board(): void
    {
        $boardId = $this->insertBoard('mcp-board', ['name' => 'MCP Board']);
        $userId  = $this->insertUser('mcp-board@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'get_board'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['result']['isError']);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);
        self::assertSame('mcp-board', $payload['board']['slug']);
        self::assertSame('MCP Board', $payload['board']['name']);
    }

    public function test_list_ideas_tool_only_returns_own_board_ideas(): void
    {
        $boardA = $this->insertBoard('mcp-ideas-a');
        $boardB = $this->insertBoard('mcp-ideas-b');
        $userId = $this->insertUser('mcp-ideas-owner@example.com');

        $this->seedIdea($boardA, $userId, 'MCP Idea in A');
        $this->seedIdea($boardB, $userId, 'MCP Idea in B');

        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardA, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'list_ideas'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);
        self::assertCount(1, $payload['ideas']);
        self::assertSame('MCP Idea in A', $payload['ideas'][0]['title']);
    }

    public function test_get_idea_tool_for_foreign_board_returns_not_found_not_leak(): void
    {
        $boardA = $this->insertBoard('mcp-detail-a');
        $boardB = $this->insertBoard('mcp-detail-b');
        $userId = $this->insertUser('mcp-detail-owner@example.com');

        $foreignIdea = $this->seedIdea($boardB, $userId, 'Foreign MCP idea');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardA, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'get_idea', 'arguments' => ['id' => $foreignIdea]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['result']['isError']);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);
        self::assertSame('not_found', $payload['error']['key']);
    }

    public function test_get_idea_tool_for_own_board_returns_idea(): void
    {
        $boardId = $this->insertBoard('mcp-detail-own');
        $userId  = $this->insertUser('mcp-detail-own-owner@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Own MCP idea');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'get_idea', 'arguments' => ['id' => $ideaId]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['result']['isError']);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);
        self::assertSame('Own MCP idea', $payload['idea']['title']);
    }

    public function test_create_idea_tool_creates_idea_attributed_to_token_creator(): void
    {
        $boardId = $this->insertBoard('mcp-create');
        $userId  = $this->insertUser('mcp-create-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'create_idea',
                'arguments' => [
                    'title' => 'Agent-submitted via MCP',
                    'body'  => 'This idea was submitted through the MCP tool wrapper.',
                ],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['result']['isError']);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);

        $row = $this->conn->fetchAssociative('SELECT * FROM ideas WHERE id = :id', ['id' => $payload['id']]);
        self::assertIsArray($row);
        self::assertSame($boardId, (int) $row['board_id']);
        self::assertSame($userId, (int) $row['author_id']);
        self::assertSame('Agent-submitted via MCP', $row['title']);
    }

    public function test_create_idea_tool_rejects_read_only_token(): void
    {
        $boardId = $this->insertBoard('mcp-readonly');
        $userId  = $this->insertUser('mcp-readonly-owner@example.com');
        $pair    = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, $pair['hash'], 'Read-only', scope: 'read');

        $response = $this->createApp()->handle($this->bearer($pair['token'], [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'create_idea',
                'arguments' => ['title' => 'Should be rejected', 'body' => 'A read-only token must not write.'],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(-32000, $data['error']['code']);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(0, $count);
    }

    public function test_create_idea_tool_validation_failure_is_reported_as_tool_error(): void
    {
        $boardId = $this->insertBoard('mcp-create-invalid');
        $userId  = $this->insertUser('mcp-create-invalid-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'create_idea', 'arguments' => ['title' => '', 'body' => '']],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data    = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['result']['isError']);
        $payload = json_decode((string) $data['result']['content'][0]['text'], true);
        self::assertSame('validation_error', $payload['error']['key']);
    }

    // -------------------------------------------------------------------------
    // AuthN — invalid/revoked token, same 401 gate as REST
    // -------------------------------------------------------------------------

    public function test_missing_authorization_header_returns_403_via_csrf_gate(): void
    {
        // POST /api/v1/mcp without a Bearer header is treated by CsrfMiddleware
        // as an ordinary (non-Bearer) mutating request — it never reaches
        // ApiTokenAuthMiddleware, so the CSRF gate (403), not the token gate
        // (401), rejects it first. A request that DOES carry an (invalid)
        // Bearer header exercises the token gate instead — see
        // test_revoked_token_returns_401 below.
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/mcp');
        $request->getBody()->write((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']));

        $response = $this->createApp()->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_unknown_token_returns_401(): void
    {
        $response = $this->createApp()->handle($this->bearer('not-a-real-token', [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_revoked_token_returns_401(): void
    {
        $boardId = $this->insertBoard('mcp-revoked');
        $userId  = $this->insertUser('mcp-revoked@example.com');
        $vault   = new TokenVault();
        $pair    = $vault->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, $pair['hash'], overrides: [
            'revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->bearer($pair['token'], [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Malformed JSON-RPC — proper JSON-RPC error, never a 500
    // -------------------------------------------------------------------------

    public function test_malformed_json_body_returns_parse_error_not_500(): void
    {
        $boardId = $this->insertBoard('mcp-malformed');
        $userId  = $this->insertUser('mcp-malformed@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mcp')
            ->withHeader('Authorization', 'Bearer ' . $plain);
        $request->getBody()->write('{not valid json');

        $response = $this->createApp()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(-32700, $data['error']['code']);
    }

    public function test_missing_jsonrpc_version_returns_invalid_request_error(): void
    {
        $boardId = $this->insertBoard('mcp-invalid-request');
        $userId  = $this->insertUser('mcp-invalid-request@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, ['id' => 1, 'method' => 'initialize']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(-32600, $data['error']['code']);
    }

    public function test_unknown_method_returns_method_not_found_error(): void
    {
        $boardId = $this->insertBoard('mcp-unknown-method');
        $userId  = $this->insertUser('mcp-unknown-method@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'not/a/real/method',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(-32601, $data['error']['code']);
    }

    public function test_unknown_tool_returns_method_not_found_error(): void
    {
        $boardId = $this->insertBoard('mcp-unknown-tool');
        $userId  = $this->insertUser('mcp-unknown-tool@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer($plain, [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'delete_everything'],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(-32601, $data['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Rate limiting — apitoken:write bucket shared with REST, per token
    // -------------------------------------------------------------------------

    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => [
                'apitoken:write' => ['limit' => 1, 'window' => 3600],
            ],
        ]);
    }

    public function test_exceeding_write_rate_limit_returns_rate_limit_error(): void
    {
        $boardId = $this->insertBoard('mcp-rl-write');
        $userId  = $this->insertUser('mcp-rl-write-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $app = $this->createApp();

        $createCall = static fn (string $title): array => [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => 'create_idea', 'arguments' => ['title' => $title, 'body' => 'Well within the length rules.']],
        ];

        $first = $app->handle($this->bearer($plain, $createCall('First MCP idea')));
        self::assertSame(200, $first->getStatusCode());
        $firstData = json_decode((string) $first->getBody(), true);
        self::assertFalse($firstData['result']['isError']);

        $second = $app->handle($this->bearer($plain, $createCall('Second MCP idea')));
        self::assertSame(200, $second->getStatusCode());
        $secondData = json_decode((string) $second->getBody(), true);
        self::assertSame(-32000, $secondData['error']['code']);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(1, $count);
    }
}
