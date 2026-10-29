<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\Minisign;

final class MinisignTest extends TestCase
{
    private const MESSAGE = 'archive bytes for the test';

    public function test_prehashed_signature_verifies(): void
    {
        $pair = MinisignFixture::keyPair();

        self::assertTrue(Minisign::verify(self::MESSAGE, MinisignFixture::sign(self::MESSAGE, $pair, true), $pair['publicKeyLine']));
    }

    public function test_legacy_signature_verifies(): void
    {
        $pair = MinisignFixture::keyPair();

        self::assertTrue(Minisign::verify(self::MESSAGE, MinisignFixture::sign(self::MESSAGE, $pair, false), $pair['publicKeyLine']));
    }

    public function test_signature_by_another_key_is_refused(): void
    {
        $signer = MinisignFixture::keyPair();
        $other  = MinisignFixture::keyPair();

        self::assertFalse(Minisign::verify(self::MESSAGE, MinisignFixture::sign(self::MESSAGE, $signer), $other['publicKeyLine']));
    }

    public function test_tampered_message_is_refused(): void
    {
        $pair = MinisignFixture::keyPair();
        $sig  = MinisignFixture::sign(self::MESSAGE, $pair);

        self::assertFalse(Minisign::verify(self::MESSAGE . ' tampered', $sig, $pair['publicKeyLine']));
    }

    public function test_tampered_trusted_comment_is_refused(): void
    {
        $pair = MinisignFixture::keyPair();
        $sig  = str_replace('file:votepit-community', 'file:something-else', MinisignFixture::sign(self::MESSAGE, $pair));

        self::assertFalse(Minisign::verify(self::MESSAGE, $sig, $pair['publicKeyLine']));
    }

    public function test_malformed_inputs_are_refused_without_throwing(): void
    {
        $pair = MinisignFixture::keyPair();
        $sig  = MinisignFixture::sign(self::MESSAGE, $pair);

        self::assertFalse(Minisign::verify(self::MESSAGE, '', $pair['publicKeyLine']));
        self::assertFalse(Minisign::verify(self::MESSAGE, "garbage\nnot-base64!!\n", $pair['publicKeyLine']));
        self::assertFalse(Minisign::verify(self::MESSAGE, $sig, 'PLACEHOLDER-PRIMARY-PUBLIC-KEY'));
        self::assertFalse(Minisign::verify(self::MESSAGE, $sig, base64_encode('Ed' . str_repeat('x', 10))));
    }

    public function test_placeholder_key_is_not_a_well_formed_public_key(): void
    {
        self::assertFalse(Minisign::isWellFormedPublicKey('PLACEHOLDER-PRIMARY-PUBLIC-KEY'));
        self::assertTrue(Minisign::isWellFormedPublicKey(MinisignFixture::keyPair()['publicKeyLine']));
    }
}
