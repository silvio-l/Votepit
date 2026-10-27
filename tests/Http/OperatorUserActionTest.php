<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /operator/users/password-reset — Operator/
 * Support-triggered password reset for ANY platform user (Punkt 5d).
 *
 * AuthZ: AuthZMiddleware::support() — is_support OR is_operator, both tiers
 * may use it (support staff handle this day-to-day; operator is the
 * superset role). Like the account-admin variant, the target is resolved by
 * re-typed email (ADR 0002: no plaintext email is ever stored) — unlike
 * MemberAction::passwordReset(), there is no account-membership scoping
 * here, since this tier is explicitly ABOVE account-scoping (any tenant's
 * user is a valid target).
 */
final class OperatorUserActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function post(string $email, int $actorId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/operator/users/password-reset')
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($actorId),
                $csrf->cookieName() => $signed,
            ])
            ->withParsedBody(['_csrf' => $token, 'email' => $email]);
    }

    private function operator(): int
    {
        return $this->insertUser('operator-pwreset@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function support(): int
    {
        return $this->insertUser('support-pwreset@example.com', [
            'is_support'      => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_operator_triggers_a_password_reset_for_any_user(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->createApp($mailer);
        $operatorId = $this->operator();
        $accountB   = $this->insertAccount(['slug' => 'acct-operator-pwreset', 'name' => 'Foreign Account']);
        $targetId   = $this->insertUser('foreign-user-pwreset@example.com');
        $this->insertAccountMember($accountB, $targetId, 'moderator');

        $response = $app->handle($this->post('foreign-user-pwreset@example.com', $operatorId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
        self::assertSame('foreign-user-pwreset@example.com', $mailer->sent[0]['to']);
    }

    public function test_support_can_also_trigger_a_password_reset(): void
    {
        $mailer   = new InMemoryMailer();
        $app      = $this->createApp($mailer);
        $supportId = $this->support();
        $this->insertUser('another-user-pwreset@example.com');

        $response = $app->handle($this->post('another-user-pwreset@example.com', $supportId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
    }

    public function test_regular_user_cannot_trigger_a_password_reset_returns_403(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $userId  = $this->insertUser('regular-pwreset@example.com');
        $this->insertUser('another-target-pwreset@example.com');

        $response = $app->handle($this->post('another-target-pwreset@example.com', $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_unknown_email_returns_404(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->createApp($mailer);
        $operatorId = $this->operator();

        $response = $app->handle($this->post('ghost-pwreset@example.com', $operatorId));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_anon_request_returns_401(): void
    {
        $app    = $this->createApp();
        $csrf   = $this->csrf();
        $token  = $csrf->generate();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/operator/users/password-reset')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withParsedBody(['_csrf' => $token, 'email' => 'anyone@example.com']);

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    // ── POST /operator/users/block ──────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function emailPost(string $path, int $actorId, array $body): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($actorId),
                $csrf->cookieName() => $signed,
            ])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    public function test_operator_blocks_a_user_and_bumps_token_version(): void
    {
        $app        = $this->createApp();
        $operatorId = $this->operator();
        $targetId   = $this->insertUser('block-target@example.com');

        $response = $app->handle($this->emailPost('/operator/users/block', $operatorId, [
            'email'   => 'block-target@example.com',
            'blocked' => true,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['blocked']);

        $row = $this->conn->fetchAssociative(
            'SELECT is_blocked, token_version FROM users WHERE id = :id',
            ['id' => $targetId],
        );
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['is_blocked']);
        self::assertSame(1, (int) $row['token_version']);
    }

    public function test_blocking_a_user_invalidates_their_existing_session(): void
    {
        $app          = $this->createApp();
        $operatorId   = $this->operator();
        $targetId     = $this->insertUser('block-invalidate@example.com');
        $targetCookie = $this->sessionCookie($targetId, 0);

        $app->handle($this->emailPost('/operator/users/block', $operatorId, [
            'email'   => 'block-invalidate@example.com',
            'blocked' => true,
        ]));

        $csrf   = $this->csrf();
        $signed = $csrf->sign($csrf->generate());
        $followup = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/bootstrap')
            ->withCookieParams([$csrf->cookieName() => $signed, 'votepit_sess' => $targetCookie]);

        $response = $app->handle($followup);
        $data     = json_decode((string) $response->getBody(), true);
        self::assertNull($data['user']);
    }

    public function test_operator_unblocks_a_user_without_bumping_token_version(): void
    {
        $app        = $this->createApp();
        $operatorId = $this->operator();
        $targetId   = $this->insertUser('unblock-target@example.com', ['is_blocked' => 1]);

        $response = $app->handle($this->emailPost('/operator/users/block', $operatorId, [
            'email'   => 'unblock-target@example.com',
            'blocked' => false,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative(
            'SELECT is_blocked, token_version FROM users WHERE id = :id',
            ['id' => $targetId],
        );
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['is_blocked']);
        self::assertSame(0, (int) $row['token_version']);
    }

    public function test_regular_user_cannot_block_another_user_returns_403(): void
    {
        $app    = $this->createApp();
        $userId = $this->insertUser('regular-blocker@example.com');
        $this->insertUser('regular-block-target@example.com');

        $response = $app->handle($this->emailPost('/operator/users/block', $userId, [
            'email'   => 'regular-block-target@example.com',
            'blocked' => true,
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_block_unknown_user_returns_404(): void
    {
        $app        = $this->createApp();
        $operatorId = $this->operator();

        $response = $app->handle($this->emailPost('/operator/users/block', $operatorId, [
            'email'   => 'ghost-block@example.com',
            'blocked' => true,
        ]));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── POST /operator/users/end-sessions ───────────────────────────────

    public function test_operator_ends_all_sessions_of_a_target_user(): void
    {
        $app        = $this->createApp();
        $operatorId = $this->operator();
        $targetId   = $this->insertUser('end-sessions-target@example.com');

        $response = $app->handle($this->emailPost('/operator/users/end-sessions', $operatorId, [
            'email' => 'end-sessions-target@example.com',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative(
            'SELECT is_blocked, token_version FROM users WHERE id = :id',
            ['id' => $targetId],
        );
        self::assertIsArray($row);
        // Sessions ended, but NOT blocked — the user can log back in immediately.
        self::assertSame(0, (int) $row['is_blocked']);
        self::assertSame(1, (int) $row['token_version']);
    }

    public function test_regular_user_cannot_end_sessions_of_another_user_returns_403(): void
    {
        $app    = $this->createApp();
        $userId = $this->insertUser('regular-ender@example.com');
        $this->insertUser('regular-ender-target@example.com');

        $response = $app->handle($this->emailPost('/operator/users/end-sessions', $userId, [
            'email' => 'regular-ender-target@example.com',
        ]));

        self::assertSame(403, $response->getStatusCode());
    }
}
