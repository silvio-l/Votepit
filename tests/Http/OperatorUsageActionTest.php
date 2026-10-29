<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /operator/usage (Operator panel,
 * platform-wide usage overview).
 */
final class OperatorUsageActionTest extends IntegrationTestCase
{
    public function test_usage_overview_returns_correct_counts(): void
    {
        // Default account already exists (self-host seed) — plan 'self-host'.
        $freeAccountId = $this->insertAccount(['plan' => 'free']);
        $this->insertAccount(['plan' => 'pro']);

        $boardA = $this->insertBoard('usage-board-a');
        $boardB = $this->insertBoard('usage-board-b', ['account_id' => $freeAccountId]);

        $authorId = $this->insertUser('usage-author@example.com');
        $this->seedIdea($boardA, $authorId, 'Idea 1');
        $this->seedIdea($boardB, $authorId, 'Idea 2');
        $this->seedIdea($boardB, $authorId, 'Idea 3');

        $this->insertAbuseReport('/usage-board-a/ideas/1', 'Spam', ['status' => 'open']);
        $this->insertAbuseReport('/usage-board-b/ideas/2', 'Already handled', ['status' => 'dismissed']);

        $operatorId = $this->insertUser('operator-usage@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/operator/usage')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($operatorId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame(3, $data['accounts_total']); // default + free + pro
        self::assertSame(1, $data['accounts_by_plan']['self-host']);
        self::assertSame(1, $data['accounts_by_plan']['free']);
        self::assertSame(1, $data['accounts_by_plan']['pro']);
        self::assertSame(2, $data['boards_total']);
        self::assertSame(3, $data['ideas_total']);
        self::assertSame(1, $data['open_reports']);
        self::assertGreaterThanOrEqual(0, $data['signups_last_7_days']);
        self::assertFalse($data['translation_configured']);
    }

    public function test_translation_configured_reflects_config_state(): void
    {
        $operatorId = $this->insertUser('operator-usage-translation@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $response = $this->createAppWithTranslationConfigured()->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/operator/usage')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($operatorId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['translation_configured']);
    }

    /** @return \Slim\App<null> */
    private function createAppWithTranslationConfigured(): \Slim\App
    {
        $audit = new AuditLogger($this->logFile);
        return AppFactory::create(
            Config::fromArray([
                'env'                 => 'dev',
                'app_url'             => 'http://localhost:8000',
                'app_key'             => str_repeat('a', 64),
                'identity_server_key' => self::identityServerKey(),
                'db'                  => ['name' => ':memory:'],
                'smtp'                => ['from_email' => 'noreply@example.com'],
                'magic_link_ttl'      => 900,
                'translation'         => [
                    'provider' => 'google',
                    'google'   => [
                        'project_id'          => 'test-project',
                        'service_account_key' => '/tmp/does-not-need-to-exist-for-this-status-check.json',
                        'region'              => 'eu',
                    ],
                ],
            ]),
            $this->conn,
            new \Votepit\Mail\InMemoryMailer(),
            $audit,
            avatarDirOverride: $this->avatarDir,
            planPolicy: self::syntheticPlanPolicy(),
        );
    }
}
