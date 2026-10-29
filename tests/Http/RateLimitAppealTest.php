<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-limit integration test for the per-action bucket `appeal:submit`
 * (board-operator-compliance issue 09) — same pattern as RateLimitReportTest
 * for `report:submit`: overrides testConfig() with a low threshold so the
 * limit is deterministically reachable, and proves the config key
 * `appeal:submit` matches what AppFactory looks up.
 */
final class RateLimitAppealTest extends IntegrationTestCase
{
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => [
                'appeal:submit' => ['limit' => 1, 'window' => 3600],
            ],
        ]);
    }

    private function postAppeal(int $moderationActionId, string $message, int $userId): ServerRequestInterface
    {
        $csrf   = new CsrfService(str_repeat('a', 64), 3600, false);
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/appeals')
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ])
            ->withParsedBody([
                '_csrf'                 => $token,
                'moderation_action_id'  => $moderationActionId,
                'message'               => $message,
            ]);
    }

    public function test_exceeding_the_appeal_submit_limit_returns_429(): void
    {
        $boardId  = $this->insertBoard('rl-appeal-board');
        $authorId = $this->insertUser('rl-appeal-author@example.com');
        $ideaId   = $this->seedIdea($boardId, $authorId);
        $actionId = $this->seedModerationAction('idea', $ideaId, $boardId, $this->defaultAccountId(), $this->insertUser('rl-appeal-mod@example.com'));
        $this->conn->update(
            'ideas',
            ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'moderation_action_id' => $actionId],
            ['id' => $ideaId],
        );

        $app = $this->createApp();

        // 1st appeal (count=1, 1 <= 1 → allowed → 201)
        $first = $app->handle($this->postAppeal($actionId, 'First appeal attempt, worded at sufficient length.', $authorId));
        self::assertSame(201, $first->getStatusCode());

        // 2nd appeal from the same user (count=2, 2 > 1 → 429) — would
        // otherwise 409 (already pending), but the rate limit middleware
        // runs first and blocks it before the action is ever reached.
        $second = $app->handle($this->postAppeal($actionId, 'Second appeal attempt, worded at sufficient length.', $authorId));
        self::assertSame(429, $second->getStatusCode());

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM content_moderation_appeals'));
    }
}
