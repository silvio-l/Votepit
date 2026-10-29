<?php

declare(strict_types=1);

namespace Votepit\Webhook\Chat;

use Votepit\Security\Webhook\AllowlistedUrlPolicy;

/**
 * The chat services a board can push notifications to via an incoming-
 * webhook URL. Each provider pins its own exact host allowlist + URL path
 * shape (AllowlistedUrlPolicy) — the board admin can never point Votepit at
 * an arbitrary host through this feature (SSRF, OWASP A01).
 */
enum ChatProvider: string
{
    case Slack   = 'slack';
    case Discord = 'discord';

    public function urlPolicy(): AllowlistedUrlPolicy
    {
        return match ($this) {
            // https://api.slack.com/messaging/webhooks — /services/T…/B…/<token>
            self::Slack => new AllowlistedUrlPolicy(
                ['hooks.slack.com'],
                '#^/services/[A-Za-z0-9]{1,32}/[A-Za-z0-9]{1,32}/[A-Za-z0-9]{1,64}$#',
            ),
            // https://discord.com/developers/docs/resources/webhook — /api/webhooks/<id>/<token>
            self::Discord => new AllowlistedUrlPolicy(
                ['discord.com', 'discordapp.com'],
                '#^/api/webhooks/\d{1,20}/[A-Za-z0-9_-]{1,128}$#',
            ),
        };
    }

    /**
     * Non-secret display form of a stored URL, e.g. "hooks.slack.com/…a1b2".
     * The full URL embeds the webhook's bearer token and is never returned
     * to the client after saving.
     */
    public function urlHint(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        return strtolower($host) . '/…' . substr($url, -4);
    }
}
