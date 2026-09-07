<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /admin/account/delete + POST
 * /admin/account/delete/cancel (owner self-service GDPR account deletion,
 * 48h grace period). AuthZ: accountOwner on both routes. The extension hook
 * (AccountDeletionPrecondition) is covered in tests/Extension/.
 *
 * Most cases need a NON-default account (the self-host-undeletable guard
 * would otherwise always fire, since IntegrationTestCase::testConfig()'s
 * self-host routing mode ALWAYS resolves ATTR_ACCOUNT_ID to the seeded
 * default account — see AccountContextMiddleware class doc) — those tests
 * run in cloud routing mode instead (CloudRoutingTest's established
 * pattern), hitting the {account}-prefixed path for a freshly inserted,
 * non-default account.
 */
final class AccountDeleteActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /** @return \Slim\App<null> */
    private function cloudApp(): \Slim\App
    {
        $config = Config::fromArray([
            'env'                  => 'dev',
            'app_url'              => 'http://localhost:8000',
            'app_key'              => str_repeat('a', 64),
            'identity_server_key'  => self::identityServerKey(),
            'db'                   => ['name' => ':memory:'],
            'smtp'                 => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'       => 900,
            'routing_mode'         => 'cloud',
        ]);

        return AppFactory::create(
            $config,
            $this->conn,
            new InMemoryMailer(),
            new AuditLogger($this->logFile),
            planPolicy: self::syntheticPlanPolicy(),
            extensions: [],
        );
    }

    public function test_owner_can_schedule_deletion_with_correct_confirmation(): void
    {
        $accountId = $this->insertAccount(['slug' => 'delete-me-account', 'plan' => 'starter']);
        $ownerId   = $this->insertUser('owner-delete@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $response = $this->cloudApp()->handle($this->post(
            '/delete-me-account/admin/account/delete',
            ['confirm_slug' => 'delete-me-account'],
            $ownerId,
        ));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertIsString($data['deletion_scheduled_at'] ?? null);

        $scheduledAt = $this->conn->fetchOne(
            'SELECT deletion_scheduled_at FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );
        self::assertNotNull($scheduledAt);

        // Grace period is ~48h.
        $deadline = new \DateTimeImmutable((string) $scheduledAt);
        $hoursOut = ($deadline->getTimestamp() - time()) / 3600;
        self::assertGreaterThan(46, $hoursOut);
        self::assertLessThan(50, $hoursOut);
    }

    public function test_wrong_confirmation_text_is_rejected(): void
    {
        $accountId = $this->insertAccount(['slug' => 'delete-me-wrong', 'plan' => 'starter']);
        $ownerId   = $this->insertUser('owner-wrong@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $response = $this->cloudApp()->handle($this->post(
            '/delete-me-wrong/admin/account/delete',
            ['confirm_slug' => 'not-the-real-slug'],
            $ownerId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('confirmation_mismatch', $data['error']['key'] ?? null);

        $scheduledAt = $this->conn->fetchOne(
            'SELECT deletion_scheduled_at FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );
        self::assertNull($scheduledAt);
    }

    public function test_default_self_host_account_cannot_be_deleted(): void
    {
        $accountId = $this->defaultAccountId();
        $slug      = $this->defaultAccountSlug();
        $ownerId   = $this->insertUser('owner-default@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        // Self-host routing mode — ATTR_ACCOUNT_ID always resolves to the
        // (is_default=1) default account, exactly the case this guard exists for.
        $response = $this->createApp()->handle($this->post(
            '/admin/account/delete',
            ['confirm_slug' => $slug],
            $ownerId,
        ));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('default_account_undeletable', $data['error']['key'] ?? null);
    }

    public function test_owner_can_undo_a_pending_deletion(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner-undo@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->conn->update(
            'accounts',
            ['deletion_scheduled_at' => (new \DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s')],
            ['id' => $accountId],
        );

        $response = $this->createApp()->handle($this->post(
            '/admin/account/delete/cancel',
            [],
            $ownerId,
        ));

        self::assertSame(200, $response->getStatusCode());
        $scheduledAt = $this->conn->fetchOne(
            'SELECT deletion_scheduled_at FROM accounts WHERE id = :id',
            ['id' => $accountId],
        );
        self::assertNull($scheduledAt);
    }

    public function test_moderator_is_rejected(): void
    {
        $accountId = $this->defaultAccountId();
        $modId     = $this->insertUser('mod-delete@example.com');
        $this->insertAccountMember($accountId, $modId, 'moderator');

        $response = $this->createApp()->handle($this->post(
            '/admin/account/delete',
            ['confirm_slug' => $this->defaultAccountSlug()],
            $modId,
        ));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_is_rejected(): void
    {
        $response = $this->createApp()->handle($this->post('/admin/account/delete', [], null));

        self::assertSame(401, $response->getStatusCode());
    }
}
