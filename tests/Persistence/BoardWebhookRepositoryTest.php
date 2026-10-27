<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Persistence\BoardWebhookRepository;

final class BoardWebhookRepositoryTest extends TestCase
{
    private Connection $conn;
    private BoardWebhookRepository $repo;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->conn->executeStatement('PRAGMA foreign_keys = ON');

        $this->conn->executeStatement('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT \'\')');
        $this->conn->executeStatement(
            'CREATE TABLE boards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                FOREIGN KEY (account_id) REFERENCES accounts(id)
            )',
        );
        $this->conn->executeStatement(
            'CREATE TABLE board_webhooks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                url VARCHAR(2048) NOT NULL,
                secret_encrypted VARCHAR(512) NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );
        $this->conn->executeStatement(
            'CREATE TABLE board_webhook_deliveries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_webhook_id INTEGER NOT NULL,
                event VARCHAR(64) NOT NULL,
                success INTEGER NOT NULL,
                status_code INTEGER NULL,
                error VARCHAR(255) NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_webhook_id) REFERENCES board_webhooks(id) ON DELETE CASCADE
            )',
        );

        $this->repo = new BoardWebhookRepository($this->conn);
    }

    private function seedAccount(): int
    {
        $this->conn->insert('accounts', ['name' => 'Test Account']);
        return (int) $this->conn->lastInsertId();
    }

    private function seedBoard(int $accountId): int
    {
        $this->conn->insert('boards', ['account_id' => $accountId]);
        return (int) $this->conn->lastInsertId();
    }

    public function test_save_creates_then_upserts_a_single_row_per_board(): void
    {
        $accountId = $this->seedAccount();
        $boardId   = $this->seedBoard($accountId);

        $id1 = $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');
        $id2 = $this->repo->save($boardId, 'https://b.example.com/hook', 'enc-b');

        self::assertSame($id1, $id2, 'a second save() must upsert, not create a second row');

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_webhooks WHERE board_id = :b', ['b' => $boardId]);
        self::assertSame(1, $count);

        $found = $this->repo->findActiveForBoard($boardId);
        self::assertNotNull($found);
        self::assertSame('https://b.example.com/hook', $found['url']);
        self::assertSame('enc-b', $found['secret_encrypted']);
    }

    public function test_find_active_for_board_returns_null_when_inactive(): void
    {
        $accountId = $this->seedAccount();
        $boardId   = $this->seedBoard($accountId);
        $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');
        $this->repo->setActive($accountId, $boardId, false);

        self::assertNull($this->repo->findActiveForBoard($boardId));
    }

    public function test_find_for_account_board_is_account_scoped(): void
    {
        $ownerAccount   = $this->seedAccount();
        $foreignAccount = $this->seedAccount();
        $boardId        = $this->seedBoard($ownerAccount);
        $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');

        self::assertNotNull($this->repo->findForAccountBoard($ownerAccount, $boardId));
        self::assertNull($this->repo->findForAccountBoard($foreignAccount, $boardId), 'cross-tenant read must not leak');
    }

    public function test_set_active_is_account_scoped(): void
    {
        $ownerAccount   = $this->seedAccount();
        $foreignAccount = $this->seedAccount();
        $boardId        = $this->seedBoard($ownerAccount);
        $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');

        self::assertFalse($this->repo->setActive($foreignAccount, $boardId, false), 'cross-tenant write must be rejected');
        self::assertNotNull($this->repo->findActiveForBoard($boardId), 'must stay active — the foreign write had no effect');

        self::assertTrue($this->repo->setActive($ownerAccount, $boardId, false));
        self::assertNull($this->repo->findActiveForBoard($boardId));
    }

    public function test_delete_is_account_scoped(): void
    {
        $ownerAccount   = $this->seedAccount();
        $foreignAccount = $this->seedAccount();
        $boardId        = $this->seedBoard($ownerAccount);
        $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');

        self::assertFalse($this->repo->delete($foreignAccount, $boardId));
        self::assertNotNull($this->repo->findForAccountBoard($ownerAccount, $boardId));

        self::assertTrue($this->repo->delete($ownerAccount, $boardId));
        self::assertNull($this->repo->findForAccountBoard($ownerAccount, $boardId));
    }

    public function test_record_and_list_recent_deliveries_account_scoped_newest_first(): void
    {
        $ownerAccount   = $this->seedAccount();
        $foreignAccount = $this->seedAccount();
        $boardId        = $this->seedBoard($ownerAccount);
        $webhookId      = $this->repo->save($boardId, 'https://a.example.com/hook', 'enc-a');

        $this->repo->recordDelivery($webhookId, 'idea.created', true, 200, null);
        $this->repo->recordDelivery($webhookId, 'idea.status_changed', false, 500, 'server_error');

        $rows = $this->repo->recentDeliveries($ownerAccount, $boardId);
        self::assertCount(2, $rows);
        self::assertSame('idea.status_changed', $rows[0]['event'], 'newest first');
        self::assertFalse($rows[0]['success']);
        self::assertSame(500, $rows[0]['status_code']);

        self::assertSame([], $this->repo->recentDeliveries($foreignAccount, $boardId), 'cross-tenant read must not leak');
    }
}
