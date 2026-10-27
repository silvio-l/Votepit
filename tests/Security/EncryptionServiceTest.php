<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\EncryptionService;

/**
 * OWASP/BSI audit remediation issue 09 (crypto-output versioning): the
 * "v1:" ciphertext tag and its backward-compatible handling of legacy
 * (pre-tag) blobs.
 */
final class EncryptionServiceTest extends TestCase
{
    private const APP_KEY = 'test-app-key';

    public function test_round_trips_a_plaintext(): void
    {
        $service = new EncryptionService(self::APP_KEY, 'test');

        $encrypted = $service->encrypt('super secret value');

        self::assertSame('super secret value', $service->decrypt($encrypted));
    }

    public function test_encrypted_output_carries_the_v1_tag(): void
    {
        $service = new EncryptionService(self::APP_KEY, 'test');

        self::assertStringStartsWith('v1:', $service->encrypt('anything'));
    }

    public function test_decrypts_legacy_untagged_ciphertext(): void
    {
        $service = new EncryptionService(self::APP_KEY, 'test');

        // Simulates a blob written before issue 09 (no "v1:" prefix) by
        // stripping the tag off a freshly encrypted value — same bytes the
        // pre-versioning encrypt() used to produce.
        $tagged   = $service->encrypt('legacy secret');
        $untagged = substr($tagged, strlen('v1:'));

        self::assertSame('legacy secret', $service->decrypt($untagged));
    }

    public function test_rejects_tampered_ciphertext(): void
    {
        $service   = new EncryptionService(self::APP_KEY, 'test');
        $encrypted = $service->encrypt('secret');

        self::assertNull($service->decrypt($encrypted . 'x'));
    }

    public function test_rejects_unrecognized_version_tag_instead_of_guessing(): void
    {
        $service = new EncryptionService(self::APP_KEY, 'test');

        self::assertNull($service->decrypt('v2:whatever-a-future-format-looks-like'));
    }

    public function test_rejects_malformed_input(): void
    {
        $service = new EncryptionService(self::APP_KEY, 'test');

        self::assertNull($service->decrypt(''));
        self::assertNull($service->decrypt('not-valid-base64!!!'));
    }

    public function test_different_contexts_derive_independent_keys(): void
    {
        $smtp  = new EncryptionService(self::APP_KEY, 'smtp');
        $totp  = new EncryptionService(self::APP_KEY, 'totp');
        $encrypted = $smtp->encrypt('secret');

        self::assertNull($totp->decrypt($encrypted));
    }
}
