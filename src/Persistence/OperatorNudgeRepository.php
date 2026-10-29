<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Votepit\Nudge\OperatorNudgeKind;

/**
 * Persistence of the operator nudges (migrations 0110/0111): the idempotent
 * sent-log, the recipient selection, the candidate queries and the per-kind
 * preference switches.
 *
 * Every query is either scoped to one account (account_id in the WHERE clause)
 * or a deliberately cross-account candidate scan whose result is only ever
 * used to pick an account, then handled per account. Prepared statements only.
 *
 * @phpstan-type OnboardingAccount array{id: int, name: string, slug: string, created_at: string, board_name: string|null, board_slug: string|null, has_public_idea: bool, has_outside_vote: bool}
 * @phpstan-type BoardActivity array{board_id: int, account_id: int, account_slug: string, account_name: string, board_name: string, board_slug: string, last_activity: string|null}
 * @phpstan-type Recipient array{id: int, email: string, language: string}
 */
final readonly class OperatorNudgeRepository
{
    /** Languages the operator mails are rendered in (NULL/unknown → en). */
    private const LANGUAGES = ['de', 'en'];

    public function __construct(private Connection $conn) {}

    /**
     * Claims one nudge for one recipient. Returns false when that exact nudge
     * was already claimed (sent, or sending right now) — the UNIQUE key is the
     * idempotency guard.
     *
     * @throws DbalException
     */
    public function claim(int $userId, int $accountId, OperatorNudgeKind $kind, string $scope, \DateTimeImmutable $at): bool
    {
        try {
            $this->conn->insert('operator_nudge_log', [
                'user_id'    => $userId,
                'account_id' => $accountId,
                'kind'       => $kind->value,
                'scope'      => $scope,
                'sent_at'    => $at->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Removes one claim again — used when the send failed, so the next run retries it.
     *
     * @throws DbalException
     */
    public function release(int $userId, int $accountId, OperatorNudgeKind $kind, string $scope): void
    {
        $this->conn->executeStatement(
            'DELETE FROM operator_nudge_log WHERE user_id = :user_id AND account_id = :account_id AND kind = :kind AND scope = :scope',
            ['user_id' => $userId, 'account_id' => $accountId, 'kind' => $kind->value, 'scope' => $scope],
        );
    }

    /**
     * Re-arms a nudge instance for everyone of one account (condition cleared:
     * activity came back, or usage dropped below the threshold).
     *
     * @throws DbalException
     */
    public function resetScope(int $accountId, OperatorNudgeKind $kind, string $scope): void
    {
        $this->conn->executeStatement(
            'DELETE FROM operator_nudge_log WHERE account_id = :account_id AND kind = :kind AND scope = :scope',
            ['account_id' => $accountId, 'kind' => $kind->value, 'scope' => $scope],
        );
    }

    /**
     * True when the nudge instance was already sent to anyone of the account.
     *
     * @throws DbalException
     */
    public function scopeSent(int $accountId, OperatorNudgeKind $kind, string $scope): bool
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM operator_nudge_log WHERE account_id = :account_id AND kind = :kind AND scope = :scope',
            ['account_id' => $accountId, 'kind' => $kind->value, 'scope' => $scope],
        ) > 0;
    }

    /**
     * True when any nudge of this kind went out to the account since $since
     * (onboarding: "never both mails on the same day").
     *
     * @throws DbalException
     */
    public function kindSentSince(int $accountId, OperatorNudgeKind $kind, \DateTimeImmutable $since): bool
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM operator_nudge_log WHERE account_id = :account_id AND kind = :kind AND sent_at >= :since',
            ['account_id' => $accountId, 'kind' => $kind->value, 'since' => $since->format('Y-m-d H:i:s')],
        ) > 0;
    }

    /**
     * Deletes onboarding claims older than $before — they only matter inside
     * the 14-day onboarding window. Other kinds are never purged (their
     * re-arming is condition-driven, see resetScope()).
     *
     * @throws DbalException
     */
    public function purgeOnboardingBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM operator_nudge_log WHERE kind = :kind AND sent_at < :before',
            ['kind' => OperatorNudgeKind::Onboarding->value, 'before' => $before->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Owners of one account who should receive this kind of nudge: confirmed
     * notification e-mail only, not blocked, the kind's switch still on, and
     * active since $activeSince (decay, owner decision 6; null = no activity
     * requirement, see OperatorNudgeKind::requiresRecentActivity()). "Active" is
     * users.last_seen_at (maintained by AuthNMiddleware). Only where it was
     * never recorded (NULL, users from before migration 0103) the older
     * signals apply: last login token, verification, creation. Admins only
     * with the same explicit opt-in as the weekly digest; moderators never.
     *
     * @return list<Recipient>
     * @throws DbalException
     */
    public function recipientsFor(int $accountId, OperatorNudgeKind $kind, ?\DateTimeImmutable $activeSince): array
    {
        // $kind->preferenceColumn() is a closed allow-list constant, never input.
        $column = $kind->preferenceColumn();

        $rows = $this->conn->fetchAllAssociative(
            "SELECT u.id, u.notification_email, u.digest_language
             FROM users u
             INNER JOIN account_members am ON am.user_id = u.id AND am.account_id = :account_id
             INNER JOIN accounts a ON a.id = am.account_id
             WHERE a.locked_at IS NULL
               AND u.notification_email IS NOT NULL
               AND u.is_blocked = 0
               AND u.is_deleted_placeholder = 0
               AND u.is_guest_placeholder = 0
               AND u.{$column} = 1
               AND (am.role = 'owner' OR (am.role = 'admin' AND u.notify_weekly_digest_email = 1))
               AND (:active_since IS NULL OR COALESCE(
                       u.last_seen_at,
                       (SELECT MAX(lt.used_at) FROM login_tokens lt WHERE lt.user_id = u.id AND lt.purpose = 'login'),
                       u.verified_at,
                       u.created_at
                   ) >= :active_since2)
             ORDER BY u.id ASC",
            [
                'account_id'    => $accountId,
                'active_since'  => $activeSince?->format('Y-m-d H:i:s'),
                'active_since2' => $activeSince?->format('Y-m-d H:i:s'),
            ],
            ['account_id' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $r): array => [
                'id'       => (int) $r['id'],
                'email'    => (string) $r['notification_email'],
                'language' => is_string($r['digest_language']) && in_array($r['digest_language'], self::LANGUAGES, true) ? $r['digest_language'] : 'en',
            ],
            $rows,
        );
    }

    /**
     * Tenant accounts created inside the onboarding window, with their first
     * live board and the two onboarding facts computed in SQL: whether any board of the account shows
     * a published idea on a public board, and whether anyone who is not a
     * member of the account has voted on it.
     *
     * @return list<OnboardingAccount>
     * @throws DbalException
     */
    public function onboardingAccountsCreatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT a.id, a.name, a.slug, a.created_at,
                    (SELECT b.name FROM boards b WHERE b.account_id = a.id AND b.locked_at IS NULL ORDER BY b.id ASC LIMIT 1) AS board_name,
                    (SELECT b.slug FROM boards b WHERE b.account_id = a.id AND b.locked_at IS NULL ORDER BY b.id ASC LIMIT 1) AS board_slug,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM ideas i
                        INNER JOIN boards b ON b.id = i.board_id
                        WHERE b.account_id = a.id AND b.visibility = 'public'
                          AND i.deleted_at IS NULL AND i.moderation_state = 'published'
                    ) THEN 1 ELSE 0 END AS has_public_idea,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM votes v
                        INNER JOIN ideas i ON i.id = v.idea_id
                        INNER JOIN boards b ON b.id = i.board_id
                        WHERE b.account_id = a.id
                          AND NOT EXISTS (
                              SELECT 1 FROM account_members m WHERE m.account_id = a.id AND m.user_id = v.user_id
                          )
                    ) THEN 1 ELSE 0 END AS has_outside_vote
             FROM accounts a
             WHERE a.is_default = 0
               AND a.locked_at IS NULL
               AND a.created_at >= :from
               AND a.created_at <= :to
             ORDER BY a.id ASC",
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $r): array => [
                'id'               => (int) $r['id'],
                'name'             => (string) $r['name'],
                'slug'             => (string) $r['slug'],
                'created_at'       => (string) $r['created_at'],
                'board_name'       => is_string($r['board_name'] ?? null) ? $r['board_name'] : null,
                'board_slug'       => is_string($r['board_slug'] ?? null) ? $r['board_slug'] : null,
                'has_public_idea'  => (int) $r['has_public_idea'] === 1,
                'has_outside_vote' => (int) $r['has_outside_vote'] === 1,
            ],
            $rows,
        );
    }

    /**
     * Every unlocked account (self-host: the one installation account, which is
     * the default account and therefore excluded from the cloud-only candidate scans).
     *
     * @return list<array{id: int, name: string}>
     * @throws DbalException
     */
    public function unlockedAccounts(): array
    {
        $rows = $this->conn->fetchAllAssociative('SELECT id, name FROM accounts WHERE locked_at IS NULL ORDER BY id ASC');

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $rows,
        );
    }

    /**
     * Every live board of an unlocked tenant account with its most recent
     * idea, vote and comment (null = never active). The caller decides what
     * "quiet" means; the newest of the three timestamps is the last activity.
     *
     * @return list<BoardActivity>
     * @throws DbalException
     */
    public function boardActivity(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT b.id AS board_id, b.account_id, a.slug AS account_slug, a.name AS account_name, b.name AS board_name, b.slug AS board_slug,
                    (SELECT MAX(i.created_at) FROM ideas i WHERE i.board_id = b.id AND i.deleted_at IS NULL) AS idea_at,
                    (SELECT MAX(v.created_at) FROM votes v INNER JOIN ideas i ON i.id = v.idea_id WHERE i.board_id = b.id) AS vote_at,
                    (SELECT MAX(c.created_at) FROM comments c INNER JOIN ideas i ON i.id = c.idea_id WHERE i.board_id = b.id) AS comment_at
             FROM boards b
             INNER JOIN accounts a ON a.id = b.account_id
             WHERE a.is_default = 0
               AND a.locked_at IS NULL
               AND b.locked_at IS NULL
               AND b.frozen_at IS NULL
             ORDER BY b.id ASC",
        );

        return array_map(
            static function (array $r): array {
                $times = array_filter(
                    [$r['idea_at'] ?? null, $r['vote_at'] ?? null, $r['comment_at'] ?? null],
                    static fn (mixed $t): bool => is_string($t) && $t !== '',
                );

                return [
                    'board_id'      => (int) $r['board_id'],
                    'account_id'    => (int) $r['account_id'],
                    'account_slug'  => (string) $r['account_slug'],
                    'account_name' => (string) $r['account_name'],
                    'board_name'    => (string) $r['board_name'],
                    'board_slug'    => (string) $r['board_slug'],
                    'last_activity' => $times === [] ? null : max($times),
                ];
            },
            $rows,
        );
    }

    /**
     * The caller's three nudge switches (default on).
     *
     * @return array<string, bool> keyed by OperatorNudgeKind::value
     * @throws DbalException
     */
    public function preferences(int $userId): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT notify_onboarding_nudge_email, notify_quiet_board_email, notify_plan_limit_email, notify_update_available_email FROM users WHERE id = :id',
            ['id' => $userId],
        );

        $prefs = [];
        foreach (OperatorNudgeKind::all() as $kind) {
            $prefs[$kind->value] = is_array($row) && (int) ($row[$kind->preferenceColumn()] ?? 1) === 1;
        }

        return $prefs;
    }

    /**
     * Sets one kind's switch for one user (self-service or one-click unsubscribe).
     *
     * @throws DbalException
     */
    public function setPreference(int $userId, OperatorNudgeKind $kind, bool $enabled): void
    {
        $this->conn->executeStatement(
            "UPDATE users SET {$kind->preferenceColumn()} = :enabled WHERE id = :id",
            ['enabled' => $enabled ? 1 : 0, 'id' => $userId],
        );
    }
}
