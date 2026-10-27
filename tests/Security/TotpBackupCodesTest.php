<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\TotpBackupCodes;

final class TotpBackupCodesTest extends TestCase
{
    public function test_generate_returns_10_unique_codes_in_grouped_format(): void
    {
        $codes = new TotpBackupCodes();
        $list  = $codes->generate();

        self::assertCount(10, $list);
        self::assertCount(10, array_unique($list));
        $group = '[A-HJKMNP-Z2-9]{4}';
        foreach ($list as $code) {
            self::assertMatchesRegularExpression("/^{$group}(-{$group}){6}\$/", $code);
        }
    }

    /** Backup-code format must carry at least 128 bits of entropy (OWASP/BSI audit issue 03). */
    public function test_generated_code_carries_at_least_128_bits_of_entropy(): void
    {
        $codes    = new TotpBackupCodes();
        $sample   = $codes->generate()[0];
        $charCount = strlen(str_replace('-', '', $sample));
        $alphabetSize = 32; // A-HJKMNP-Z2-9, no 0/O/1/I/L

        $bits = $charCount * log($alphabetSize, 2);
        self::assertGreaterThanOrEqual(128.0, $bits);
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

    public function test_hash_is_argon2id_and_self_salting(): void
    {
        $codes = new TotpBackupCodes();

        $hashA = $codes->hash('ABCD-2345');
        $hashB = $codes->hash('abcd-2345'); // case-insensitive input, same normalized code

        self::assertStringStartsWith('$argon2id$', $hashA);
        // Argon2id embeds a random salt — hashing the SAME code twice never
        // produces the same stored hash (unlike the old bare SHA-256 scheme).
        self::assertNotSame($hashA, $hashB);
    }

    public function test_verify_accepts_the_matching_code_against_an_argon2id_hash(): void
    {
        $codes = new TotpBackupCodes();
        $hash  = $codes->hash('ABCD-2345');

        self::assertTrue($codes->verify('ABCD-2345', $hash));
        self::assertTrue($codes->verify('abcd-2345', $hash)); // case-insensitive
        self::assertTrue($codes->verify(' ABCD-2345 ', $hash)); // trimmed
        self::assertFalse($codes->verify('ABCD-2346', $hash));
    }

    /**
     * Codes issued before the Argon2id migration are stored as bare
     * hash('sha256', ...) hex digests — verify() must keep accepting those
     * so an existing user's codes don't silently stop working (PRD: no
     * breaking change without the user's own action).
     */
    public function test_verify_accepts_a_legacy_sha256_hash(): void
    {
        $codes      = new TotpBackupCodes();
        $legacyHash = hash('sha256', 'ABCD-2345');

        self::assertTrue($codes->verify('ABCD-2345', $legacyHash));
        self::assertTrue($codes->verify('abcd-2345', $legacyHash));
        self::assertFalse($codes->verify('ABCD-2346', $legacyHash));
    }
}
