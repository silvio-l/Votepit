<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Persistence\UserRepository;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * GDPR self-service for every user (competitor-parity issue 12):
 * GET  /account/export         — Art. 15/20 export of the caller's own data
 * POST /account/delete         — Art. 17, 48h grace period, typed-email confirmation
 * POST /account/delete/cancel  — undo during the grace period
 * All user-scoped (no account prefix), AuthZ: user, own row only.
 */
final class UserSelfServiceActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf    = $this->csrf();
        $token   = $csrf->generate();
        $cookies = [$csrf->cookieName() => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function get(string $path, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

        return $userId !== null
            ? $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            : $request;
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    // ── export ───────────────────────────────────────────────────────────

    public function test_export_requires_a_session(): void
    {
        self::assertSame(401, $this->createApp()->handle($this->get('/account/export', null))->getStatusCode());
    }

    public function test_export_contains_only_the_callers_own_data_across_accounts(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'second-acct']);
        $boardA       = $this->insertBoard('board-a');
        $boardB       = $this->insertBoard('board-b', ['account_id' => $otherAccount]);
        $me           = $this->insertUser('me@example.com', ['username' => 'Me', 'username_lower' => 'me', 'notification_email' => 'me@example.com']);
        $someone      = $this->insertUser('someone@example.com');
        $this->insertAccountMember($otherAccount, $me, 'member');
        $this->conn->insert('oauth_identities', ['user_id' => $me, 'provider' => 'github', 'provider_user_id' => 'gh-me']);

        $myIdeaA    = $this->seedIdea($boardA, $me, 'My idea A');
        $myIdeaB    = $this->seedIdea($boardB, $me, 'My idea B');
        $theirIdea  = $this->seedIdea($boardB, $someone, 'Their idea');
        $myComment  = $this->seedComment($theirIdea, $me, 'My comment');
        $theirCmt   = $this->seedComment($myIdeaA, $someone, 'Their comment');
        $this->seedVote($theirIdea, $me, 1);
        $this->seedVote($myIdeaA, $someone, -1);
        $this->seedReaction($theirCmt, $me, '🎉');

        $response = $this->createApp()->handle($this->get('/account/export', $me));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        $data = $this->json($response);

        self::assertSame('Me', $data['profile']['username']);
        self::assertSame('me@example.com', $data['notification_preferences']['notification_email']);
        self::assertArrayNotHasKey('email_hmac', $data['profile']);
        self::assertArrayNotHasKey('password_hash', $data['profile']);
        self::assertSame([['account_slug' => 'second-acct', 'role' => 'member']], $data['memberships']);
        self::assertSame(['github'], array_column($data['linked_identities'], 'provider'));

        self::assertEqualsCanonicalizing([$myIdeaA, $myIdeaB], array_map(intval(...), array_column($data['ideas'], 'id')));
        self::assertEqualsCanonicalizing(['board-a', 'board-b'], array_column($data['ideas'], 'board_slug'));
        self::assertSame([$myComment], array_map(intval(...), array_column($data['comments'], 'id')));
        self::assertSame([$theirIdea], array_map(intval(...), array_column($data['votes'], 'idea_id')));
        self::assertSame(['🎉'], array_column($data['reactions'], 'reaction'));

        $encoded = (string) json_encode($data);
        self::assertStringNotContainsString('Their idea', $encoded);
        self::assertStringNotContainsString('Their comment', $encoded);
    }

    // ── delete ───────────────────────────────────────────────────────────

    public function test_delete_with_matching_email_schedules_a_48h_grace_period(): void
    {
        $me = $this->insertUser('leaving@example.com');

        $response = $this->createApp()->handle($this->post('/account/delete', ['confirm_email' => '  Leaving@Example.com '], $me));

        self::assertSame(200, $response->getStatusCode());
        $deadline = new \DateTimeImmutable((string) $this->json($response)['deletion_scheduled_at']);
        $hoursOut = ($deadline->getTimestamp() - time()) / 3600;
        self::assertGreaterThan(46, $hoursOut);
        self::assertLessThan(50, $hoursOut);
        self::assertNotNull($this->conn->fetchOne('SELECT deletion_scheduled_at FROM users WHERE id = :id', ['id' => $me]));
        self::assertStringContainsString('user.delete.requested', $this->readAuditLog());
    }

    public function test_delete_with_a_different_email_is_rejected(): void
    {
        $me = $this->insertUser('real@example.com');

        $response = $this->createApp()->handle($this->post('/account/delete', ['confirm_email' => 'other@example.com'], $me));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('confirmation_mismatch', $this->json($response)['error']['key']);
        self::assertNull($this->conn->fetchOne('SELECT deletion_scheduled_at FROM users WHERE id = :id', ['id' => $me]));
    }

    public function test_an_account_owner_is_blocked_until_the_account_is_gone(): void
    {
        $accountId = $this->insertAccount(['slug' => 'still-owned']);
        $me        = $this->insertUser('owner-leaving@example.com');
        $this->insertAccountMember($accountId, $me, 'owner');

        $response = $this->createApp()->handle($this->post('/account/delete', ['confirm_email' => 'owner-leaving@example.com'], $me));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('owns_accounts', $this->json($response)['error']['key']);
        self::assertNull($this->conn->fetchOne('SELECT deletion_scheduled_at FROM users WHERE id = :id', ['id' => $me]));
    }

    public function test_an_operator_is_blocked(): void
    {
        $me = $this->insertUser('op-leaving@example.com', ['is_operator' => 1]);

        $response = $this->createApp()->handle($this->post('/account/delete', ['confirm_email' => 'op-leaving@example.com'], $me));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('operator_undeletable', $this->json($response)['error']['key']);
    }

    public function test_delete_requires_a_session(): void
    {
        $response = $this->createApp()->handle($this->post('/account/delete', ['confirm_email' => 'x@example.com'], null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_cancel_clears_the_schedule_and_profile_reports_it(): void
    {
        $me  = $this->insertUser('undo@example.com');
        $app = $this->createApp();
        $app->handle($this->post('/account/delete', ['confirm_email' => 'undo@example.com'], $me));

        $profile = $this->json($app->handle($this->get('/account/profile', $me)));
        self::assertIsString($profile['deletion_scheduled_at']);

        $response = $app->handle($this->post('/account/delete/cancel', [], $me));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT deletion_scheduled_at FROM users WHERE id = :id', ['id' => $me]));
        self::assertNull($this->json($app->handle($this->get('/account/profile', $me)))['deletion_scheduled_at']);
    }

    // ── placeholder rendering ────────────────────────────────────────────

    public function test_public_profile_of_the_placeholder_is_marked_deleted(): void
    {
        $placeholder = (new UserRepository($this->conn))->findOrCreateDeletedPlaceholder();
        $regular     = $this->insertUser('regular@example.com');
        $app         = $this->createApp();

        $deleted = $this->json($app->handle($this->get("/members/{$placeholder}/profile", null)));
        $normal  = $this->json($app->handle($this->get("/members/{$regular}/profile", null)));

        self::assertTrue($deleted['deleted']);
        self::assertFalse($deleted['visible']);
        self::assertFalse($normal['deleted']);
    }
}
