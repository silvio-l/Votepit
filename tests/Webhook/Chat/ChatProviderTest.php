<?php

declare(strict_types=1);

namespace Votepit\Tests\Webhook\Chat;

use PHPUnit\Framework\TestCase;
use Votepit\Webhook\Chat\ChatProvider;

final class ChatProviderTest extends TestCase
{
    public function test_slack_accepts_only_incoming_webhook_urls(): void
    {
        $p = ChatProvider::Slack->urlPolicy();
        self::assertTrue($p->allows('https://hooks.slack.com/services/T0001/B0002/abcDEF123'));
        self::assertFalse($p->allows('https://hooks.slack.com/triggers/T0001/123/abc'));
        self::assertFalse($p->allows('https://discord.com/api/webhooks/123/abc'));
        self::assertFalse($p->allows('https://slack.com/services/T0001/B0002/abc'));
    }

    public function test_discord_accepts_both_official_hosts(): void
    {
        $p = ChatProvider::Discord->urlPolicy();
        self::assertTrue($p->allows('https://discord.com/api/webhooks/123456789/abc_DEF-123'));
        self::assertTrue($p->allows('https://discordapp.com/api/webhooks/123456789/abc_DEF-123'));
        self::assertFalse($p->allows('https://hooks.slack.com/services/T0001/B0002/abc'));
        self::assertFalse($p->allows('https://discord.com/api/webhooks/abc/def'));
        self::assertFalse($p->allows('https://canary.discord.com/api/webhooks/1/abc'));
    }

    public function test_url_hint_never_contains_the_token(): void
    {
        $url  = 'https://hooks.slack.com/services/T0001/B0002/supersecrettoken9z';
        $hint = ChatProvider::Slack->urlHint($url);
        self::assertSame('hooks.slack.com/…en9z', $hint);
        self::assertStringNotContainsString('supersecret', $hint);
        self::assertStringNotContainsString('B0002', $hint);
    }
}
