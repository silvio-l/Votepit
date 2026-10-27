<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\BoardChatIntegrationRepository;
use Votepit\Tests\Support\IntegrationTestCase;

final class BoardChatIntegrationRepositoryTest extends IntegrationTestCase
{
    public function test_save_list_and_dispatch_lookup(): void
    {
        $repo    = new BoardChatIntegrationRepository($this->conn);
        $boardId = $this->insertBoard('demo');
        $acct    = $this->defaultAccountId();

        self::assertTrue($repo->save($acct, $boardId, 'slack', 'enc-1', 'hooks.slack.com/…abcd'));
        self::assertTrue($repo->save($acct, $boardId, 'slack', 'enc-2', 'hooks.slack.com/…wxyz'), 'upsert replaces');

        $list = $repo->listForAccountBoard($acct, $boardId);
        self::assertCount(1, $list);
        self::assertSame('slack', $list[0]['provider']);
        self::assertSame('hooks.slack.com/…wxyz', $list[0]['url_hint']);
        self::assertArrayNotHasKey('url_encrypted', $list[0]);
        self::assertNull($list[0]['last_delivery_ok']);

        $dispatch = $repo->findForDispatch($boardId);
        self::assertCount(1, $dispatch);
        self::assertSame('enc-2', $dispatch[0]['url_encrypted']);
        self::assertSame('Demo Board', $dispatch[0]['board_name']);
        self::assertSame('demo', $dispatch[0]['board_slug']);
        self::assertSame($this->defaultAccountSlug(), $dispatch[0]['account_slug']);

        $repo->recordDelivery($dispatch[0]['id'], false, 'http_404');
        $list = $repo->listForAccountBoard($acct, $boardId);
        self::assertFalse($list[0]['last_delivery_ok']);
        self::assertSame('http_404', $list[0]['last_delivery_error']);
        self::assertNotNull($list[0]['last_delivery_at']);
    }

    public function test_foreign_account_can_neither_read_write_nor_delete(): void
    {
        $repo    = new BoardChatIntegrationRepository($this->conn);
        $boardId = $this->insertBoard('demo');
        $foreign = $this->insertAccount();

        self::assertFalse($repo->save($foreign, $boardId, 'discord', 'enc', 'hint'));
        self::assertSame(0, $this->rowCount(), 'nothing was written');

        $repo->save($this->defaultAccountId(), $boardId, 'discord', 'enc', 'hint');
        self::assertSame([], $repo->listForAccountBoard($foreign, $boardId));
        self::assertFalse($repo->delete($foreign, $boardId, 'discord'));
        self::assertSame(1, $this->rowCount());

        self::assertTrue($repo->delete($this->defaultAccountId(), $boardId, 'discord'));
        self::assertSame(0, $this->rowCount());
    }

    private function rowCount(): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_chat_integrations');
    }
}
