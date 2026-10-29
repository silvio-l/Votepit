<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\TrustedReleaseKeys;

final class TrustedReleaseKeysTest extends TestCase
{
    public function test_the_shipped_release_keys_are_usable(): void
    {
        self::assertSame([], TrustedReleaseKeys::unusable());
    }
}
