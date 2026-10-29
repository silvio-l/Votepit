<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\SemanticVersion;

final class SemanticVersionTest extends TestCase
{
    public function test_only_plain_major_minor_patch_is_valid(): void
    {
        self::assertTrue(SemanticVersion::isValid('0.1.8'));
        self::assertTrue(SemanticVersion::isValid('10.0.12'));
        self::assertFalse(SemanticVersion::isValid('0.1'));
        self::assertFalse(SemanticVersion::isValid('1.0.0-rc1'));
        self::assertFalse(SemanticVersion::isValid("0.1.8\n"));
        self::assertFalse(SemanticVersion::isValid('v0.1.8'));
    }

    public function test_newer_compares_numerically(): void
    {
        self::assertTrue(SemanticVersion::isNewer('0.1.8', '0.1.7'));
        self::assertTrue(SemanticVersion::isNewer('0.1.10', '0.1.9'));
        self::assertFalse(SemanticVersion::isNewer('0.1.7', '0.1.7'));
        self::assertFalse(SemanticVersion::isNewer('0.1.6', '0.1.7'));
        self::assertFalse(SemanticVersion::isNewer('garbage', '0.1.7'));
    }

    public function test_tags_are_mapped_to_versions_and_other_names_are_rejected(): void
    {
        self::assertSame('0.1.8', SemanticVersion::fromTag('v0.1.8'));
        self::assertNull(SemanticVersion::fromTag('latest'));
        self::assertNull(SemanticVersion::fromTag('v0.1.8-beta'));
    }
}
