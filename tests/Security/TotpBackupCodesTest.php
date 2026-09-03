<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\TotpBackupCodes;

final class TotpBackupCodesTest extends TestCase
{
    public function test_generate_returns_10_unique_codes_in_xxxx_xxxx_format(): void
    {
        $codes = new TotpBackupCodes();
        $list  = $codes->generate();

        self::assertCount(10, $list);
        self::assertCount(10, array_unique($list));
        foreach ($list as $code) {
            self::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/', $code);
        }
    }

    public function test_generate_avoids_ambiguous_characters(): void
    {
        $codes = new TotpBackupCodes();
        $list  = $codes->generate();

        $joined = implode('', $list);
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            self::assertStringNotContainsString($ambiguous, $joined);
        }
    }

    public function test_hash_is_stable_and_case_insensitive(): void
    {
        $codes = new TotpBackupCodes();

        self::assertSame($codes->hash('ABCD-2345'), $codes->hash('abcd-2345'));
        self::assertSame($codes->hash('ABCD-2345'), $codes->hash(' ABCD-2345 '));
    }

    public function test_hash_differs_for_different_codes(): void
    {
        $codes = new TotpBackupCodes();

        self::assertNotSame($codes->hash('ABCD-2345'), $codes->hash('ABCD-2346'));
    }
}
