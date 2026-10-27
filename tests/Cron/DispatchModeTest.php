<?php

declare(strict_types=1);

namespace Votepit\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Votepit\ConfigException;
use Votepit\Cron\DispatchMode;

final class DispatchModeTest extends TestCase
{
    public function test_an_unset_mode_defaults_to_inline(): void
    {
        self::assertSame(DispatchMode::Inline, DispatchMode::fromRaw(null));
    }

    public function test_the_two_known_modes_parse(): void
    {
        self::assertSame(DispatchMode::Inline, DispatchMode::fromRaw('inline'));
        self::assertSame(DispatchMode::Detached, DispatchMode::fromRaw('detached'));
    }

    public function test_an_unknown_mode_fails_closed_at_boot(): void
    {
        $this->expectException(ConfigException::class);
        DispatchMode::fromRaw('fork-bomb');
    }
}
