<?php

declare(strict_types=1);

namespace Votepit\Tests\Webhook\Chat;

use PHPUnit\Framework\TestCase;
use Votepit\Webhook\Chat\ChatMessageFormatter;
use Votepit\Webhook\Chat\ChatProvider;

final class ChatMessageFormatterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function decode(?string $json): array
    {
        self::assertNotNull($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    public function test_slack_idea_created_links_to_the_idea(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com/', false);
        $body = $this->decode($f->format(ChatProvider::Slack, 'idea.created', 'Feedback', 'acme', 'demo', ['idea_id' => 7, 'title' => 'Dark mode']));

        self::assertSame('New idea on Feedback: <https://app.example.com/demo/idea/7|Dark mode>', $body['text']);
        self::assertFalse($body['unfurl_links']);
    }

    public function test_cloud_mode_prefixes_the_account_slug(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', true);
        $body = $this->decode($f->format(ChatProvider::Discord, 'idea.created', 'Feedback', 'acme', 'demo', ['idea_id' => 7, 'title' => 'Dark mode']));

        self::assertSame('New idea on Feedback: [Dark mode](https://app.example.com/acme/demo/idea/7)', $body['content']);
        self::assertSame(['parse' => []], $body['allowed_mentions']);
        self::assertSame(4, $body['flags']);
    }

    public function test_status_change_shows_from_and_to(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', false);
        $body = $this->decode($f->format(ChatProvider::Slack, 'idea.status_changed', 'B', 'a', 'demo', [
            'idea_id' => 3, 'title' => 'T', 'status_from' => 'open', 'status_to' => 'in_progress',
        ]));

        self::assertSame('Status update on B: <https://app.example.com/demo/idea/3|T> (Open → In progress)', $body['text']);
    }

    public function test_merged_links_to_the_canonical_idea(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', false);
        $body = $this->decode($f->format(ChatProvider::Slack, 'idea.merged', 'B', 'a', 'demo', [
            'idea_id' => 4, 'title' => 'Dupe', 'merged_into_id' => 2, 'merged_into_title' => 'Original',
        ]));

        self::assertSame('Idea merged on B: Dupe → <https://app.example.com/demo/idea/2|Original>', $body['text']);
    }

    public function test_unsupported_event_is_not_sent(): void
    {
        $f = new ChatMessageFormatter('https://app.example.com', false);
        self::assertNull($f->format(ChatProvider::Slack, 'comment.created', 'B', 'a', 'demo', []));
    }

    public function test_slack_title_cannot_inject_mentions_or_links(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', false);
        $body = $this->decode($f->format(ChatProvider::Slack, 'idea.created', 'B', 'a', 'demo', [
            'idea_id' => 1, 'title' => '<!channel> <https://evil.example|click> & co',
        ]));

        self::assertStringNotContainsString('<!channel>', $body['text']);
        self::assertStringNotContainsString('<https://evil', $body['text']);
        self::assertStringContainsString('&lt;!channel&gt; &lt;https://evil.example|click&gt; &amp; co', $body['text']);
    }

    public function test_discord_title_cannot_inject_masked_links_or_mentions(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', false);
        $body = $this->decode($f->format(ChatProvider::Discord, 'idea.created', 'B', 'a', 'demo', [
            'idea_id' => 1, 'title' => '@everyone [x](https://evil.example) **bold**',
        ]));

        self::assertStringContainsString('\\@everyone \\[x\\]\\(https\\://evil.example\\) \\*\\*bold\\*\\*', $body['content']);
    }

    public function test_long_multiline_titles_are_flattened_and_clipped(): void
    {
        $f    = new ChatMessageFormatter('https://app.example.com', false);
        $body = $this->decode($f->format(ChatProvider::Slack, 'idea.created', 'B', 'a', 'demo', [
            'idea_id' => 1, 'title' => "line1\nline2 " . str_repeat('x', 500),
        ]));

        self::assertStringNotContainsString("\n", $body['text']);
        self::assertStringContainsString('line1 line2', $body['text']);
        self::assertLessThan(300, mb_strlen((string) $body['text']));
    }
}
