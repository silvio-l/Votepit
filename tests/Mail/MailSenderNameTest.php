<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\MailSenderName;

final class MailSenderNameTest extends TestCase
{
    public function test_plain_name_gets_the_fixed_via_suffix(): void
    {
        self::assertSame('Acme GmbH via Votepit', MailSenderName::forName('Acme GmbH'));
    }

    public function test_header_injection_and_address_characters_are_stripped(): void
    {
        $name = MailSenderName::forName("Evil\r\nBcc: victim@example.com <a@b.c>\"; \\");

        self::assertNotNull($name);
        self::assertStringNotContainsString("\r", $name);
        self::assertStringNotContainsString("\n", $name);
        self::assertStringNotContainsString('<', $name);
        self::assertStringNotContainsString('>', $name);
        self::assertStringNotContainsString('@', $name);
        self::assertStringNotContainsString('"', $name);
        self::assertStringNotContainsString('\\', $name);
        self::assertStringEndsWith(' via Votepit', $name);
        self::assertTrue(MailSenderName::isSafe($name));
    }

    public function test_empty_or_unusable_names_fall_back_to_null(): void
    {
        self::assertNull(MailSenderName::forName(''));
        self::assertNull(MailSenderName::forName('<<>>@@'));
        self::assertNull(MailSenderName::forName("\r\n\t"));
    }

    public function test_a_name_that_claims_votepit_is_refused(): void
    {
        self::assertNull(MailSenderName::forName('Votepit Official'));
        self::assertNull(MailSenderName::forName('VOTEPIT'));
    }

    public function test_the_name_part_is_length_limited(): void
    {
        $name = MailSenderName::forName(str_repeat('a', 80));

        self::assertSame(str_repeat('a', 40) . ' via Votepit', $name);
    }

    public function test_is_safe_accepts_only_what_the_sanitiser_produces(): void
    {
        self::assertTrue(MailSenderName::isSafe('Acme via Votepit'));
        self::assertFalse(MailSenderName::isSafe("Acme via Votepit\r\nBcc: x@example.com"));
        self::assertFalse(MailSenderName::isSafe('x@example.com via Votepit'));
        self::assertFalse(MailSenderName::isSafe('PayPal'));
        self::assertFalse(MailSenderName::isSafe('Acme via Votepit2'));
        self::assertFalse(MailSenderName::isSafe('Votepit Support via Votepit'));
    }
}
