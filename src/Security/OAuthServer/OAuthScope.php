<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Votepit\Security\ApiTokenScope;

/**
 * OAuth `scope` values of the MCP OAuth server. They are the per-board
 * grant levels of an API token (ApiTokenScope: read < write < moderate) —
 * no second permission model. A requested scope only CAPS what the consent
 * screen may grant; the user picks the actual level per board, and the
 * resulting grant carries one level per board exactly like a static token.
 */
final class OAuthScope
{
    /**
     * Levels an OAuth client may request. `manage` (branding) is never granted
     * over OAuth: the MCP server's OAuth grant stays at moderate (migration 0117).
     */
    public const SUPPORTED = [ApiTokenScope::READ, ApiTokenScope::WRITE, ApiTokenScope::MODERATE];

    /** Strict order, lowest first. */
    private const RANK = [ApiTokenScope::READ => 1, ApiTokenScope::WRITE => 2, ApiTokenScope::MODERATE => 3];

    /**
     * Highest level named in a space-separated scope string. An absent/empty
     * scope means "no cap" (moderate). Returns null for any unknown value —
     * the caller answers `invalid_scope` (fail-secure, nothing is guessed).
     */
    public static function maxLevel(?string $scope): ?string
    {
        if ($scope === null || trim($scope) === '') {
            return ApiTokenScope::MODERATE;
        }

        $max = null;
        foreach (explode(' ', preg_replace('/ +/', ' ', trim($scope)) ?? '') as $value) {
            if (!isset(self::RANK[$value])) {
                return null;
            }
            if ($max === null || self::RANK[$value] > self::RANK[$max]) {
                $max = $value;
            }
        }

        return $max;
    }

    /** True if $level is a known level not above $cap. */
    public static function within(string $level, string $cap): bool
    {
        return isset(self::RANK[$level], self::RANK[$cap]) && self::RANK[$level] <= self::RANK[$cap];
    }

    /**
     * The `scope` value reported in a token response: the distinct levels the
     * grant holds on at least one board, lowest first.
     *
     * @param array<int, string> $boardScopes board_id => level
     */
    public static function grantedScope(array $boardScopes): string
    {
        $levels = array_values(array_unique(array_values($boardScopes)));
        usort($levels, static fn (string $a, string $b): int => (self::RANK[$a] ?? 0) <=> (self::RANK[$b] ?? 0));

        return implode(' ', $levels);
    }
}
