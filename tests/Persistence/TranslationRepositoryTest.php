<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Votepit\Persistence\TranslationRepository;

final class TranslationRepositoryTest extends TestCase
{
    private Connection $conn;
    private TranslationRepository $repo;

    protected function setUp(): void
    {
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->conn->executeStatement(
            'CREATE TABLE content_translations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(16) NOT NULL,
                entity_id INTEGER NOT NULL,
                target_locale VARCHAR(10) NOT NULL,
                source_hash CHAR(64) NOT NULL,
                detected_source_locale VARCHAR(10) NOT NULL,
                translated_title VARCHAR(500) NULL,
                translated_body TEXT NOT NULL,
                provider VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, target_locale)
            )',
        );

        $this->repo = new TranslationRepository($this->conn);
    }

    private function hashOf(string $text): string
    {
        return hash('sha256', $text);
    }

    public function test_find_cached_returns_null_when_no_row_exists(): void
    {
        self::assertNull($this->repo->findCached('idea', 1, 'en', $this->hashOf('x')));
    }

    public function test_upsert_then_find_cached_is_a_hit(): void
    {
        $hash = $this->hashOf('Title|Body');
        $this->repo->upsert('idea', 1, 'en', $hash, 'de', 'Translated title', 'Translated body', 'google');

        $found = $this->repo->findCached('idea', 1, 'en', $hash);

        self::assertNotNull($found);
        self::assertSame('Translated title', $found['translated_title']);
        self::assertSame('Translated body', $found['translated_body']);
        self::assertSame('de', $found['detected_source_locale']);
        self::assertSame('google', $found['provider']);
    }

    public function test_find_cached_with_null_title_for_a_comment(): void
    {
        $hash = $this->hashOf('Comment body');
        $this->repo->upsert('comment', 5, 'fr', $hash, 'en', null, 'Corps traduit', 'google');

        $found = $this->repo->findCached('comment', 5, 'fr', $hash);

        self::assertNotNull($found);
        self::assertNull($found['translated_title']);
        self::assertSame('Corps traduit', $found['translated_body']);
    }

    public function test_find_cached_is_a_miss_on_hash_mismatch(): void
    {
        $hash = $this->hashOf('Original text');
        $this->repo->upsert('idea', 1, 'en', $hash, 'de', 'Title', 'Body', 'google');

        $newHash = $this->hashOf('Edited text');
        self::assertNull($this->repo->findCached('idea', 1, 'en', $newHash), 'edited content must invalidate the cache');
    }

    public function test_find_cached_is_scoped_by_target_locale(): void
    {
        $hash = $this->hashOf('Body');
        $this->repo->upsert('idea', 1, 'en', $hash, 'de', 'Title', 'Body en', 'google');

        self::assertNull($this->repo->findCached('idea', 1, 'fr', $hash));
    }

    public function test_find_cached_is_scoped_by_entity_type(): void
    {
        $hash = $this->hashOf('Body');
        $this->repo->upsert('idea', 1, 'en', $hash, 'de', 'Title', 'Body', 'google');

        self::assertNull($this->repo->findCached('comment', 1, 'en', $hash), 'same entity_id but different entity_type must not collide');
    }

    public function test_upsert_overwrites_a_prior_row_instead_of_duplicating(): void
    {
        $hash1 = $this->hashOf('First version');
        $this->repo->upsert('idea', 1, 'en', $hash1, 'de', 'Title 1', 'Body 1', 'google');

        $hash2 = $this->hashOf('Second version');
        $this->repo->upsert('idea', 1, 'en', $hash2, 'de', 'Title 2', 'Body 2', 'google');

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_translations WHERE entity_type = :t AND entity_id = 1 AND target_locale = :l', ['t' => 'idea', 'l' => 'en']);
        self::assertSame(1, $count, 'a second upsert for the same (entity_type, entity_id, target_locale) must replace, not duplicate');

        $found = $this->repo->findCached('idea', 1, 'en', $hash2);
        self::assertNotNull($found);
        self::assertSame('Body 2', $found['translated_body']);

        self::assertNull($this->repo->findCached('idea', 1, 'en', $hash1), 'the stale hash must no longer be a hit');
    }
}
