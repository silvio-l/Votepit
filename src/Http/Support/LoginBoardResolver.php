<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;

/**
 * Resolves the board a POST /login request's `returnTo` path points at, so
 * the per-board SMTP config (SmtpConfigResolver) can be used for the magic
 * link mail instead of the installation-wide fallback.
 *
 * Self-host mode: `returnTo` is `/{boardSlug}/...` — the first segment IS
 * the board slug, looked up within the single self-host account.
 *
 * Cloud mode: `returnTo` is `/{accountSlug}/{boardSlug}/...` (path-based
 * multi-tenancy) — the first segment is the ACCOUNT, not the board. Using it
 * as a board slug (the self-host assumption) never resolves. The account
 * also can't come from AccountContextMiddleware's request attribute here:
 * `/login` is a global route with no `{account}` route argument, so that
 * attribute always falls back to defaultAccountId(), regardless of which
 * account the visitor actually came from. The account slug is therefore
 * parsed out of `returnTo` itself and resolved directly.
 */
final class LoginBoardResolver
{
    public static function resolve(
        string $returnTo,
        string $routingMode,
        BoardRepository $boardRepo,
        AccountRepository $accountRepo,
        int $selfHostAccountId,
    ): ?int {
        if ($returnTo === '') {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', ltrim($returnTo, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return null;
        }

        if ($routingMode === 'cloud') {
            $boardSlug = $segments[1] ?? null;
            if ($boardSlug === null) {
                return null;
            }
            $account = $accountRepo->findBySlug($segments[0]);
            if ($account === null) {
                return null;
            }
            $accountId = (int) $account['id'];
        } else {
            $boardSlug = $segments[0];
            $accountId = $selfHostAccountId;
        }

        $boardRow = $boardRepo->findBySlugForAccount($boardSlug, $accountId);
        $boardId  = is_array($boardRow) ? (int) ($boardRow['id'] ?? 0) : 0;

        return $boardId === 0 ? null : $boardId;
    }
}
