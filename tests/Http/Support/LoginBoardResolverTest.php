<?php

declare(strict_types=1);

namespace Votepit\Tests\Http\Support;

use Votepit\Http\Support\LoginBoardResolver;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Regression coverage for the POST /login per-board SMTP resolution.
 *
 * Root cause: in cloud mode, `returnTo` is
 * `/{accountSlug}/{boardSlug}/...` — the first path segment is the
 * ACCOUNT, not the board (unlike self-host, where the first segment IS
 * the board slug). The old inline AppFactory.php code always treated the
 * first segment as a board slug, so it essentially never resolved a
 * board's own SMTP settings in cloud mode.
 */
final class LoginBoardResolverTest extends IntegrationTestCase
{
    public function test_self_host_mode_resolves_the_board_from_the_first_segment(): void
    {
        $boardId = $this->insertBoard('demo');

        $resolved = LoginBoardResolver::resolve(
            '/demo/idea/26',
            'self-host',
            new BoardRepository($this->conn),
            new AccountRepository($this->conn),
            $this->defaultAccountId(),
        );

        self::assertSame($boardId, $resolved);
    }

    public function test_cloud_mode_resolves_the_board_from_the_second_segment_within_the_matching_account(): void
    {
        $accountId = $this->insertAccount(['slug' => 'stageing-test']);
        $boardId   = $this->insertBoard('stage', ['account_id' => $accountId]);

        // A board with the SAME slug in a DIFFERENT account — must not be
        // picked (would be the pre-fix bug's cross-tenant-flavored failure
        // mode if the account weren't resolved from the correct segment).
        $this->insertBoard('stage', ['account_id' => $this->defaultAccountId()]);

        $resolved = LoginBoardResolver::resolve(
            '/stageing-test/stage/idea/26',
            'cloud',
            new BoardRepository($this->conn),
            new AccountRepository($this->conn),
            $this->defaultAccountId(),
        );

        self::assertSame($boardId, $resolved);
    }

    public function test_cloud_mode_with_unknown_account_slug_resolves_to_null(): void
    {
        $resolved = LoginBoardResolver::resolve(
            '/no-such-account/stage/idea/26',
            'cloud',
            new BoardRepository($this->conn),
            new AccountRepository($this->conn),
            $this->defaultAccountId(),
        );

        self::assertNull($resolved);
    }

    public function test_cloud_mode_with_only_one_segment_resolves_to_null(): void
    {
        $resolved = LoginBoardResolver::resolve(
            '/stageing-test',
            'cloud',
            new BoardRepository($this->conn),
            new AccountRepository($this->conn),
            $this->defaultAccountId(),
        );

        self::assertNull($resolved);
    }

    public function test_empty_return_to_resolves_to_null(): void
    {
        $resolved = LoginBoardResolver::resolve(
            '',
            'cloud',
            new BoardRepository($this->conn),
            new AccountRepository($this->conn),
            $this->defaultAccountId(),
        );

        self::assertNull($resolved);
    }
}
