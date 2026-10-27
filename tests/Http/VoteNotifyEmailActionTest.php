<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\EncryptionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for PATCH /{board}/ideas/{id}/vote/notify-email (ADR
 * 0004 UX follow-up) — adding/changing/clearing an anonymous voter's
 * optional notification email AFTER their vote already exists, decoupled
 * from the POST .../vote request itself.
 */
final class VoteNotifyEmailActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, string> $cookies */
    private function postAnonVote(string $slug, int $ideaId, string $value, array $cookies = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $slug . '/ideas/' . $ideaId . '/vote')
            ->withCookieParams([$csrf->cookieName() => $signed, ...$cookies])
            ->withParsedBody(['_csrf' => $token, 'value' => $value]);
    }

    /** @param array<string, string> $cookies */
    private function patchNotifyEmail(string $slug, int $ideaId, ?string $email, array $cookies = []): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $body = ['_csrf' => $token];
        if ($email !== null) {
            $body['notify_email'] = $email;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/' . $slug . '/ideas/' . $ideaId . '/vote/notify-email')
            ->withCookieParams([$csrf->cookieName() => $signed, ...$cookies])
            ->withParsedBody($body);
    }

    /** @return array<string, string> */
    private function extractSetCookies(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    private function voterEmailEnc(int $ideaId, string $slug): ?string
    {
        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :id',
            ['id' => $ideaId],
        );

        self::assertIsArray($row, "expected a vote row for idea {$ideaId} on board {$slug}");

        return $row['voter_email_enc'];
    }

    public function test_sets_email_on_an_already_cast_anonymous_vote(): void
    {
        $boardId = $this->insertBoard('notify-email-set', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-set-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app      = $this->createApp();
        $castVote = $app->handle($this->postAnonVote('notify-email-set', $ideaId, 'up'));
        $cookie   = $this->extractSetCookies($castVote)['vp_anon_voter'];

        $response = $app->handle(
            $this->patchNotifyEmail('notify-email-set', $ideaId, 'voter@example.com', ['vp_anon_voter' => $cookie]),
        );

        self::assertSame(200, $response->getStatusCode());
        $enc = $this->voterEmailEnc($ideaId, 'notify-email-set');
        self::assertIsString($enc);
        self::assertNotSame('voter@example.com', $enc);

        $encryption = new EncryptionService(str_repeat('a', 64), 'anon_vote_email');
        self::assertSame('voter@example.com', $encryption->decrypt($enc));
    }

    public function test_changes_a_previously_set_email(): void
    {
        $boardId = $this->insertBoard('notify-email-change', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-change-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app      = $this->createApp();
        $castVote = $app->handle($this->postAnonVote('notify-email-change', $ideaId, 'up'));
        $cookie   = $this->extractSetCookies($castVote)['vp_anon_voter'];

        $app->handle($this->patchNotifyEmail('notify-email-change', $ideaId, 'first@example.com', ['vp_anon_voter' => $cookie]));
        $app->handle($this->patchNotifyEmail('notify-email-change', $ideaId, 'second@example.com', ['vp_anon_voter' => $cookie]));

        $encryption = new EncryptionService(str_repeat('a', 64), 'anon_vote_email');
        $enc        = $this->voterEmailEnc($ideaId, 'notify-email-change');
        self::assertIsString($enc);
        self::assertSame('second@example.com', $encryption->decrypt($enc));
    }

    public function test_clears_a_previously_set_email_when_sent_blank(): void
    {
        $boardId = $this->insertBoard('notify-email-clear', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-clear-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app      = $this->createApp();
        $castVote = $app->handle($this->postAnonVote('notify-email-clear', $ideaId, 'up'));
        $cookie   = $this->extractSetCookies($castVote)['vp_anon_voter'];

        $app->handle($this->patchNotifyEmail('notify-email-clear', $ideaId, 'set@example.com', ['vp_anon_voter' => $cookie]));
        $response = $app->handle($this->patchNotifyEmail('notify-email-clear', $ideaId, '', ['vp_anon_voter' => $cookie]));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->voterEmailEnc($ideaId, 'notify-email-clear'));
    }

    public function test_returns_404_without_an_existing_vote(): void
    {
        $boardId = $this->insertBoard('notify-email-no-vote', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-no-vote-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle(
            $this->patchNotifyEmail('notify-email-no-vote', $ideaId, 'voter@example.com', ['vp_anon_voter' => 'never-voted-token']),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_returns_404_without_any_dedup_cookie(): void
    {
        $boardId = $this->insertBoard('notify-email-no-cookie', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-no-cookie-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle(
            $this->patchNotifyEmail('notify-email-no-cookie', $ideaId, 'voter@example.com'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_returns_404_when_board_never_opted_into_anonymous_voting(): void
    {
        $boardId = $this->insertBoard('notify-email-board-off'); // default off
        $author  = $this->insertUser('notify-email-board-off-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $response = $this->createApp()->handle(
            $this->patchNotifyEmail('notify-email-board-off', $ideaId, 'voter@example.com', ['vp_anon_voter' => 'some-token']),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_returns_422_for_a_malformed_email(): void
    {
        $boardId = $this->insertBoard('notify-email-bad', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-bad-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app      = $this->createApp();
        $castVote = $app->handle($this->postAnonVote('notify-email-bad', $ideaId, 'up'));
        $cookie   = $this->extractSetCookies($castVote)['vp_anon_voter'];

        $response = $app->handle(
            $this->patchNotifyEmail('notify-email-bad', $ideaId, 'not-an-email', ['vp_anon_voter' => $cookie]),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('invalid_email', $data['error']['key'] ?? null);
        self::assertNull($this->voterEmailEnc($ideaId, 'notify-email-bad'));
    }

    public function test_never_touches_another_voters_row(): void
    {
        $boardId = $this->insertBoard('notify-email-isolation', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-isolation-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app = $this->createApp();
        $first = $app->handle($this->postAnonVote('notify-email-isolation', $ideaId, 'up'));
        $cookieA = $this->extractSetCookies($first)['vp_anon_voter'];
        // No cookie sent → a distinct second anonymous voter.
        $second = $app->handle($this->postAnonVote('notify-email-isolation', $ideaId, 'down'));
        $cookieB = $this->extractSetCookies($second)['vp_anon_voter'];

        $app->handle($this->patchNotifyEmail('notify-email-isolation', $ideaId, 'a@example.com', ['vp_anon_voter' => $cookieA]));
        $app->handle($this->patchNotifyEmail('notify-email-isolation', $ideaId, 'b@example.com', ['vp_anon_voter' => $cookieB]));

        $rows = $this->conn->fetchAllAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :id ORDER BY id',
            ['id' => $ideaId],
        );
        self::assertCount(2, $rows);

        $encryption = new EncryptionService(str_repeat('a', 64), 'anon_vote_email');
        $decrypted  = [];
        foreach ($rows as $row) {
            self::assertIsString($row['voter_email_enc']);
            $decrypted[] = $encryption->decrypt($row['voter_email_enc']);
        }
        sort($decrypted);
        self::assertSame(['a@example.com', 'b@example.com'], $decrypted);
    }

    public function test_authenticated_users_get_404_too(): void
    {
        $boardId = $this->insertBoard('notify-email-authed', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-authed-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/notify-email-authed/ideas/' . $ideaId . '/vote/notify-email')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $this->sessionCookie($author)])
            ->withParsedBody(['_csrf' => $token, 'notify_email' => 'voter@example.com']);

        $response = $this->createApp()->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_update_is_audited_with_hashed_identity_only(): void
    {
        $boardId = $this->insertBoard('notify-email-audit', ['anonymous_voting_enabled' => 1]);
        $author  = $this->insertUser('notify-email-audit-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $app      = $this->createApp();
        $castVote = $app->handle($this->postAnonVote('notify-email-audit', $ideaId, 'up'));
        $cookie   = $this->extractSetCookies($castVote)['vp_anon_voter'];

        $app->handle($this->patchNotifyEmail('notify-email-audit', $ideaId, 'voter@example.com', ['vp_anon_voter' => $cookie]));

        $log = $this->readAuditLog();
        self::assertStringContainsString('vote.notify_email_updated', $log);
        self::assertStringNotContainsString('voter@example.com', $log);
    }
}
