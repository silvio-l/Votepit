<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Agent API board branding (migration 0117, `manage` grant): REST routes and
 * the MCP tools. The validation, plan gates and writes are the session
 * actions' (covered by BoardBrandingActionTest and BoardAssetActionTest); these
 * tests cover the API seam: grant levels, the issuer-role downgrade, the PATCH
 * merge, the frozen guard, the error shapes and the MCP argument handling.
 */
final class ApiBoardBrandingTest extends IntegrationTestCase
{
    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return $body !== null ? $request->withHeader('Content-Type', 'application/json')->withParsedBody($body) : $request;
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
        $this->insertApiToken($this->defaultAccountId(), $boardId, $issuerId, $pair['hash'], 'Branding ' . $scope, scope: $scope);

        return $pair['token'];
    }

    /** @return array{0: int, 1: int} */
    private function ownBoard(): array
    {
        $boardId = $this->insertBoard('demo');
        $ownerId = $this->insertUser('owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        return [$boardId, $ownerId];
    }

    public function test_read_grant_reads_the_branding_view(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'read');

        $result = $this->send($this->createApp(), $this->bearer('GET', '/api/v1/board/branding', $token));

        self::assertSame(200, $result['status']);
        self::assertSame('demo', $result['body']['branding']['board_slug']);
        self::assertArrayHasKey('typography_preset', $result['body']['pro_branding']);
        self::assertArrayHasKey('variants', $result['body']['assets']);
    }

    public function test_moderate_grant_cannot_change_branding(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'moderate');

        $result = $this->send($this->createApp(), $this->bearer('PATCH', '/api/v1/board/branding', $token, ['primary_color' => '#1d4ed8']));

        self::assertSame(403, $result['status']);
        self::assertSame('insufficient_scope', $result['body']['error']['key']);
        self::assertNull($this->conn->fetchOne('SELECT primary_color FROM boards WHERE id = ?', [$boardId]));
    }

    public function test_manage_grant_patch_keeps_the_fields_it_does_not_send(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $this->conn->executeStatement('UPDATE boards SET hide_badge = 1 WHERE id = ?', [$boardId]);
        $token = $this->token($boardId, $ownerId, 'manage');

        $result = $this->send($this->createApp(), $this->bearer('PATCH', '/api/v1/board/branding', $token, ['primary_color' => '#1d4ed8']));

        self::assertSame(200, $result['status']);
        self::assertSame('#1d4ed8', $this->conn->fetchOne('SELECT primary_color FROM boards WHERE id = ?', [$boardId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT hide_badge FROM boards WHERE id = ?', [$boardId]));
        self::assertStringContainsString('api_token.write', $this->readAuditLog());
    }

    public function test_low_contrast_color_fails_validation_and_writes_nothing(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'manage');

        $result = $this->send($this->createApp(), $this->bearer('PATCH', '/api/v1/board/branding', $token, [
            'primary_color' => '#ffffff',
            'intro'         => 'Hello',
        ]));

        self::assertSame(422, $result['status']);
        self::assertSame('validation_error', $result['body']['error']['key']);
        self::assertArrayHasKey('primary_color', $result['body']['error']['fields']);
        self::assertEmpty($this->conn->fetchOne('SELECT intro FROM boards WHERE id = ?', [$boardId]));
    }

    public function test_manage_token_of_an_issuer_who_is_no_longer_admin_is_downgraded(): void
    {
        [$boardId] = $this->ownBoard();
        $moderatorId = $this->insertUser('moderator@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $moderatorId, 'moderator');
        $token = $this->token($boardId, $moderatorId, 'manage');

        $result = $this->send($this->createApp(), $this->bearer('PATCH', '/api/v1/board/branding', $token, ['intro' => 'Hello']));

        self::assertSame(403, $result['status']);
        self::assertSame('insufficient_scope', $result['body']['error']['key']);
    }

    public function test_frozen_board_refuses_every_branding_write(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $this->conn->executeStatement("UPDATE boards SET frozen_at = '2026-01-01 00:00:00' WHERE id = ?", [$boardId]);
        $token = $this->token($boardId, $ownerId, 'manage');
        $app   = $this->createApp();

        $patch  = $this->send($app, $this->bearer('PATCH', '/api/v1/board/branding', $token, ['intro' => 'Hello']));
        $delete = $this->send($app, $this->bearer('DELETE', '/api/v1/board/assets/cover', $token));

        self::assertSame(423, $patch['status']);
        self::assertSame('board_frozen', $patch['body']['error']['key']);
        self::assertSame(423, $delete['status']);
    }

    public function test_deleting_an_absent_asset_is_a_no_op_success(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'manage');

        $result = $this->send($this->createApp(), $this->bearer('DELETE', '/api/v1/board/assets/cover', $token));

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['ok']);
    }

    // -------------------------------------------------------------------------
    // MCP tools
    // -------------------------------------------------------------------------

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

    public function test_mcp_branding_tools_read_and_update_with_the_manage_grant(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'manage');
        $app   = $this->createApp();

        $read = $this->tool($app, $token, 'get_board_branding', []);
        self::assertFalse($read['isError']);
        self::assertSame('demo', $read['data']['branding']['board_slug']);

        $update = $this->tool($app, $token, 'update_board_branding', ['primary_color' => '#1d4ed8']);
        self::assertFalse($update['isError']);
        self::assertSame('#1d4ed8', $this->conn->fetchOne('SELECT primary_color FROM boards WHERE id = ?', [$boardId]));
        self::assertStringContainsString('"via":"mcp"', $this->readAuditLog());
    }

    public function test_mcp_branding_writes_are_tool_errors_for_a_moderate_grant(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'moderate');
        $app   = $this->createApp();

        $update = $this->tool($app, $token, 'update_board_branding', ['intro' => 'Hello']);
        self::assertTrue($update['isError']);
        self::assertSame('insufficient_scope', $update['data']['error']['key']);

        $upload = $this->tool($app, $token, 'upload_board_asset', ['kind' => 'favicon', 'image_base64' => 'AAAA']);
        self::assertTrue($upload['isError']);
    }

    public function test_mcp_upload_rejects_invalid_base64_before_storing_anything(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'manage');
        $app   = $this->createApp();

        $upload = $this->tool($app, $token, 'upload_board_asset', [
            'kind'         => 'favicon',
            'image_base64' => 'data:image/png;base64,not-plain-base64!!',
        ]);

        self::assertTrue($upload['isError']);
        self::assertContains($upload['data']['error']['key'], ['invalid_upload', 'plan_feature_locked']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_assets WHERE board_id = ?', [$boardId]));
    }

    public function test_mcp_delete_asset_is_a_tool_success_with_the_manage_grant(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($boardId, $ownerId, 'manage');

        $delete = $this->tool($this->createApp(), $token, 'delete_board_asset', ['kind' => 'favicon']);

        self::assertFalse($delete['isError']);
        self::assertTrue($delete['data']['ok']);
    }
}
