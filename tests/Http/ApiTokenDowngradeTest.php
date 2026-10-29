<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * The effective grant of an Agent API token follows its issuing user's CURRENT
 * role (ApiTokenAuthMiddleware::effectiveScope): `manage` stays only while the
 * issuer can administer the account, `moderate` only while the issuer can
 * moderate, otherwise the grant is `write`. A demoted issuer therefore loses
 * the power immediately, without a token change. REST and MCP are both covered
 * because they share the middleware.
 */
final class ApiTokenDowngradeTest extends IntegrationTestCase
{
    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $body !== null ? $request->withHeader('Content-Type', 'application/json')->withParsedBody($body) : $request;
    }

    /** @param array<string, mixed> $arguments */
    private function mcpCall(string $token, string $tool, array $arguments): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mcp')
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => ['name' => $tool, 'arguments' => $arguments],
        ]));
        $request->getBody()->rewind();

        return $request;
    }

    /**
     * @param App<null>            $app
     * @param array<string, mixed> $arguments
     * @return array{isError: bool, data: array<string, mixed>}
     */
    private function tool(App $app, string $token, string $tool, array $arguments): array
    {
        $response = $app->handle($this->mcpCall($token, $tool, $arguments));
        self::assertSame(200, $response->getStatusCode());
        $rpc = json_decode((string) $response->getBody(), true);
        self::assertIsArray($rpc);
        self::assertArrayHasKey('result', $rpc, (string) $response->getBody());
        $data = json_decode((string) $rpc['result']['content'][0]['text'], true);
        self::assertIsArray($data);

        return ['isError' => (bool) $rpc['result']['isError'], 'data' => $data];
    }

    /**
     * @param App<null> $app
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(App $app, ServerRequestInterface $request): array
    {
        $response = $app->handle($request);
        $decoded  = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function token(int $boardId, int $issuerId, string $scope): string
    {
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $issuerId, $pair['hash'], 'Downgrade ' . $scope, scope: $scope);

        return $pair['token'];
    }

    /**
     * A premoderated board (every submission waits) with an owner and an
     * issuer whose role the test sets.
     *
     * @return array{0: int, 1: int} [boardId, issuerId]
     */
    private function premoderatedBoard(string $issuerRole): array
    {
        $boardId = $this->insertBoard('demo', ['premoderation' => 'all']);
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $issuerId = $this->insertUser('issuer@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $issuerId, $issuerRole);

        return [$boardId, $issuerId];
    }

    public function test_demoted_moderate_issuer_rest_create_is_stored_pending(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('member');
        $token = $this->token($boardId, $issuerId, 'moderate');
        $app   = $this->createApp();

        $result = $this->send($app, $this->bearer('POST', '/api/v1/ideas', $token, ['title' => 'Agent idea', 'body' => 'Long enough description.']));

        self::assertSame(201, $result['status']);
        self::assertSame('pending', $result['body']['moderation_state'] ?? null);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE title = ?', ['Agent idea']));
    }

    public function test_demoted_moderate_issuer_mcp_create_is_stored_pending(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('member');
        $token = $this->token($boardId, $issuerId, 'moderate');
        $app   = $this->createApp();

        $result = $this->tool($app, $token, 'create_idea', ['title' => 'Agent idea', 'body' => 'Long enough description.']);

        self::assertFalse($result['isError']);
        self::assertSame('pending', $result['data']['moderation_state'] ?? null);
        self::assertSame('pending', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE title = ?', ['Agent idea']));
    }

    public function test_moderator_issuer_moderate_create_still_publishes(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('moderator');
        $token = $this->token($boardId, $issuerId, 'moderate');
        $app   = $this->createApp();

        $result = $this->send($app, $this->bearer('POST', '/api/v1/ideas', $token, ['title' => 'Agent idea', 'body' => 'Long enough description.']));

        self::assertSame(201, $result['status']);
        self::assertArrayNotHasKey('moderation_state', $result['body']);
        self::assertSame('published', $this->conn->fetchOne('SELECT moderation_state FROM ideas WHERE title = ?', ['Agent idea']));
    }

    public function test_demoted_moderate_issuer_cannot_read_the_pending_queue_over_rest_or_mcp(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('member');
        $this->seedIdea($boardId, $issuerId, 'Waiting', ['moderation_state' => 'pending']);
        $token = $this->token($boardId, $issuerId, 'moderate');
        $app   = $this->createApp();

        $rest = $this->send($app, $this->bearer('GET', '/api/v1/ideas?moderation_state=pending', $token));
        self::assertSame(403, $rest['status']);
        self::assertSame('insufficient_scope', $rest['body']['error']['key'] ?? null);

        $mcp = $this->tool($app, $token, 'list_ideas', ['moderation_state' => 'pending']);
        self::assertTrue($mcp['isError']);
        self::assertSame('insufficient_scope', $mcp['data']['error']['key'] ?? null);
    }

    public function test_manage_issuer_demoted_to_moderator_keeps_moderate_tools_but_loses_branding(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('moderator');
        $ideaId = $this->seedIdea($boardId, $issuerId, 'Idea');
        $token  = $this->token($boardId, $issuerId, 'manage');
        $app    = $this->createApp();

        $status = $this->send($app, $this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned']));
        self::assertSame(200, $status['status']);
        self::assertSame('planned', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));

        $branding = $this->send($app, $this->bearer('PATCH', '/api/v1/board/branding', $token, ['primary_color' => '#112233']));
        self::assertSame(403, $branding['status']);
        self::assertSame('insufficient_scope', $branding['body']['error']['key'] ?? null);

        $mcp = $this->tool($app, $token, 'update_board_branding', ['primary_color' => '#112233']);
        self::assertTrue($mcp['isError']);
        self::assertSame('insufficient_scope', $mcp['data']['error']['key'] ?? null);
        self::assertNull($this->conn->fetchOne('SELECT primary_color FROM boards WHERE id = ?', [$boardId]));
    }

    public function test_manage_issuer_demoted_to_member_keeps_only_write(): void
    {
        [$boardId, $issuerId] = $this->premoderatedBoard('member');
        $ideaId = $this->seedIdea($boardId, $issuerId, 'Idea');
        $token  = $this->token($boardId, $issuerId, 'manage');
        $app    = $this->createApp();

        $vote = $this->send($app, $this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/vote', $token, ['value' => 'up']));
        self::assertSame(200, $vote['status']);

        $status = $this->send($app, $this->bearer('POST', '/api/v1/ideas/' . $ideaId . '/status', $token, ['status' => 'planned']));
        self::assertSame(403, $status['status']);
        self::assertSame('insufficient_scope', $status['body']['error']['key'] ?? null);

        $branding = $this->send($app, $this->bearer('PATCH', '/api/v1/board/branding', $token, ['primary_color' => '#112233']));
        self::assertSame(403, $branding['status']);
        self::assertSame('insufficient_scope', $branding['body']['error']['key'] ?? null);
    }
}
