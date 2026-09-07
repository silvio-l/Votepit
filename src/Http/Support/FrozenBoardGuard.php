<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "reject a write on a downgrade-frozen board" guard (part of the
 * upgrade/downgrade/cancellation lifecycle).
 *
 * Deliberately a tiny shared static helper rather than a middleware: every
 * write action below already loads the board array itself (via
 * BoardRepository::findBySlugForAccount(), which now also selects
 * `frozen_at`) before doing anything else — exactly the same "thin inline
 * guard, no central middleware" pattern the board-scoped user-block checks
 * already use in these same actions
 * (BlockRepository::isBlocked() calls right next to this one). A route-level
 * middleware would need the board slug re-resolved from the path a second
 * time; reusing the board array the action already has avoids that.
 *
 * Read paths are NEVER guarded by this — a frozen board's public page, idea
 * list/detail and comments must keep rendering exactly as before (see
 * migrations/0016_add_board_freeze_and_deletion_reminder.sql's class doc for
 * why this is a second column, distinct from the operator's locked_at).
 */
final class FrozenBoardGuard
{
    /** @param array<string, mixed> $board */
    public static function isFrozen(array $board): bool
    {
        return ($board['frozen_at'] ?? null) !== null;
    }

    public static function reject(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'board_frozen',
                'message' => 'This board is frozen (read-only) and cannot be edited at the moment.',
            ],
        ]));

        return $response->withStatus(423)->withHeader('Content-Type', 'application/json');
    }
}
