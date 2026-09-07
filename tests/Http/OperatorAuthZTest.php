<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Security-critical AuthZ tests for the operator tier (Operator
 * panel). The load-bearing invariant: AuthZMiddleware::
 * operator() sits STRICTLY ABOVE account-scoping — a regular account owner
 * (of ANY account, including the default one) must be structurally unable
 * to reach any /operator/* route, ever, no matter how privileged they are
 * within their own tenant.
 */
final class OperatorAuthZTest extends IntegrationTestCase
{
    private const OPERATOR_ROUTES = [
        ['GET', '/operator/usage'],
        ['GET', '/operator/accounts'],
        ['GET', '/operator/boards'],
        ['GET', '/operator/reports'],
    ];

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, ?int $userId, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        if ($method !== 'GET') {
            $csrf   = $this->csrf();
            $token  = $csrf->generate();
            $cookies[$csrf->cookieName()] = $csrf->sign($token);
            $body   = array_merge($body, ['_csrf' => $token]);
            $request = $request->withParsedBody($body);
        }

        return $request->withCookieParams($cookies);
    }

    // -------------------------------------------------------------------------
    // Anon → 401 on every operator route.
    // -------------------------------------------------------------------------

    public function test_anon_is_rejected_from_every_operator_route(): void
    {
        $app = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, null));
            self::assertSame(401, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // A plain logged-in user (no account membership at all) → 403.
    // -------------------------------------------------------------------------

    public function test_plain_user_is_rejected_from_every_operator_route(): void
    {
        $userId = $this->insertUser('plain-user@example.com');
        $app    = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $userId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // The default account's OWNER → 403 (the critical case: highest possible
    // account-scoped privilege must still not reach the operator tier).
    // -------------------------------------------------------------------------

    public function test_default_account_owner_is_rejected_from_every_operator_route(): void
    {
        $ownerId = $this->insertUser('default-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $ownerId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // An OWNER of a DIFFERENT (non-default) account → 403 — cross-tenant
    // account ownership must not grant operator access either.
    // -------------------------------------------------------------------------

    public function test_other_account_owner_is_rejected_from_every_operator_route(): void
    {
        $otherAccountId = $this->insertAccount();
        $ownerId        = $this->insertUser('other-owner@example.com');
        $this->insertAccountMember($otherAccountId, $ownerId, 'owner');
        $app = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $ownerId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // The installation-wide platform admin (users.is_admin) → 403 — is_admin
    // and is_operator are deliberately separate tiers.
    // -------------------------------------------------------------------------

    public function test_platform_admin_is_rejected_from_every_operator_route(): void
    {
        $adminId = $this->insertUser('platform-admin-op@example.com', ['is_admin' => 1]);
        $app     = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $adminId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // An operator (users.is_operator = 1) succeeds on every read route.
    // -------------------------------------------------------------------------

    public function test_operator_is_allowed_on_every_operator_read_route(): void
    {
        $operatorId = $this->insertUser('operator@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $app = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $operatorId));
            self::assertSame(200, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // An operator without 2FA set up → 403, even though is_operator = 1 — 2FA
    // is mandatory for this tier (AuthZMiddleware::process()).
    // -------------------------------------------------------------------------

    public function test_operator_without_totp_is_rejected_from_every_operator_route(): void
    {
        $operatorId = $this->insertUser('operator-no-2fa@example.com', ['is_operator' => 1]);
        $app        = $this->createApp();

        foreach (self::OPERATOR_ROUTES as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $operatorId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }

    // -------------------------------------------------------------------------
    // Mutating operator routes: non-operator (incl. owner) → 403, operator → 200.
    // -------------------------------------------------------------------------

    public function test_non_operator_owner_is_rejected_from_mutating_operator_routes(): void
    {
        $ownerId   = $this->insertUser('owner-mutate@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $accountId = $this->insertAccount();
        $boardId   = $this->insertBoard('mutate-board', ['account_id' => $accountId]);
        $app       = $this->createApp();

        $routes = [
            ['POST', "/operator/accounts/{$accountId}/lock"],
            ['POST', "/operator/accounts/{$accountId}/unlock"],
            ['POST', "/operator/boards/{$boardId}/lock"],
            ['POST', "/operator/boards/{$boardId}/unlock"],
        ];

        foreach ($routes as [$method, $path]) {
            $response = $app->handle($this->request($method, $path, $ownerId));
            self::assertSame(403, $response->getStatusCode(), "$method $path");
        }
    }
}
