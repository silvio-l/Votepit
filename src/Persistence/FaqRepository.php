<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * FAQ persistence (operator-maintained knowledge base, migrations/
 * 0023_add_support_and_faq.sql). Platform-wide — NOT account-scoped, one
 * shared list across every tenant, same as legal footer links.
 */
final readonly class FaqRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Published entries only, ordered for display — for the contact-form
     * deflection and the standalone FAQ view. Both languages are always
     * returned; the caller picks question_{lang}/answer_{lang}.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listPublished(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, category, question_de, question_en, answer_de, answer_en, sort_order
             FROM faq_entries WHERE is_published = 1 ORDER BY category ASC, sort_order ASC, id ASC',
        );

        return $rows;
    }

    /**
     * Every entry, including unpublished drafts — operator-only.
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listAllForOperator(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, category, question_de, question_en, answer_de, answer_en,
                    sort_order, is_published, created_at, updated_at
             FROM faq_entries ORDER BY category ASC, sort_order ASC, id ASC',
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, category, question_de, question_en, answer_de, answer_en,
                    sort_order, is_published, created_at, updated_at
             FROM faq_entries WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /** @throws DbalException */
    public function create(
        string $category,
        string $questionDe,
        string $questionEn,
        string $answerDe,
        string $answerEn,
        int $sortOrder,
        bool $isPublished,
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conn->insert('faq_entries', [
            'category'     => $category,
            'question_de'  => $questionDe,
            'question_en'  => $questionEn,
            'answer_de'    => $answerDe,
            'answer_en'    => $answerEn,
            'sort_order'   => $sortOrder,
            'is_published' => $isPublished ? 1 : 0,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return (int) $this->conn->lastInsertId();
    }

    /** @throws DbalException */
    public function update(
        int $id,
        string $category,
        string $questionDe,
        string $questionEn,
        string $answerDe,
        string $answerEn,
        int $sortOrder,
        bool $isPublished,
    ): bool {
        $affected = $this->conn->executeStatement(
            'UPDATE faq_entries
             SET category = :category, question_de = :question_de, question_en = :question_en,
                 answer_de = :answer_de, answer_en = :answer_en, sort_order = :sort_order,
                 is_published = :is_published, updated_at = :now
             WHERE id = :id',
            [
                'category'     => $category,
                'question_de'  => $questionDe,
                'question_en'  => $questionEn,
                'answer_de'    => $answerDe,
                'answer_en'    => $answerEn,
                'sort_order'   => $sortOrder,
                'is_published' => $isPublished ? 1 : 0,
                'now'          => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'           => $id,
            ],
        );

        return $affected > 0;
    }

    /** @throws DbalException */
    public function delete(int $id): bool
    {
        $affected = $this->conn->executeStatement('DELETE FROM faq_entries WHERE id = :id', ['id' => $id]);
        return $affected > 0;
    }
}
