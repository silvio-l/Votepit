<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * MCP tools added with competitor-parity issue 15 (search_ideas, vote_idea,
 * comment_on_idea, set_idea_status, add/remove_idea_tag, merge/unmerge,
 * pending reads). They run the same ApiIdeaWriteAction handlers as the REST
 * routes, so these tests focus on the MCP seam: arguments → handler, REST
 * errors → `isError`, grant levels, the shared write budget and account
 * scoping; the domain behaviour is covered by ApiIdeaWriteEndpointTest.
 */
final class McpWriteToolsTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $arguments */
    private function call(string $token, string $tool, array $arguments): ServerRequestInterface
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
        $response = $app->handle($this->call($token, $tool, $arguments));
        self::assertSame(200, $response->getStatusCode());
        $rpc = json_decode((string) $response->getBody(), true);
        self::assertIsArray($rpc);
        self::assertArrayHasKey('result', $rpc, (string) $response->getBody());
        $data = json_decode((string) $rpc['result']['content'][0]['text'], true);
        self::assertIsArray($data);

        return ['isError' => (bool) $rpc['result']['isError'], 'data' => $data];
    }

    private function token(int $accountId, int $boardId, int $issuerId, string $scope): string
    {
        $pair = (new TokenVault())->generate();
        $this->insertApiToken($accountId, $boardId, $issuerId, $pair['hash'], 'MCP ' . $scope, scope: $scope);

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

    public function test_write_tools_act_on_the_tokens_board(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Dark mode');
        $target = $this->seedIdea($boardId, $ownerId, 'Night theme');
        $tagId  = $this->insertTag($boardId, 'UI');
        $token  = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app    = $this->createApp();

        $vote = $this->tool($app, $token, 'vote_idea', ['id' => $ideaId, 'value' => 'up']);
        self::assertFalse($vote['isError']);
        self::assertSame('up', $vote['data']['my_vote']);

        self::assertFalse($this->tool($app, $token, 'comment_on_idea', ['id' => $ideaId, 'body' => 'Agent note.'])['isError']);
        self::assertFalse($this->tool($app, $token, 'set_idea_status', ['id' => $ideaId, 'status' => 'planned'])['isError']);
        self::assertFalse($this->tool($app, $token, 'add_idea_tag', ['id' => $ideaId, 'tag_id' => $tagId])['isError']);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments'));
        self::assertFalse($this->tool($app, $token, 'remove_idea_tag', ['id' => $ideaId, 'tag_id' => $tagId])['isError']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_tag_assignments'));
        self::assertFalse($this->tool($app, $token, 'merge_ideas', ['id' => $target, 'target_id' => $ideaId])['isError']);
        self::assertSame($ideaId, (int) $this->conn->fetchOne('SELECT merged_into_id FROM ideas WHERE id = ?', [$target]));
        self::assertFalse($this->tool($app, $token, 'unmerge_idea', ['id' => $target])['isError']);

        self::assertSame('planned', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
        self::assertStringContainsString('"via":"mcp"', $this->readAuditLog());

        $search = $this->tool($app, $token, 'search_ideas', ['q' => 'night']);
        self::assertSame(['Night theme'], array_column($search['data']['ideas'], 'title'));
    }

    public function test_moderator_tools_are_tool_errors_for_a_write_grant(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        $this->seedIdea($boardId, $ownerId, 'Pending', ['moderation_state' => 'pending']);
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'write');
        $app   = $this->createApp();

        $status = $this->tool($app, $token, 'set_idea_status', ['id' => $ideaId, 'status' => 'planned']);
        self::assertTrue($status['isError']);
        self::assertSame('insufficient_scope', $status['data']['error']['key']);

        $pending = $this->tool($app, $token, 'list_ideas', ['moderation_state' => 'pending']);
        self::assertTrue($pending['isError']);
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
    }

    public function test_moderate_grant_reads_the_pending_queue(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $pendingId = $this->seedIdea($boardId, $ownerId, 'Waiting', ['moderation_state' => 'pending']);
        $token     = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');
        $app       = $this->createApp();

        $queue = $this->tool($app, $token, 'list_ideas', ['moderation_state' => 'pending']);
        self::assertSame(['Waiting'], array_column($queue['data']['ideas'], 'title'));
        self::assertFalse($this->tool($app, $token, 'get_idea', ['id' => $pendingId])['isError']);
    }

    public function test_read_grant_cannot_vote_through_mcp(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea');
        $token  = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'read');

        $vote = $this->tool($this->createApp(), $token, 'vote_idea', ['id' => $ideaId, 'value' => 'up']);

        self::assertTrue($vote['isError']);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
    }

    public function test_foreign_account_ids_are_not_found_through_mcp(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $ideaId         = $this->seedIdea($boardId, $ownerId, 'Victim idea');
        $foreignAccount = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('foreign', ['account_id' => $foreignAccount, 'is_default' => 0]);
        $foreignOwner   = $this->insertUser('foreign@example.com');
        $this->insertAccountMember($foreignAccount, $foreignOwner, 'owner');
        $token = $this->token($foreignAccount, $foreignBoard, $foreignOwner, 'moderate');
        $app   = $this->createApp();

        foreach ([
            ['vote_idea', ['id' => $ideaId, 'value' => 'up']],
            ['comment_on_idea', ['id' => $ideaId, 'body' => 'Hi']],
            ['set_idea_status', ['id' => $ideaId, 'status' => 'planned']],
            ['merge_ideas', ['id' => $ideaId, 'target_id' => $ideaId]],
            ['unmerge_idea', ['id' => $ideaId]],
            ['get_idea', ['id' => $ideaId]],
        ] as [$tool, $args]) {
            $result = $this->tool($app, $token, $tool, $args);
            self::assertTrue($result['isError'], $tool);
        }

        self::assertSame([], array_column($this->tool($app, $token, 'search_ideas', ['q' => 'victim'])['data']['ideas'], 'title'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM comments'));
        self::assertSame('open', $this->conn->fetchOne('SELECT status FROM ideas WHERE id = ?', [$ideaId]));
    }

    public function test_non_numeric_ids_are_a_tool_error_not_a_500(): void
    {
        [$boardId, $ownerId] = $this->ownBoard();
        $token = $this->token($this->defaultAccountId(), $boardId, $ownerId, 'moderate');

        $result = $this->tool($this->createApp(), $token, 'vote_idea', ['id' => 'abc', 'value' => 'up']);

        self::assertTrue($result['isError']);
        self::assertSame('invalid_arguments', $result['data']['error']['key']);
    }
}
