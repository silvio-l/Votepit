<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Turnstile;

use Votepit\Config;
use Votepit\Security\RateLimiter;
use Votepit\Security\Turnstile\InMemoryTurnstileHttpClient;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * TurnstileGuard (OWASP/BSI audit remediation issue 20: anti-automation
 * challenge) — the suspicion-threshold escalation and verify() gate, in
 * isolation from the HTTP seam (see IdeaCreateActionTest/VoteActionTest/
 * CommentCreateActionTest for the full request-level acceptance criteria).
 */
final class TurnstileGuardTest extends IntegrationTestCase
{
    private function configured(int $threshold = 3, int $windowSeconds = 3600): Config
    {
        return Config::fromArray($this->testConfigArray([
            'turnstile' => [
                'site_key'                 => 'site-123',
                'secret_key'               => 'secret-456',
                'suspicion_threshold'      => $threshold,
                'suspicion_window_seconds' => $windowSeconds,
            ],
        ]));
    }

    private function unconfigured(): Config
    {
        return Config::fromArray($this->testConfigArray());
    }

    public function test_unconfigured_guard_never_requires_a_challenge_and_never_verifies(): void
    {
        $http  = new InMemoryTurnstileHttpClient();
        $guard = new TurnstileGuard($this->unconfigured(), new RateLimiter($this->conn), $http);

        for ($i = 0; $i < 10; $i++) {
            $guard->recordSuspicion('1.2.3.4');
        }

        self::assertFalse($guard->isConfigured());
        self::assertNull($guard->siteKey());
        self::assertFalse($guard->requiresChallenge('1.2.3.4'));
        self::assertFalse($guard->verify('any-token', '1.2.3.4'));
    }

    public function test_no_challenge_below_the_suspicion_threshold(): void
    {
        $guard = new TurnstileGuard($this->configured(threshold: 3), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');

        self::assertFalse($guard->requiresChallenge('1.2.3.4'));
    }

    public function test_challenge_required_once_the_threshold_is_crossed(): void
    {
        $guard = new TurnstileGuard($this->configured(threshold: 3), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');

        self::assertTrue($guard->requiresChallenge('1.2.3.4'));
    }

    public function test_suspicion_is_tracked_independently_per_ip(): void
    {
        $guard = new TurnstileGuard($this->configured(threshold: 3), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');

        self::assertTrue($guard->requiresChallenge('1.2.3.4'));
        self::assertFalse($guard->requiresChallenge('5.6.7.8'));
    }

    public function test_requires_challenge_is_read_only(): void
    {
        $guard = new TurnstileGuard($this->configured(threshold: 3), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        $guard->recordSuspicion('1.2.3.4');
        $guard->recordSuspicion('1.2.3.4');

        // Repeated reads must not themselves push the count over the threshold.
        $guard->requiresChallenge('1.2.3.4');
        $guard->requiresChallenge('1.2.3.4');
        $guard->requiresChallenge('1.2.3.4');

        self::assertFalse($guard->requiresChallenge('1.2.3.4'));
    }

    public function test_verify_rejects_an_empty_token(): void
    {
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        self::assertFalse($guard->verify('', '1.2.3.4'));
    }

    public function test_verify_rejects_an_unstubbed_token(): void
    {
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        self::assertFalse($guard->verify('unknown-token', '1.2.3.4'));
    }

    public function test_verify_accepts_a_stubbed_successful_token(): void
    {
        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), $http);

        self::assertTrue($guard->verify('good-token', '1.2.3.4'));
    }

    public function test_site_key_is_exposed_only_when_configured(): void
    {
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), new InMemoryTurnstileHttpClient());

        self::assertSame('site-123', $guard->siteKey());
    }

    public function test_verify_with_expected_action_requires_the_matching_action(): void
    {
        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('right-action', 'anon_idea_submit');
        $http->stubSuccess('other-action', 'login');
        $http->stubSuccess('no-action');
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), $http);

        self::assertTrue($guard->verify('right-action', '1.2.3.4', 'anon_idea_submit'));
        self::assertFalse($guard->verify('other-action', '1.2.3.4', 'anon_idea_submit'));
        self::assertFalse($guard->verify('no-action', '1.2.3.4', 'anon_idea_submit'));
        // Existing callers (no expected action) are unchanged.
        self::assertTrue($guard->verify('other-action', '1.2.3.4'));
    }

    public function test_verify_fails_closed_on_a_transport_failure(): void
    {
        $http = new InMemoryTurnstileHttpClient();
        $http->stubTransportFailure('broken');
        $guard = new TurnstileGuard($this->configured(), new RateLimiter($this->conn), $http);

        self::assertFalse($guard->verify('broken', '1.2.3.4'));
        self::assertFalse($guard->verify('broken', '1.2.3.4', 'anon_idea_submit'));
    }
}
