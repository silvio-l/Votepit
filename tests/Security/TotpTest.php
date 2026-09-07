<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Security\Totp;

/**
 * RFC 6238 Appendix B test vectors (secret "12345678901234567890", SHA1,
 * 30s step). The RFC's published vectors are 8-digit; this implementation is
 * fixed at 6 digits (RFC 6238 §5.3: the N-digit truncation is `binary mod
 * 10^N`, so the 6-digit code is mathematically just the last 6 digits of the
 * published 8-digit vector — verified independently against a reference
 * HOTP computation before being hardcoded here).
 */
final class TotpTest extends TestCase
{
    // RFC 6238 appendix B test vector, base32("12345678901234567890") — public, not a secret.
    private const RFC_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // gitleaks:allow

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        return [
            'T=59'          => [59, '287082'],
            'T=1111111109'  => [1111111109, '081804'],
            'T=1111111111'  => [1111111111, '050471'],
            'T=1234567890'  => [1234567890, '005924'],
            'T=2000000000'  => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    /**
     * verify()'s public surface has no injectable clock (it always uses the
     * real time()), and the RFC vectors are fixed historical/future
     * timestamps — so this pins the actual HOTP/truncation logic via
     * reflection on the private hotp()/base32Decode() helpers instead of
     * going through verify() itself. The "now"-based round-trip is covered
     * separately below (test_verify_accepts_the_current_code_and_rejects_a_wrong_one).
     */
    #[DataProvider('rfcVectors')]
    public function test_matches_rfc6238_vector(int $unixTime, string $expectedCode): void
    {
        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');

        $secret  = $decode->invoke(new Totp(), self::RFC_SECRET_BASE32);
        $counter = intdiv($unixTime, 30);

        self::assertSame($expectedCode, $hotp->invoke(new Totp(), $secret, $counter));
    }

    public function test_generateSecret_returns_32_char_base32(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_generateSecret_is_unique_per_call(): void
    {
        $totp = new Totp();

        self::assertNotSame($totp->generateSecret(), $totp->generateSecret());
    }

    public function test_provisioningUri_contains_secret_issuer_and_label(): void
    {
        $totp = new Totp();
        $uri  = $totp->provisioningUri('JBSWY3DPEHPK3PXP', 'Account #7', 'Votepit');

        self::assertStringStartsWith('otpauth://totp/Votepit:Account%20%237', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Votepit', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function test_verify_accepts_the_current_code_and_rejects_a_wrong_one(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');
        $binary     = $decode->invoke(new Totp(), $secret);
        $currentCode = $hotp->invoke(new Totp(), $binary, intdiv(time(), 30));

        self::assertTrue($totp->verify($secret, $currentCode));
        self::assertFalse($totp->verify($secret, '000000' === $currentCode ? '111111' : '000000'));
    }

    public function test_verify_accepts_previous_and_next_step_within_window(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');
        $binary     = $decode->invoke(new Totp(), $secret);

        $currentStep = intdiv(time(), 30);
        $previousCode = $hotp->invoke(new Totp(), $binary, $currentStep - 1);
        $nextCode     = $hotp->invoke(new Totp(), $binary, $currentStep + 1);

        self::assertTrue($totp->verify($secret, $previousCode, 1));
        self::assertTrue($totp->verify($secret, $nextCode, 1));
    }

    public function test_verify_rejects_a_step_outside_the_window(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $reflection = new \ReflectionClass(Totp::class);
        $hotp       = $reflection->getMethod('hotp');
        $decode     = $reflection->getMethod('base32Decode');
        $binary     = $decode->invoke(new Totp(), $secret);

        $farCode = $hotp->invoke(new Totp(), $binary, intdiv(time(), 30) + 5);

        self::assertFalse($totp->verify($secret, $farCode, 1));
    }

    public function test_verify_rejects_malformed_code(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        self::assertFalse($totp->verify($secret, 'abcdef'));
        self::assertFalse($totp->verify($secret, '12345'));
        self::assertFalse($totp->verify($secret, ''));
    }
}
