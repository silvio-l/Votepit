<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Per-board pre-moderation policy (broaden-use-cases issue 05, migration
 * 0083). Pure decision logic, no I/O.
 *
 * boards.premoderation is a VARCHAR with this app-side allow-list (no DB
 * CHECK), so the 'anonymous' mode (issue 06 / ADR 0006 §8: only guest
 * ideas wait, members publish immediately) needed no schema change. normalizeMode() is fail-secure: anything other than an exactly
 * known mode is treated as 'all' (approval required), never as 'off'.
 */
final class Premoderation
{
    public const MODE_OFF = 'off';
    public const MODE_ALL = 'all';
    public const MODE_ANONYMOUS = 'anonymous';

    /** Modes an owner may select (settings allow-list). */
    public const MODES = [self::MODE_OFF, self::MODE_ALL, self::MODE_ANONYMOUS];

    public const STATE_PUBLISHED = 'published';
    public const STATE_PENDING   = 'pending';

    public static function normalizeMode(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::MODES, true) ? $raw : self::MODE_ALL;
    }

    /** Roles whose ideas always publish immediately: the same set as AuthZMiddleware accountModerate(). */
    public static function canModerate(?string $role): bool
    {
        return AccountRoles::canModerate($role);
    }

    /** Standing states in which a trusted author keeps the bypass (issue 11). */
    public const TRUST_KEEPING_STATES = ['normal', 'flagged'];

    /**
     * Whether an account_member_standing row (AccountMemberStandingRepository::
     * findFor() shape, null = never moderated) marks a trusted author
     * (broaden-use-cases issue 11, migration 0089). Trust is cleared when the
     * standing drops to warned/suspended; this check additionally refuses a
     * stale trust next to such a state, or next to an unknown state
     * (fail-secure).
     *
     * @param array<string, mixed>|null $standing
     */
    public static function isTrusted(?array $standing): bool
    {
        return is_array($standing)
            && ($standing['trusted_at'] ?? null) !== null
            && in_array($standing['state'] ?? null, self::TRUST_KEEPING_STATES, true);
    }

    /**
     * Whether an idea submitted under $mode must wait for approval. A guest
     * (ADR 0006) has no account role and never bypasses; $role and $trusted
     * are ignored for guests. Under 'anonymous' only guest ideas are held
     * back. A trusted author (issue 11, per account) publishes immediately
     * like a moderator. This is the ONLY place that decides — the session,
     * guest and Agent-API/MCP submit paths all call it.
     */
    public static function requiresApproval(string $mode, ?string $role, bool $isGuest = false, bool $trusted = false): bool
    {
        $mode = self::normalizeMode($mode);
        if ($mode === self::MODE_OFF) {
            return false;
        }
        if ($isGuest) {
            return true;
        }
        if ($mode === self::MODE_ANONYMOUS) {
            return false;
        }

        return !$trusted && !self::canModerate($role);
    }
}
