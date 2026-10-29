<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Persistence for idea_issue_links (issue created in Linear/GitHub from an
 * idea, migrations/0080_add_issue_tracker_links.sql).
 *
 * Account scoping: every method binds account_id, and create() only links
 * an idea whose board belongs to that account (defense in depth on top of
 * the caller's account-scoped board/idea lookup). The webhook lookup
 * findForSync() resolves the idea's board THROUGH the same account, so a
 * delivery for account A can never reach an idea of account B.
 *
 * @phpstan-type LinkRow array{provider: string, external_key: string, url: string, created_at: string}
 */
final readonly class IdeaIssueLinkRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return list<LinkRow>
     * @throws DbalException
     */
    public function listForIdea(int $accountId, int $ideaId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT provider, external_key, url, created_at FROM idea_issue_links
              WHERE account_id = :account_id AND idea_id = :idea_id ORDER BY provider',
            ['account_id' => $accountId, 'idea_id' => $ideaId],
        );

        return array_map(static fn (array $r): array => [
            'provider'     => (string) $r['provider'],
            'external_key' => (string) $r['external_key'],
            'url'          => (string) $r['url'],
            'created_at'   => (string) $r['created_at'],
        ], $rows);
    }

    /** @throws DbalException */
    public function exists(int $accountId, int $ideaId, string $provider): bool
    {
        return $this->conn->fetchOne(
            'SELECT 1 FROM idea_issue_links WHERE account_id = :account_id AND idea_id = :idea_id AND provider = :provider',
            ['account_id' => $accountId, 'idea_id' => $ideaId, 'provider' => $provider],
        ) !== false;
    }

    /**
     * Returns false — and writes nothing — when the idea is not in one of
     * $accountId's boards or a link for (idea, provider) already exists.
     *
     * @throws DbalException
     */
    public function create(int $accountId, int $ideaId, string $provider, string $externalId, string $externalKey, string $url, int $createdBy): bool
    {
        $owned = $this->conn->fetchOne(
            'SELECT 1 FROM ideas i INNER JOIN boards b ON b.id = i.board_id WHERE i.id = :idea_id AND b.account_id = :account_id',
            ['idea_id' => $ideaId, 'account_id' => $accountId],
        );
        if ($owned === false) {
            return false;
        }

        try {
            $this->conn->insert('idea_issue_links', [
                'account_id'   => $accountId,
                'idea_id'      => $ideaId,
                'provider'     => $provider,
                'external_id'  => $externalId,
                'external_key' => $externalKey,
                'url'          => $url,
                'created_by'   => $createdBy > 0 ? $createdBy : null,
                'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** @throws DbalException */
    public function delete(int $accountId, int $ideaId, string $provider): bool
    {
        return $this->conn->executeStatement(
            'DELETE FROM idea_issue_links WHERE account_id = :account_id AND idea_id = :idea_id AND provider = :provider',
            ['account_id' => $accountId, 'idea_id' => $ideaId, 'provider' => $provider],
        ) === 1;
    }

    /**
     * Webhook status sync: the linked idea and its board, resolved strictly
     * inside $accountId (link AND board must belong to it).
     *
     * @return array{idea_id: int, board_slug: string}|null
     * @throws DbalException
     */
    public function findForSync(int $accountId, string $provider, string $externalId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT l.idea_id, b.slug AS board_slug
               FROM idea_issue_links l
               INNER JOIN ideas i ON i.id = l.idea_id
               INNER JOIN boards b ON b.id = i.board_id
              WHERE l.account_id = :account_id AND l.provider = :provider AND l.external_id = :external_id
                AND b.account_id = :board_account_id',
            ['account_id' => $accountId, 'provider' => $provider, 'external_id' => $externalId, 'board_account_id' => $accountId],
        );

        return is_array($row) ? ['idea_id' => (int) $row['idea_id'], 'board_slug' => (string) $row['board_slug']] : null;
    }
}
