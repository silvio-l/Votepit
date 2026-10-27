<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Central account-role predicates. Code that decides what a membership role may
 * do calls these instead of comparing role strings inline.
 *
 * Guard: tests/Architecture/CentralGatingGuardTest.php fails on literal
 * owner/admin/moderator comparisons outside the allow-listed files.
 */
final class AccountRoles
{
    public static function isOwner(?string $role): bool
    {
        return $role === 'owner';
    }

    /** Owner or admin: board/member administration, admin panel, delegated grants. */
    public static function canAdminister(?string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    /** Owner, admin or moderator: moderation queues and bypass of premoderation. */
    public static function canModerate(?string $role): bool
    {
        return in_array($role, ['owner', 'admin', 'moderator'], true);
    }
}
