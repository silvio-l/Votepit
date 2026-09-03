<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/boards/active-set (plan
 * upgrade/downgrade/cancellation lifecycle — owner picks which board(s)
 * stay active after a downgrade froze boards over the new plan's limit).
 *
 * AuthZ: accountOwner (same tier as BillingAction — see class doc there).
 */
final class BoardActiveSetActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param list<int> $boardIds */
    private function post(array $boardIds, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/active-set')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'board_ids' => $boardIds]);
    }

    private function frozenAt(int $boardId): ?string
    {
        $value = $this->conn->fetchOne('SELECT frozen_at FROM boards WHERE id = :id', ['id' => $boardId]);
        return $value === false || $value === null ? null : (string) $value;
    }

    public function test_owner_can_pick_active_board_within_limit(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->setAccountPlan($accountId, 'starter'); // limit 1

        $boardA = $this->insertBoard('board-a', ['is_default' => 0]);
        $boardB = $this->insertBoard('board-b', ['is_default' => 0]);
        $this->conn->update('boards', ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $boardA]);
        $this->conn->update('boards', ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $boardB]);

        $response = $this->createApp()->handle($this->post([$boardB], $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([$boardB], $data['active_board_ids'] ?? null);
        self::assertNotNull($this->frozenAt($boardA));
        self::assertNull($this->frozenAt($boardB));
    }

    public function test_choosing_more_boards_than_the_plan_limit_is_rejected(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner-limit@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->setAccountPlan($accountId, 'starter'); // limit 1

        $boardA = $this->insertBoard('board-limit-a', ['is_default' => 0]);
        $boardB = $this->insertBoard('board-limit-b', ['is_default' => 0]);

        $response = $this->createApp()->handle($this->post([$boardA, $boardB], $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_boards', $data['error']['key'] ?? null);
        // Nothing was frozen/unfrozen — validation happens before the write.
        self::assertNull($this->frozenAt($boardA));
        self::assertNull($this->frozenAt($boardB));
    }

    public function test_foreign_board_id_is_silently_dropped(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner-foreign@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->setAccountPlan($accountId, 'team'); // limit 5

        $ownBoard  = $this->insertBoard('board-own', ['is_default' => 0]);
        $otherAccountId = $this->insertAccount();
        $foreignBoard   = $this->insertBoard('board-foreign', ['account_id' => $otherAccountId, 'is_default' => 0]);

        $response = $this->createApp()->handle($this->post([$ownBoard, $foreignBoard], $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame([$ownBoard], $data['active_board_ids'] ?? null);
        self::assertNull($this->frozenAt($ownBoard));
    }

    public function test_moderator_is_rejected(): void
    {
        $accountId = $this->defaultAccountId();
        $modId     = $this->insertUser('mod-active-set@example.com');
        $this->insertAccountMember($accountId, $modId, 'moderator');

        $response = $this->createApp()->handle($this->post([], $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->post([], null));

        self::assertSame(401, $response->getStatusCode());
    }
}
