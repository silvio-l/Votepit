<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for PUT /admin/account (rename) + GET
 * /admin/account/slug-available (live-check), AccountRenameAction.
 *
 * ACs:
 *  AC1  — Owner renames name only → 200, slug unchanged
 *  AC2  — Owner renames slug only → 200, name unchanged
 *  AC3  — Moderator is forbidden (accountOwner tier, stricter than boards)
 *  AC4  — Anon → 401
 *  AC5  — Missing CSRF → 403
 *  AC6  — Slug taken by ANOTHER account → 422 fields.slug (global uniqueness,
 *         unlike a board slug which is only unique per account)
 *  AC7  — Invalid slug characters / reserved word → 422 fields.slug
 *  AC8  — Explicitly empty name/slug → 422
 *  AC9  — Omitted fields → 200, no-op
 *  AC10 — Renaming to the account's own current slug → 200, not a collision
 *  AC11 — Successful rename writes an audit log entry
 *  AC12 — slug-available: taken slug → {available:false}
 *  AC13 — slug-available: free slug → {available:true}
 *  AC14 — slug-available: the account's own current slug → {available:true}
 */
final class AccountRenameActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function put(array $body, ?int $userId, bool $withCsrf = true): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [];
        if ($withCsrf) {
            $cookies[$csrf->cookieName()] = $signed;
        }
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($userId);
        }

        $fields = $withCsrf ? array_merge(['_csrf' => $token], $body) : $body;

        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/admin/account')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    private function getSlugAvailable(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/account/slug-available?slug=' . urlencode($slug));

        if ($userId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
        }

        return $request;
    }

    // =========================================================================
    // AC1/AC2 — independent fields
    // =========================================================================

    public function test_owner_renames_name_only(): void
    {
        $ownerId = $this->insertUser('owner-acct-name@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['name' => 'New Name'], $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug, name FROM accounts WHERE id = :id', ['id' => $this->defaultAccountId()]);
        self::assertIsArray($row);
        self::assertSame('New Name', $row['name']);
        self::assertSame($this->defaultAccountSlug(), $row['slug']);
    }

    public function test_owner_renames_slug_only(): void
    {
        $ownerId = $this->insertUser('owner-acct-slug@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['slug' => 'new-account-slug'], $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug FROM accounts WHERE id = :id', ['id' => $this->defaultAccountId()]);
        self::assertIsArray($row);
        self::assertSame('new-account-slug', $row['slug']);
    }

    // =========================================================================
    // AC3/AC4 — AuthZ
    // =========================================================================

    public function test_moderator_is_forbidden(): void
    {
        $modId = $this->insertUser('mod-acct-rename@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $modId, 'moderator');

        $response = $this->createApp()->handle($this->put(['name' => 'X'], $modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_returns_401(): void
    {
        $response = $this->createApp()->handle($this->put(['name' => 'X'], null));

        self::assertSame(401, $response->getStatusCode());
    }

    // =========================================================================
    // AC5 — CSRF
    // =========================================================================

    public function test_rename_without_csrf_returns_403(): void
    {
        $ownerId = $this->insertUser('owner-acct-csrf@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['name' => 'Hacked'], $ownerId, withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // AC6 — Global slug uniqueness (cross-account)
    // =========================================================================

    public function test_slug_taken_by_another_account_returns_422(): void
    {
        $this->insertAccount(['slug' => 'taken-globally', 'name' => 'Other Account']);

        $ownerId = $this->insertUser('owner-acct-collision@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['slug' => 'taken-globally'], $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);

        $row = $this->conn->fetchAssociative('SELECT slug FROM accounts WHERE id = :id', ['id' => $this->defaultAccountId()]);
        self::assertIsArray($row);
        self::assertSame($this->defaultAccountSlug(), $row['slug']);
    }

    // =========================================================================
    // AC7 — Invalid slug
    // =========================================================================

    public function test_invalid_slug_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-acct-invalid@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['slug' => 'admin'], $ownerId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
    }

    // =========================================================================
    // AC8 — Explicitly empty fields
    // =========================================================================

    public function test_explicitly_empty_name_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-acct-empty-name@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['name' => ''], $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_explicitly_empty_slug_returns_422(): void
    {
        $ownerId = $this->insertUser('owner-acct-empty-slug@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['slug' => ''], $ownerId));

        self::assertSame(422, $response->getStatusCode());
    }

    // =========================================================================
    // AC9 — Omitted fields
    // =========================================================================

    public function test_omitted_fields_are_a_no_op_success(): void
    {
        $ownerId = $this->insertUser('owner-acct-noop@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put([], $ownerId));

        self::assertSame(200, $response->getStatusCode());
    }

    // =========================================================================
    // AC10 — Own current slug is not a self-collision
    // =========================================================================

    public function test_renaming_to_own_current_slug_succeeds(): void
    {
        $ownerId = $this->insertUser('owner-acct-self-slug@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle(
            $this->put(['slug' => $this->defaultAccountSlug(), 'name' => 'Renamed'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT name FROM accounts WHERE id = :id', ['id' => $this->defaultAccountId()]);
        self::assertIsArray($row);
        self::assertSame('Renamed', $row['name']);
    }

    // =========================================================================
    // AC11 — Audit log
    // =========================================================================

    public function test_successful_rename_writes_audit_log(): void
    {
        $ownerId = $this->insertUser('owner-acct-audit@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->put(['name' => 'Audited'], $ownerId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('account.renamed', $this->readAuditLog());
    }

    // =========================================================================
    // AC12-AC14 — slug-available live check
    // =========================================================================

    public function test_slug_available_reports_taken_slug_as_unavailable(): void
    {
        $this->insertAccount(['slug' => 'live-check-taken', 'name' => 'Other Account']);

        $ownerId = $this->insertUser('owner-acct-check-taken@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getSlugAvailable('live-check-taken', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['available']);
    }

    public function test_slug_available_reports_free_slug_as_available(): void
    {
        $ownerId = $this->insertUser('owner-acct-check-free@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getSlugAvailable('completely-free-slug', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
    }

    public function test_slug_available_reports_own_current_slug_as_available(): void
    {
        $ownerId = $this->insertUser('owner-acct-check-own@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        $response = $this->createApp()->handle($this->getSlugAvailable($this->defaultAccountSlug(), $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['available']);
    }
}
