<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the Bearer-token-authenticated Agent API:
 * GET /api/v1/board, GET /api/v1/ideas,
 * GET /api/v1/ideas/{id}, POST /api/v1/ideas.
 *
 * AC coverage:
 *   - A valid token reads/writes only its own board.
 *   - Missing/unknown/revoked token → 401.
 *   - Cross-board leak: a token for board A cannot read ideas from board B.
 *   - Rate limit `apitoken:write` applies per token.
 */
final class ApiTokenEndpointTest extends IntegrationTestCase
{
    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $plaintextToken, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $plaintextToken);

        return $body !== null ? $request->withParsedBody($body) : $request;
    }

    /**
     * Seeds a token for a board; returns [tokenId, plaintext].
     *
     * @return array{0: int, 1: string}
     */
    private function seedToken(int $accountId, int $boardId, int $createdByUserId, string $label = 'CI'): array
    {
        $vault = new TokenVault();
        $pair  = $vault->generate();
        $id    = $this->insertApiToken($accountId, $boardId, $createdByUserId, $pair['hash'], $label);

        return [$id, $pair['token']];
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/board
    // -------------------------------------------------------------------------

    public function test_valid_token_reads_its_own_board(): void
    {
        $boardId = $this->insertBoard('api-board', ['name' => 'API Board']);
        $userId  = $this->insertUser('api-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer('GET', '/api/v1/board', $plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('api-board', $data['board']['slug']);
        self::assertSame('API Board', $data['board']['name']);
    }

    public function test_missing_authorization_header_returns_401(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/board'),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_unknown_token_returns_401(): void
    {
        $response = $this->createApp()->handle($this->bearer('GET', '/api/v1/board', 'not-a-real-token'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_revoked_token_returns_401(): void
    {
        $boardId = $this->insertBoard('api-board-revoked');
        $userId  = $this->insertUser('api-owner-revoked@example.com');
        $vault   = new TokenVault();
        $pair    = $vault->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, $pair['hash'], overrides: [
            'revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->bearer('GET', '/api/v1/board', $pair['token']));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/ideas, /api/v1/ideas/{id} — board-scoped, no cross-board leak
    // -------------------------------------------------------------------------

    public function test_ideas_list_only_returns_own_board_ideas(): void
    {
        $boardA = $this->insertBoard('api-ideas-a');
        $boardB = $this->insertBoard('api-ideas-b');
        $userId = $this->insertUser('api-ideas-owner@example.com');

        $this->seedIdea($boardA, $userId, 'Idea in A');
        $this->seedIdea($boardB, $userId, 'Idea in B');

        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardA, $userId);

        $response = $this->createApp()->handle($this->bearer('GET', '/api/v1/ideas', $plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $data['ideas']);
        self::assertSame('Idea in A', $data['ideas'][0]['title']);
    }

    public function test_idea_detail_for_foreign_board_returns_404_not_leak(): void
    {
        $boardA = $this->insertBoard('api-detail-a');
        $boardB = $this->insertBoard('api-detail-b');
        $userId = $this->insertUser('api-detail-owner@example.com');

        $foreignIdea = $this->seedIdea($boardB, $userId, 'Foreign idea');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardA, $userId);

        $response = $this->createApp()->handle($this->bearer('GET', "/api/v1/ideas/{$foreignIdea}", $plain));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_idea_detail_for_own_board_returns_200(): void
    {
        $boardId = $this->insertBoard('api-detail-own');
        $userId  = $this->insertUser('api-detail-own-owner@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId, 'Own idea');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer('GET', "/api/v1/ideas/{$ideaId}", $plain));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('Own idea', $data['idea']['title']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/ideas — write endpoint, AuthZ + cross-board
    // -------------------------------------------------------------------------

    public function test_valid_token_creates_idea_attributed_to_token_creator(): void
    {
        $boardId = $this->insertBoard('api-create');
        $userId  = $this->insertUser('api-create-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer('POST', '/api/v1/ideas', $plain, [
            'title' => 'Agent-submitted idea',
            'body'  => 'This idea was submitted via the Agent API token.',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        $row = $this->conn->fetchAssociative('SELECT * FROM ideas WHERE id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertSame($boardId, (int) $row['board_id']);
        self::assertSame($userId, (int) $row['author_id']);
        self::assertSame('Agent-submitted idea', $row['title']);
    }

    public function test_create_idea_validation_failure_returns_422(): void
    {
        $boardId = $this->insertBoard('api-create-invalid');
        $userId  = $this->insertUser('api-create-invalid-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $response = $this->createApp()->handle($this->bearer('POST', '/api/v1/ideas', $plain, [
            'title' => '',
            'body'  => '',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_write_via_revoked_token_returns_401_and_creates_nothing(): void
    {
        $boardId = $this->insertBoard('api-create-revoked');
        $userId  = $this->insertUser('api-create-revoked-owner@example.com');
        $vault   = new TokenVault();
        $pair    = $vault->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $userId, $pair['hash'], overrides: [
            'revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle($this->bearer('POST', '/api/v1/ideas', $pair['token'], [
            'title' => 'Should never land',
            'body'  => 'Because the token used to submit it is revoked.',
        ]));

        self::assertSame(401, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(0, $count);
    }

    // -------------------------------------------------------------------------
    // Rate limiting — apitoken:write, per-token bucket
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

    public function test_exceeding_write_rate_limit_returns_429(): void
    {
        $boardId = $this->insertBoard('api-rl-write');
        $userId  = $this->insertUser('api-rl-write-owner@example.com');
        [, $plain] = $this->seedToken($this->defaultAccountId(), $boardId, $userId);

        $app = $this->createApp();

        $first = $app->handle($this->bearer('POST', '/api/v1/ideas', $plain, [
            'title' => 'First agent idea',
            'body'  => 'Well within the rate limit for this token.',
        ]));
        self::assertSame(201, $first->getStatusCode());

        $second = $app->handle($this->bearer('POST', '/api/v1/ideas', $plain, [
            'title' => 'Second agent idea',
            'body'  => 'This one should be throttled by the per-token bucket.',
        ]));
        self::assertSame(429, $second->getStatusCode());

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :id', ['id' => $boardId]);
        self::assertSame(1, $count);
    }
}
