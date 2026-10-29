<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Per-board grant levels of an Agent API token (api_token_boards.scope,
 * migrations 0047 + 0081). Strictly ordered, each level includes the ones
 * below it:
 *
 *  - `read`     — board/idea reads (published ideas only).
 *  - `write`    — plus contributor writes: create an idea, vote, comment.
 *                 Acts like a regular (non-moderating) member: on a board
 *                 with pre-moderation, an idea created with this grant waits
 *                 for approval.
 *  - `moderate` — plus moderator actions: status change, tag assign/remove,
 *                 merge/unmerge, reading pending ideas; ideas created with it
 *                 publish immediately. An EXPLICIT grant (never implied by
 *                 `write`), and only effective while the token's issuing
 *                 user still holds a moderating account role (owner/admin/
 *                 moderator) — see ApiIdeaWriteAction.
 *
 * Unknown values grant nothing beyond reading (fail-secure).
 */
final class ApiTokenScope
{
    public const READ     = 'read';
    public const WRITE    = 'write';
    public const MODERATE = 'moderate';
    public const MANAGE   = 'manage';

    /** Allow-list for the token admin (creation) and the DB CHECK constraint. */
    public const ALL = [self::READ, self::WRITE, self::MODERATE, self::MANAGE];

    public static function allowsWrite(string $scope): bool
    {
        return in_array($scope, [self::WRITE, self::MODERATE, self::MANAGE], true);
    }

    public static function allowsModerate(string $scope): bool
    {
        return $scope === self::MODERATE || $scope === self::MANAGE;
    }

    public static function allowsManage(string $scope): bool
    {
        return $scope === self::MANAGE;
    }
}
