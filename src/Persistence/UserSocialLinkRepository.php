<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Persistence for a user's 4 fixed social-profile identifiers
 * (profile-avatar-social security redesign, sprint social-links-structured).
 * User-scoped, NOT account-scoped — a user's profile is the same across
 * every account they're a member of (mirrors how `users` itself is global,
 * ADR 0001 §2c).
 *
 * Storage: 4 discrete nullable columns directly on `users`
 * (migrations/0020_replace_social_links_with_fixed_fields.sql) — replaces
 * the earlier `user_social_links` child table (a JSON-array-shaped list of
 * up to 5 free-form label+URL rows, migrations/0019, dropped by 0020). A
 * fixed 1-per-user identifier per platform has no order/position and no
 * per-row id, so a single-row update on `users` is the simplest correct
 * model — no more replace-the-whole-list semantics.
 *
 * Values are validated by SocialLinkValidator BEFORE they ever reach this
 * class — this class trusts its input the same way every other Repository
 * does (BoardRepository::updateBranding() etc.).
 */
final readonly class UserSocialLinkRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * @return array{website_domain: ?string, x_handle: ?string, youtube_handle: ?string, github_username: ?string}
     * @throws DbalException
     */
    public function getForUser(int $userId): array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT website_domain, x_handle, youtube_handle, github_username FROM users WHERE id = :user_id',
            ['user_id' => $userId],
        );

        if ($row === false) {
            return [
                'website_domain'  => null,
                'x_handle'        => null,
                'youtube_handle'  => null,
                'github_username' => null,
            ];
        }

        return [
            'website_domain'  => is_string($row['website_domain'] ?? null) ? $row['website_domain'] : null,
            'x_handle'        => is_string($row['x_handle'] ?? null) ? $row['x_handle'] : null,
            'youtube_handle'  => is_string($row['youtube_handle'] ?? null) ? $row['youtube_handle'] : null,
            'github_username' => is_string($row['github_username'] ?? null) ? $row['github_username'] : null,
        ];
    }

    /**
     * Overwrites exactly the given fields for this user (any subset of the
     * 4 columns — caller passes only what changed, or all 4 for a full
     * replace). Each value is either a validated normalized string, or null
     * to clear that field. Caller (AccountProfileAction) has already
     * validated every non-null value before calling this.
     *
     * @param array{website_domain?: ?string, x_handle?: ?string, youtube_handle?: ?string, github_username?: ?string} $fields
     * @throws DbalException
     */
    public function updateForUser(int $userId, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->conn->update('users', $fields, ['id' => $userId]);
    }
}
