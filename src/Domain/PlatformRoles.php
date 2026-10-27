<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Central platform-operator predicate (`users.is_operator`). The one place that
 * reads the flag from a hydrated user array; AuthZMiddleware::operator() and the
 * plan rule in EffectivePlan both go through it.
 *
 * Guard: tests/Architecture/CentralGatingGuardTest.php fails on `is_operator`
 * key reads outside the allow-listed files.
 */
final class PlatformRoles
{
    public static function isOperator(mixed $user): bool
    {
        return is_array($user) && (bool) ($user['is_operator'] ?? false);
    }
}
