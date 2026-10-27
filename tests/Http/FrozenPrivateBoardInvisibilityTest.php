<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\TokenVault;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * P-21 regression: a PRIVATE board that is frozen (either reason) must stay
 * invisible to everyone except its own account's members, on every read
 * path. Freezing must never widen visibility. The owner's own account keeps
 * reading it (control case, so a 404 is never just a broken route).
 */
final class FrozenPrivateBoardInvisibilityTest extends IntegrationTestCase
{
    private const SLUG = 'frozen-private';
    private const SECRET_TITLE = 'Secret idea title';

    /** @return array<string, array{string}> */
    public static function freezeReasons(): array
    {
        return [
            'downgrade freeze (frozen_at)'             => ['frozen_at'],
            'compliance freeze (compliance_frozen_at)' => ['compliance_frozen_at'],
        ];
    }

    /** @return array{boardId: int, ideaId: int, ownerId: int} private + frozen, owned by the default account */
    private function frozenPrivateBoard(string $column): array
    {
        $ownerId = $this->insertUser('p21-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $boardId = $this->insertBoard(self::SLUG, [
            'visibility' => 'private',
            $column      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $ideaId = $this->seedIdea($boardId, $ownerId, self::SECRET_TITLE);

        return ['boardId' => $boardId, 'ideaId' => $ideaId, 'ownerId' => $ownerId];
    }

    private function getRequest(string $path, ?int $userId = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    /** @return list<string> the board's read surfaces (self-host paths) */
    private function readSurfaces(int $ideaId): array
    {
        return [
            '/' . self::SLUG,
            '/' . self::SLUG . "/ideas/{$ideaId}",
            '/' . self::SLUG . '/widget',
            '/' . self::SLUG . '/roadmap',
        ];
    }

    #[DataProvider('freezeReasons')]
    public function test_anonymous_visitor_gets_404_on_every_read_surface(string $column): void
    {
        ['ideaId' => $ideaId] = $this->frozenPrivateBoard($column);
        $app = $this->createApp();

        foreach ($this->readSurfaces($ideaId) as $path) {
            $response = $app->handle($this->getRequest($path));
            self::assertSame(404, $response->getStatusCode(), "anonymous GET {$path}");
            self::assertStringNotContainsString(self::SECRET_TITLE, (string) $response->getBody());
        }
    }

    #[DataProvider('freezeReasons')]
    public function test_stranger_gets_404_on_every_read_surface(string $column): void
    {
        ['ideaId' => $ideaId] = $this->frozenPrivateBoard($column);
        $strangerId = $this->insertUser('p21-stranger@example.com');
        $app = $this->createApp();

        foreach ($this->readSurfaces($ideaId) as $path) {
            $response = $app->handle($this->getRequest($path, $strangerId));
            self::assertSame(404, $response->getStatusCode(), "stranger GET {$path}");
            self::assertStringNotContainsString(self::SECRET_TITLE, (string) $response->getBody());
        }
    }

    #[DataProvider('freezeReasons')]
    public function test_owner_account_member_still_reads_the_board(string $column): void
    {
        ['ideaId' => $ideaId, 'ownerId' => $ownerId] = $this->frozenPrivateBoard($column);
        $app = $this->createApp();

        self::assertSame(200, $app->handle($this->getRequest('/' . self::SLUG, $ownerId))->getStatusCode(), 'control: own member reads the board');
        self::assertSame(200, $app->handle($this->getRequest('/' . self::SLUG . "/ideas/{$ideaId}", $ownerId))->getStatusCode(), 'control: own member reads the idea');
    }

    #[DataProvider('freezeReasons')]
    public function test_discover_does_not_list_the_private_frozen_board(string $column): void
    {
        $this->frozenPrivateBoard($column);

        $response = $this->createApp()->handle($this->getRequest('/discover'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString(self::SLUG, (string) $response->getBody());
    }

    #[DataProvider('freezeReasons')]
    public function test_public_discovery_query_excludes_it(string $column): void
    {
        $this->frozenPrivateBoard($column);

        $slugs = array_column((new BoardRepository($this->conn))->listPublicDiscovery(50, 0), 'slug');

        self::assertNotContains(self::SLUG, $slugs);
    }

    #[DataProvider('freezeReasons')]
    public function test_foreign_agent_api_token_cannot_read_the_frozen_board(string $column): void
    {
        ['ideaId' => $ideaId] = $this->frozenPrivateBoard($column);

        $accountB = $this->insertAccount(['slug' => 'p21-bravo']);
        $userB    = $this->insertUser('p21-bravo-owner@example.com');
        $this->insertAccountMember($accountB, $userB, 'owner');
        $ownBoardB = $this->insertBoard('p21-own-board', ['account_id' => $accountB, 'visibility' => 'public']);
        $pair      = (new TokenVault())->generate();
        $this->insertApiToken($accountB, $ownBoardB, $userB, $pair['hash'], 'Bravo token');

        $app = $this->createApp();

        $list = $app->handle($this->bearer('GET', '/api/v1/ideas?board=' . self::SLUG, $pair['token']));
        self::assertNotSame(200, $list->getStatusCode());
        self::assertStringNotContainsString(self::SECRET_TITLE, (string) $list->getBody());

        $detail = $app->handle($this->bearer('GET', "/api/v1/ideas/{$ideaId}", $pair['token']));
        self::assertContains($detail->getStatusCode(), [403, 404]);
        self::assertStringNotContainsString(self::SECRET_TITLE, (string) $detail->getBody());
    }

    #[DataProvider('freezeReasons')]
    public function test_cloud_routing_member_of_another_account_gets_404(string $column): void
    {
        $accountA = $this->insertAccount(['slug' => 'alpha', 'plan' => 'pro']);
        $accountB = $this->insertAccount(['slug' => 'bravo', 'plan' => 'pro']);
        $ownerA   = $this->insertUser('p21-alpha-owner@example.com');
        $this->insertAccountMember($accountA, $ownerA, 'owner');
        $memberB  = $this->insertUser('p21-bravo-member@example.com');
        $this->insertAccountMember($accountB, $memberB, 'owner');
        $boardA = $this->insertBoard(self::SLUG, [
            'account_id' => $accountA,
            'visibility' => 'private',
            $column      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $ideaA = $this->seedIdea($boardA, $ownerA, 'Alpha secret');

        $app = $this->createApp(config: Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud'])));

        self::assertSame(200, $app->handle($this->getRequest('/alpha/' . self::SLUG, $ownerA))->getStatusCode(), 'control: owner of alpha reads it');

        foreach (['/alpha/' . self::SLUG, '/alpha/' . self::SLUG . "/ideas/{$ideaA}", '/alpha/' . self::SLUG . '/widget'] as $path) {
            $response = $app->handle($this->getRequest($path, $memberB));
            self::assertSame(404, $response->getStatusCode(), "bravo member GET {$path}");
            self::assertStringNotContainsString('Alpha secret', (string) $response->getBody());
        }
    }

    private function bearer(string $method, string $path, string $plaintextToken): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withHeader('Authorization', 'Bearer ' . $plaintextToken);
    }
}
