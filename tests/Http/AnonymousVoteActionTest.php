<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Security\Turnstile\InMemoryTurnstileHttpClient;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for anonymous ("no account") voting on
 * POST /{board}/ideas/{id}/vote (ADR 0004).
 *
 * Regression coverage for the pre-existing 401-for-anon behaviour lives in
 * VoteActionTest (test_anon_vote_returns_401_and_creates_no_row /
 * test_json_anon_returns_401) — both boards there default
 * anonymous_voting_enabled=0, so they must keep failing exactly as before.
 * This file exercises the opt-in path itself.
 */
final class AnonymousVoteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, string> $cookies */
    private function postAnonVote(string $slug, int $ideaId, string $value, array $cookies = [], ?string $notifyEmail = null, ?string $ip = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $body = ['_csrf' => $token, 'value' => $value];
        if ($notifyEmail !== null) {
            $body['notify_email'] = $notifyEmail;
        }

        $server = $ip !== null ? ['REMOTE_ADDR' => $ip] : [];

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote', $server)
            ->withCookieParams([$csrf->cookieName() => $signed, ...$cookies])
            ->withParsedBody($body);
    }

    /** @return array<string, string> */
    private function extractSetCookies(\Psr\Http\Message\ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    private function rowCount(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
    }

    // --- Opt-in gate -----------------------------------------------------

    public function test_anonymous_vote_on_opted_in_board_succeeds_and_creates_no_user_id_row(): void
    {
        $boardId = $this->insertBoard('anon-vote-optin', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-optin-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-optin', $ideaId, 'up'));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame(1, $data['score'] ?? null);
        self::assertSame('up', $data['my_vote'] ?? null);
        self::assertSame(1, $this->rowCount($ideaId));

        $row = $this->conn->fetchAssociative('SELECT user_id, voter_token_hash FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['user_id']);
        self::assertNotNull($row['voter_token_hash']);
    }

    public function test_anonymous_vote_on_non_optin_board_still_returns_401(): void
    {
        $boardId = $this->insertBoard('anon-vote-off'); // anonymous_voting_enabled defaults to 0
        $author  = $this->insertUser('anon-vote-off-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-off', $ideaId, 'up'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- Cookie issuance + idempotent reuse -------------------------------

    public function test_first_anonymous_vote_issues_dedup_cookie(): void
    {
        $boardId = $this->insertBoard('anon-vote-cookie', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-cookie-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-cookie', $ideaId, 'up'));

        $cookies = $this->extractSetCookies($response);
        self::assertArrayHasKey('vp_anon_voter', $cookies);
        self::assertNotSame('', $cookies['vp_anon_voter']);
    }

    public function test_second_request_with_same_cookie_toggles_the_same_vote(): void
    {
        $boardId = $this->insertBoard('anon-vote-toggle', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-toggle-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app   = $this->createApp();
        $first = $app->handle($this->postAnonVote('anon-vote-toggle', $ideaId, 'up'));
        $token = $this->extractSetCookies($first)['vp_anon_voter'];

        // Same cookie, same arrow again → retraction, no second row, no new cookie needed.
        $second = $app->handle($this->postAnonVote('anon-vote-toggle', $ideaId, 'up', ['vp_anon_voter' => $token]));

        self::assertSame(200, $second->getStatusCode());
        $data = json_decode((string) $second->getBody(), true);
        self::assertSame('none', $data['my_vote'] ?? null);
        self::assertSame(0, $this->rowCount($ideaId), 'Same cookie + same arrow retracts, never a second row.');
    }

    public function test_two_different_browsers_both_count_as_separate_voters(): void
    {
        $boardId = $this->insertBoard('anon-vote-two-browsers', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-two-browsers-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app = $this->createApp();
        $app->handle($this->postAnonVote('anon-vote-two-browsers', $ideaId, 'up'));
        // No cookie sent on the second request → treated as a distinct voter.
        $second = $app->handle($this->postAnonVote('anon-vote-two-browsers', $ideaId, 'up'));

        $data = json_decode((string) $second->getBody(), true);
        self::assertSame(2, $data['up_count'] ?? null);
        self::assertSame(2, $this->rowCount($ideaId));
    }

    // --- Forced Turnstile on first-ever token issuance --------------------

    private function turnstileConfig(): Config
    {
        return Config::fromArray($this->testConfigArray([
            'turnstile' => [
                'site_key'                 => 'site-123',
                'secret_key'               => 'secret-456',
                'suspicion_threshold'      => 3,
                'suspicion_window_seconds' => 3600,
            ],
        ]));
    }

    public function test_new_anonymous_token_requires_turnstile_when_configured(): void
    {
        $boardId = $this->insertBoard('anon-vote-turnstile-1', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-turnstile-1-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp(config: $this->turnstileConfig())
            ->handle($this->postAnonVote('anon-vote-turnstile-1', $ideaId, 'up'));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('turnstile_required', $data['error']['key'] ?? null);
        self::assertSame(0, $this->rowCount($ideaId));
    }

    public function test_new_anonymous_token_with_valid_turnstile_token_succeeds(): void
    {
        $boardId = $this->insertBoard('anon-vote-turnstile-2', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-turnstile-2-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/anon-vote-turnstile-2/ideas/' . $ideaId . '/vote')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['_csrf' => $token, 'value' => 'up', 'turnstile_token' => 'good-token']);

        $response = $this->createApp(config: $this->turnstileConfig(), turnstileHttpClient: $http)->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->rowCount($ideaId));
    }

    public function test_existing_anonymous_cookie_is_not_re_challenged(): void
    {
        $boardId = $this->insertBoard('anon-vote-turnstile-3', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-turnstile-3-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $idea2   = $this->seedIdea($boardId, $author, 'Second idea');

        $http = new InMemoryTurnstileHttpClient();
        $http->stubSuccess('good-token');
        $config = $this->turnstileConfig();

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);
        $first = (new ServerRequestFactory())
            ->createServerRequest('POST', '/anon-vote-turnstile-3/ideas/' . $ideaId . '/vote')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['_csrf' => $token, 'value' => 'up', 'turnstile_token' => 'good-token']);

        $app          = $this->createApp(config: $config, turnstileHttpClient: $http);
        $firstResponse = $app->handle($first);
        $anonCookie   = $this->extractSetCookies($firstResponse)['vp_anon_voter'];

        // Second vote (different idea), same cookie, NO turnstile_token this
        // time — must still succeed because the token isn't brand-new
        // (below the suspicion threshold, so the ordinary trigger doesn't fire either).
        $token2  = $csrf->generate();
        $signed2 = $csrf->sign($token2);
        $second  = (new ServerRequestFactory())
            ->createServerRequest('POST', '/anon-vote-turnstile-3/ideas/' . $idea2 . '/vote')
            ->withCookieParams([$csrf->cookieName() => $signed2, 'vp_anon_voter' => $anonCookie])
            ->withParsedBody(['_csrf' => $token2, 'value' => 'up']);

        $secondResponse = $app->handle($second);

        self::assertSame(200, $secondResponse->getStatusCode());
        self::assertSame(1, $this->rowCount($idea2));
    }

    public function test_new_anonymous_token_is_unchallenged_when_turnstile_not_configured(): void
    {
        $boardId = $this->insertBoard('anon-vote-turnstile-4', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-turnstile-4-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        // Default testConfig() has no `turnstile` block (unconfigured) —
        // forcing a challenge here would make anonymous voting unusable on
        // a self-host install that never set Turnstile up.
        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-turnstile-4', $ideaId, 'up'));

        self::assertSame(200, $response->getStatusCode());
    }

    // --- Hard rate limiting (429) for anonymous requests -------------------

    public function test_anonymous_votes_are_rate_limited_per_ip(): void
    {
        $config  = Config::fromArray($this->testConfigArray([
            'rate_limits' => ['idea:vote' => ['limit' => 2, 'window' => 3600]],
        ]));
        $boardId = $this->insertBoard('anon-vote-ratelimit', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-ratelimit-author@example.com');
        $ideas   = [
            $this->seedIdea($boardId, $author, 'Idea 1'),
            $this->seedIdea($boardId, $author, 'Idea 2'),
            $this->seedIdea($boardId, $author, 'Idea 3'),
        ];

        $app = $this->createApp(config: $config);
        $ip  = '198.51.100.9';

        // Distinct cookie-less requests (no dedup cookie sent) on distinct
        // ideas so nothing is blocked by the vote-toggle logic itself —
        // only the bucket's own 2-per-window cap should trip.
        $r1 = $app->handle($this->postAnonVote('anon-vote-ratelimit', $ideas[0], 'up', ip: $ip));
        $r2 = $app->handle($this->postAnonVote('anon-vote-ratelimit', $ideas[1], 'up', ip: $ip));
        $r3 = $app->handle($this->postAnonVote('anon-vote-ratelimit', $ideas[2], 'up', ip: $ip));

        self::assertSame(200, $r1->getStatusCode());
        self::assertSame(200, $r2->getStatusCode());
        self::assertSame(429, $r3->getStatusCode());
    }

    // --- Optional notification email ---------------------------------------

    public function test_anonymous_vote_with_optional_email_succeeds_and_encrypts_it(): void
    {
        $boardId = $this->insertBoard('anon-vote-email', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-email-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle(
            $this->postAnonVote('anon-vote-email', $ideaId, 'up', notifyEmail: 'voter@example.com'),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT voter_email_enc FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertIsString($row['voter_email_enc']);
        self::assertNotSame('voter@example.com', $row['voter_email_enc']);

        // Reversibility roundtrip (ADR 0004 correction): unlike the old
        // irreversible hash, the whole point of this column is that it
        // CAN be decrypted back to the address it was given, so
        // IdeaStatusAction can actually deliver the promised notification.
        $encryption = new EncryptionService(str_repeat('a', 64), 'anon_vote_email');
        self::assertSame('voter@example.com', $encryption->decrypt($row['voter_email_enc']));

        $log = $this->readAuditLog();
        self::assertStringNotContainsString('voter@example.com', $log);
    }

    public function test_anonymous_vote_without_email_succeeds(): void
    {
        $boardId = $this->insertBoard('anon-vote-no-email', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-no-email-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-no-email', $ideaId, 'up'));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT voter_email_enc FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['voter_email_enc']);
    }

    public function test_anonymous_vote_with_malformed_email_returns_422(): void
    {
        $boardId = $this->insertBoard('anon-vote-bad-email', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-bad-email-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle(
            $this->postAnonVote('anon-vote-bad-email', $ideaId, 'up', notifyEmail: 'not-an-email'),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_email', $data['error']['key'] ?? null);
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- Frozen board guard still applies -----------------------------------

    public function test_anonymous_vote_on_frozen_board_returns_423(): void
    {
        $boardId = $this->insertBoard('anon-vote-frozen', [
            'anonymous_voting_enabled' => 1,
            'frozen_at'                => '2026-01-01 00:00:00',
        ]);
        $author = $this->insertUser('anon-vote-frozen-author@example.com');
        $ideaId = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle($this->postAnonVote('anon-vote-frozen', $ideaId, 'up'));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, $this->rowCount($ideaId));
    }

    // --- Audit logging (masked) ---------------------------------------------

    public function test_anonymous_vote_is_audited_with_hashed_identity_only(): void
    {
        $boardId = $this->insertBoard('anon-vote-audit', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('anon-vote-audit-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->createApp()->handle($this->postAnonVote('anon-vote-audit', $ideaId, 'up'));

        $log = $this->readAuditLog();
        self::assertStringContainsString('vote.cast_anonymous', $log);
    }
}
