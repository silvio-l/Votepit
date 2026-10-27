<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for content_translations (idea-comment-translation feature,
 * see migrations/0054_create_content_translations.sql) — the single DB
 * seam for cached machine translations of idea/comment content.
 *
 * entity_type/entity_id are polymorphic (an idea or a comment) — there is
 * deliberately no foreign key on entity_id (it references one of two
 * different tables depending on entity_type); the caller (the translate
 * actions) always resolves the idea/comment board-scoped FIRST via the
 * existing repositories, so only an already-verified entity ever reaches
 * this class.
 *
 * source_hash is a SHA-256 over the original text(s) actually translated,
 * computed by the caller at request time — findCached() treats a stored
 * hash that does not match the hash passed in as a cache miss (the
 * content was edited since the last translation), with no separate
 * invalidation job needed.
 */
final readonly class TranslationRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return array{translated_title: string|null, translated_body: string, detected_source_locale: string, provider: string}|null
     * @throws DbalException
     */
    public function findCached(string $entityType, int $entityId, string $targetLocale, string $sourceHash): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT translated_title, translated_body, detected_source_locale, provider, source_hash
               FROM content_translations
              WHERE entity_type = :entity_type AND entity_id = :entity_id AND target_locale = :target_locale',
            ['entity_type' => $entityType, 'entity_id' => $entityId, 'target_locale' => $targetLocale],
        );

        if ($row === false) {
            return null;
        }

        // A hash mismatch means the content was edited since this row was
        // cached — treated exactly like "no row" (cache miss), the caller
        // re-translates and upsert() overwrites this stale row.
        if (!hash_equals((string) $row['source_hash'], $sourceHash)) {
            return null;
        }

        return [
            'translated_title'       => $row['translated_title'] !== null ? (string) $row['translated_title'] : null,
            'translated_body'        => (string) $row['translated_body'],
            'detected_source_locale' => (string) $row['detected_source_locale'],
            'provider'               => (string) $row['provider'],
        ];
    }

    /**
     * Upsert on the (entity_type, entity_id, target_locale) UNIQUE key —
     * both "cache a new translation" and "overwrite a stale one" in one
     * call (mirrors BoardWebhookRepository::save()'s upsert idiom, though
     * here as a true UPSERT since this table has no other admin-facing
     * per-row identity to preserve).
     *
     * @throws DbalException
     */
    public function upsert(
        string $entityType,
        int $entityId,
        string $targetLocale,
        string $sourceHash,
        string $detectedSourceLocale,
        ?string $translatedTitle,
        string $translatedBody,
        string $provider,
    ): void {
        $platform = $this->conn->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO content_translations
                    (entity_type, entity_id, target_locale, source_hash, detected_source_locale, translated_title, translated_body, provider, created_at)
                 VALUES (:entity_type, :entity_id, :target_locale, :source_hash, :detected_source_locale, :translated_title, :translated_body, :provider, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    source_hash = VALUES(source_hash),
                    detected_source_locale = VALUES(detected_source_locale),
                    translated_title = VALUES(translated_title),
                    translated_body = VALUES(translated_body),
                    provider = VALUES(provider),
                    created_at = VALUES(created_at)',
                [
                    'entity_type'             => $entityType,
                    'entity_id'               => $entityId,
                    'target_locale'           => $targetLocale,
                    'source_hash'             => $sourceHash,
                    'detected_source_locale'  => $detectedSourceLocale,
                    'translated_title'        => $translatedTitle,
                    'translated_body'         => $translatedBody,
                    'provider'                => $provider,
                ],
            );

            return;
        }

        // Portable fallback for the SQLite test harness (IntegrationTestCase)
        // — no ON DUPLICATE KEY UPDATE support there; select-then-write
        // within a single call is fine since this table has no concurrent-
        // write race to protect against beyond what the UNIQUE key already
        // guards (a lost update here just means a redundant provider call
        // next time, never a wrong tenant/entity row).
        $existingId = $this->conn->fetchOne(
            'SELECT id FROM content_translations WHERE entity_type = :entity_type AND entity_id = :entity_id AND target_locale = :target_locale',
            ['entity_type' => $entityType, 'entity_id' => $entityId, 'target_locale' => $targetLocale],
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existingId === false) {
            $this->conn->insert('content_translations', [
                'entity_type'            => $entityType,
                'entity_id'              => $entityId,
                'target_locale'          => $targetLocale,
                'source_hash'            => $sourceHash,
                'detected_source_locale' => $detectedSourceLocale,
                'translated_title'       => $translatedTitle,
                'translated_body'        => $translatedBody,
                'provider'               => $provider,
                'created_at'             => $now,
            ]);

            return;
        }

        $this->conn->update('content_translations', [
            'source_hash'            => $sourceHash,
            'detected_source_locale' => $detectedSourceLocale,
            'translated_title'       => $translatedTitle,
            'translated_body'        => $translatedBody,
            'provider'               => $provider,
            'created_at'             => $now,
        ], ['id' => (int) $existingId]);
    }
}
