<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Comment-reaction persistence (migrations/0050_add_comment_reactions.sql).
 *
 * The single DB seam for comment reactions. Prepared-statements-only via
 * DBAL, no query-string concatenation. Deliberately modeled on
 * VoteRepository::cast() rather than a GitHub-style "many reaction types
 * per user" scheme: exactly ONE active reaction per (comment, user) —
 * reacting with the SAME emoji again retracts it, a DIFFERENT emoji
 * switches it in place (no second row, UNIQUE(comment_id, user_id) as the
 * DB backstop).
 *
 * `comment_reactions` has no own board_id/account_id column — scoping is
 * transitive via comment_id -> comments -> ideas -> boards, the same
 * pattern as `comments` itself (see CommentRepository class doc): the
 * caller (CommentReactionAction) loads the comment idea-/board-scoped
 * FIRST via CommentRepository::findForIdea() — only a confirmed-scoped
 * comment_id ever reaches this class.
 *
 * Reactions are a fixed, small predefined set (ALLOWED_REACTIONS +
 * a DB CHECK constraint) — never free text: no user-controlled active
 * content on a shared multi-tenant origin.
 */
final readonly class CommentReactionRepository
{
    /**
     * Fixed, small emoji set — analogous to GitHub's comment reactions.
     * No free-text emoji, no arbitrary Unicode: mirrored in the DB CHECK
     * constraint (migrations/0050_add_comment_reactions.sql).
     *
     * @var list<string>
     */
    public const ALLOWED_REACTIONS = ['👍', '👎', '❤️', '😄', '🎉'];

    public function __construct(private Connection $conn) {}

    public function isAllowed(string $reaction): bool
    {
        return in_array($reaction, self::ALLOWED_REACTIONS, true);
    }

    /**
     * Idempotent toggle core in ONE transaction. $reaction MUST already be
     * validated via isAllowed() (CommentReactionAction). Covers all three
     * cases, mirroring VoteRepository::cast():
     *   - no reaction yet          → INSERT (reaction)
     *   - other reaction exists    → UPDATE to reaction (switch in place)
     *   - same reaction exists     → DELETE (retraction)
     *
     * Exactly one or no row exists per (comment, user) afterward — never
     * two (service logic + DB UNIQUE as a backstop).
     *
     * counts is read within the same transaction (no re-query outside).
     *
     * @return array{my_reaction: ?string, counts: array<string, int>}
     * @throws DbalException
     */
    public function toggle(int $commentId, int $userId, string $reaction): array
    {
        /** @var array{my_reaction: ?string, counts: array<string, int>} $result */
        $result = $this->conn->transactional(
            function (Connection $conn) use ($commentId, $userId, $reaction): array {
                $existing = $conn->fetchOne(
                    'SELECT reaction FROM comment_reactions WHERE comment_id = :comment AND user_id = :user',
                    ['comment' => $commentId, 'user' => $userId],
                );

                if ($existing === false) {
                    $conn->executeStatement(
                        'INSERT INTO comment_reactions (comment_id, user_id, reaction, created_at)
                         VALUES (:comment, :user, :reaction, CURRENT_TIMESTAMP)',
                        ['comment' => $commentId, 'user' => $userId, 'reaction' => $reaction],
                    );
                    $myReaction = $reaction;
                } elseif ((string) $existing === $reaction) {
                    $conn->executeStatement(
                        'DELETE FROM comment_reactions WHERE comment_id = :comment AND user_id = :user',
                        ['comment' => $commentId, 'user' => $userId],
                    );
                    $myReaction = null;
                } else {
                    $conn->executeStatement(
                        'UPDATE comment_reactions SET reaction = :reaction, created_at = CURRENT_TIMESTAMP
                         WHERE comment_id = :comment AND user_id = :user',
                        ['reaction' => $reaction, 'comment' => $commentId, 'user' => $userId],
                    );
                    $myReaction = $reaction;
                }

                return [
                    'my_reaction' => $myReaction,
                    'counts'      => $this->fetchCounts($conn, $commentId),
                ];
            },
        );

        return $result;
    }

    /**
     * Reaction summary (per-emoji counts + the requesting user's own
     * reaction, or null when logged out) for a batch of comment IDs — one
     * COUNT query plus (when $userId is given) one more, instead of N+1
     * across an idea's comment list. Used by IdeaDetailAction.
     *
     * @param list<int> $commentIds
     * @return array<int, array{my_reaction: ?string, counts: array<string, int>}>
     * @throws DbalException
     */
    public function summaryForComments(array $commentIds, ?int $userId): array
    {
        if ($commentIds === []) {
            return [];
        }

        /** @var array<int, array{my_reaction: ?string, counts: array<string, int>}> $summary */
        $summary = [];
        foreach ($commentIds as $id) {
            $summary[$id] = ['my_reaction' => null, 'counts' => []];
        }

        $countRows = $this->conn->fetchAllAssociative(
            'SELECT comment_id, reaction, COUNT(*) AS cnt
             FROM comment_reactions
             WHERE comment_id IN (:ids)
             GROUP BY comment_id, reaction',
            ['ids' => $commentIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($countRows as $row) {
            $commentId = (int) $row['comment_id'];
            if (!isset($summary[$commentId])) {
                continue;
            }
            $summary[$commentId]['counts'][(string) $row['reaction']] = (int) $row['cnt'];
        }

        if ($userId !== null) {
            $mineRows = $this->conn->fetchAllAssociative(
                'SELECT comment_id, reaction FROM comment_reactions WHERE comment_id IN (:ids) AND user_id = :user',
                ['ids' => $commentIds, 'user' => $userId],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($mineRows as $row) {
                $commentId = (int) $row['comment_id'];
                if (!isset($summary[$commentId])) {
                    continue;
                }
                $summary[$commentId]['my_reaction'] = (string) $row['reaction'];
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     * @throws DbalException
     */
    private function fetchCounts(Connection $conn, int $commentId): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT reaction, COUNT(*) AS cnt FROM comment_reactions WHERE comment_id = :comment GROUP BY reaction',
            ['comment' => $commentId],
        );

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['reaction']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
