<?php

declare(strict_types=1);

namespace Votepit\Webhook\Chat;

/**
 * Turns a board event (same $event/$data contract as WebhookDispatcher::
 * dispatchAsync()) into the JSON body of a Slack or Discord incoming-
 * webhook message.
 *
 * Supported events and the $data keys each one reads (missing keys degrade
 * gracefully, never throw):
 *   - idea.created         idea_id, title
 *   - idea.status_changed  idea_id, title, status_from, status_to
 *   - idea.merged          idea_id, title, merged_into_id, merged_into_title
 * Any other event → null (not sent to chat).
 *
 * Data minimisation (DSGVO Art. 5(1)(c)): messages carry only board-visible
 * idea fields (title, status, link) — never author names, emails or vote
 * details. Idea titles are untrusted user input and are escaped for each
 * target's markup so a title can never inject a mention (@channel /
 * @everyone), a masked link or other formatting; Discord additionally gets
 * allowed_mentions.parse = [] and suppressed embeds as a second layer.
 */
final readonly class ChatMessageFormatter
{
    public const SUPPORTED_EVENTS = ['idea.created', 'idea.status_changed', 'idea.merged'];

    private const MAX_TITLE_LENGTH = 200;
    private const MAX_BOARD_LENGTH = 100;
    /** Discord's MESSAGE_FLAGS.SUPPRESS_EMBEDS — no link previews of user-posted URLs. */
    private const DISCORD_SUPPRESS_EMBEDS = 4;

    public function __construct(
        private string $appUrl,
        private bool $accountPrefixedRoutes,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function format(
        ChatProvider $provider,
        string $event,
        string $boardName,
        string $accountSlug,
        string $boardSlug,
        array $data,
    ): ?string {
        if (!in_array($event, self::SUPPORTED_EVENTS, true)) {
            return null;
        }

        $board = $this->clip($boardName, self::MAX_BOARD_LENGTH);
        $title = $this->clip($this->str($data, 'title'), self::MAX_TITLE_LENGTH);
        $link  = $this->ideaUrl($accountSlug, $boardSlug, $this->int($data, 'idea_id'));

        $text = match ($event) {
            'idea.created' => sprintf(
                'New idea on %s: %s',
                $this->escape($provider, $board),
                $this->link($provider, $title, $link),
            ),
            'idea.status_changed' => sprintf(
                'Status update on %s: %s (%s → %s)',
                $this->escape($provider, $board),
                $this->link($provider, $title, $link),
                $this->escape($provider, $this->humanStatus($this->str($data, 'status_from'))),
                $this->escape($provider, $this->humanStatus($this->str($data, 'status_to'))),
            ),
            default => sprintf( // idea.merged
                'Idea merged on %s: %s → %s',
                $this->escape($provider, $board),
                $this->escape($provider, $title),
                $this->link(
                    $provider,
                    $this->clip($this->str($data, 'merged_into_title'), self::MAX_TITLE_LENGTH),
                    $this->ideaUrl($accountSlug, $boardSlug, $this->int($data, 'merged_into_id')),
                ),
            ),
        };

        $payload = match ($provider) {
            ChatProvider::Slack => [
                'text'         => $text,
                'unfurl_links' => false,
                'unfurl_media' => false,
            ],
            ChatProvider::Discord => [
                'content'          => $text,
                'allowed_mentions' => ['parse' => []],
                'flags'            => self::DISCORD_SUPPRESS_EMBEDS,
            ],
        };

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function link(ChatProvider $provider, string $label, ?string $url): string
    {
        $escaped = $this->escape($provider, $label);
        if ($url === null) {
            return $escaped;
        }

        return match ($provider) {
            ChatProvider::Slack   => '<' . $url . '|' . $escaped . '>',
            ChatProvider::Discord => '[' . $escaped . '](' . $url . ')',
        };
    }

    /**
     * Slack: the three control characters of its mrkdwn link/mention syntax
     * must be HTML-entity-escaped (Slack's own documented requirement).
     * Discord: backslash-escape every markdown/mention control character.
     */
    private function escape(ChatProvider $provider, string $text): string
    {
        return match ($provider) {
            ChatProvider::Slack   => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text),
            ChatProvider::Discord => (string) preg_replace('/([\\\\*_~`|>\[\]()<#@:-])/', '\\\\$1', $text),
        };
    }

    private function ideaUrl(string $accountSlug, string $boardSlug, ?int $ideaId): ?string
    {
        if ($ideaId === null || $ideaId <= 0 || $boardSlug === '') {
            return null;
        }

        $prefix = $this->accountPrefixedRoutes && $accountSlug !== '' ? '/' . rawurlencode($accountSlug) : '';
        return rtrim($this->appUrl, '/') . $prefix . '/' . rawurlencode($boardSlug) . '/idea/' . $ideaId;
    }

    private function humanStatus(string $status): string
    {
        return $status === '' ? '?' : ucfirst(str_replace('_', ' ', $status));
    }

    private function clip(string $text, int $max): string
    {
        // Collapse newlines/whitespace runs: one-line chat notification.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /** @param array<string, mixed> $data */
    private function str(array $data, string $key): string
    {
        $v = $data[$key] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key): ?int
    {
        $v = $data[$key] ?? null;
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    }
}
