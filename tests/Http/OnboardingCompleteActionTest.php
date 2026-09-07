<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/onboarding/complete — dismisses the
 * first-run Setup Wizard for the current account (OnboardingCompleteAction).
 *
 * Self-host mode (the harness default, no {account} path segment) always
 * resolves a request's account context to the seeded default account
 * (AccountContextMiddleware) — every "own account" scenario below therefore
 * acts through the default account, mirroring BoardListActionTest's
 * cross-tenant pattern rather than inserting a second, unreachable account.
 */
final class OnboardingCompleteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function postRequest(?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/onboarding/complete')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token]);
    }

    public function test_post_as_anon_returns_401(): void
    {
        $response = $this->createApp()->handle($this->postRequest(null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_post_as_non_member_returns_403(): void
    {
        $userId   = $this->insertUser('no-membership-onboarding@example.com');
        $response = $this->createApp()->handle($this->postRequest($userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_post_as_owner_marks_own_account_onboarded(): void
    {
        // Override the migration 0017 backfill so this test exercises the
        // actual NULL -> set transition, not an already-onboarded no-op.
        $this->conn->update('accounts', ['onboarding_completed_at' => null], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->insertUser('owner-complete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRequest($ownerId));

        self::assertSame(200, $response->getStatusCode());

        $completedAt = $this->conn->fetchOne(
            'SELECT onboarding_completed_at FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertNotNull($completedAt);
    }

    public function test_post_is_idempotent_and_does_not_move_an_already_set_timestamp(): void
    {
        $this->conn->update(
            'accounts',
            ['onboarding_completed_at' => '2020-01-01 00:00:00'],
            ['id' => $this->defaultAccountId()],
        );
        $ownerId = $this->insertUser('owner-idempotent@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->postRequest($ownerId));
        self::assertSame(200, $response->getStatusCode());

        $completedAt = $this->conn->fetchOne(
            'SELECT onboarding_completed_at FROM accounts WHERE id = :id',
            ['id' => $this->defaultAccountId()],
        );
        self::assertSame('2020-01-01 00:00:00', $completedAt);
    }

    public function test_post_as_admin_succeeds(): void
    {
        $this->conn->update('accounts', ['onboarding_completed_at' => null], ['id' => $this->defaultAccountId()]);
        $adminId = $this->insertUser('admin-complete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $adminId, 'admin');

        $response = $this->createApp()->handle($this->postRequest($adminId));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_post_as_moderator_returns_403(): void
    {
        $this->conn->update('accounts', ['onboarding_completed_at' => null], ['id' => $this->defaultAccountId()]);
        $modId = $this->insertUser('mod-complete@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->postRequest($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_post_does_not_affect_a_different_account(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-onboarding', 'onboarding_completed_at' => null]);
        $this->conn->update('accounts', ['onboarding_completed_at' => null], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->insertUser('owner-cross-onboarding@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $this->createApp()->handle($this->postRequest($ownerId));

        $foreignCompletedAt = $this->conn->fetchOne(
            'SELECT onboarding_completed_at FROM accounts WHERE id = :id',
            ['id' => $foreignAccountId],
        );
        self::assertNull($foreignCompletedAt);
    }
}
