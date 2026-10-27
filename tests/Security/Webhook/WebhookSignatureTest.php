<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Webhook;

use PHPUnit\Framework\TestCase;
use Votepit\Security\Webhook\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function test_sign_is_deterministic_hmac_sha256(): void
    {
        $expected = hash_hmac('sha256', '{"a":1}', 'secret');
        self::assertSame($expected, WebhookSignature::sign('secret', '{"a":1}'));
    }

    public function test_header_value_carries_algorithm_prefix(): void
    {
        $sig = WebhookSignature::sign('secret', 'body');
        self::assertSame('sha256=' . $sig, WebhookSignature::headerValue('secret', 'body'));
    }

    public function test_different_secret_yields_different_signature(): void
    {
        self::assertNotSame(
            WebhookSignature::sign('secret-a', 'body'),
            WebhookSignature::sign('secret-b', 'body'),
        );
    }

    public function test_different_body_yields_different_signature(): void
    {
        self::assertNotSame(
            WebhookSignature::sign('secret', 'body-a'),
            WebhookSignature::sign('secret', 'body-b'),
        );
    }
}
