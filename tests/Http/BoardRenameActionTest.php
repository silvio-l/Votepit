<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the board-rename write path (PUT
 * /admin/boards/{slug}).
 *
 * ACs:
 *  AC1  — Owner renames title only → 200, slug unchanged, name updated
 *  AC2  — Owner renames slug only → 200, title unchanged, slug updated
 *  AC3  — Owner renames both title and slug in one request → 200
 *  AC4  — Moderator can rename → also succeeds
 *  AC5  — Anon → 401
 *  AC6  — Logged in without account membership → 403
 *  AC7  — Missing CSRF → 403
 *  AC8  — Slug collision within own account → 422 fields.slug, board unchanged
 *  AC9  — Same slug in a foreign account is unaffected (no cross-tenant leak)
 *  AC10 — Renaming to a slug that just got tombstoned (recently deleted board
 *         of the same account) → 422 fields.slug
 *  AC11 — Invalid slug characters / reserved word → 422 fields.slug
 *  AC12 — Empty name / empty slug (explicitly sent) → 422
 *  AC13 — Omitted fields (empty body) → 200, no-op, nothing changed
 *  AC14 — Renaming to the board's own current slug → 200, not treated as a collision
 *  AC15 — Foreign board (unknown slug for this account) → 404
 *  AC16 — Frozen board → 423, board unchanged
 *  AC17 — Successful rename produces an audit log entry
 */
final class BoardRenameActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function seedUser(string $email, ?string $role = null): int
    {
        $userId = $this->insertUser($email);

        if ($role !== null) {
            $this->insertAccountMember($this->defaultAccountId(), $userId, $role);
        }

        return $userId;
    }

    /** @param array<string, string> $fields */
    private function putRequest(
        string $slug,
        array $fields,
        ?int $userId,
        bool $withCsrf = true,
    ): ServerRequestInterface {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $cookies = [];
        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }
        if ($withCsrf) {
            $cookies['votepit_csrf'] = $csrf->sign($csrfToken);
            $fields['_csrf']         = $csrfToken;
        }

        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/admin/boards/' . $slug)
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // =========================================================================
    // AC1 — Title only
    // =========================================================================

    public function test_owner_renames_title_only(): void
    {
        $ownerId = $this->seedUser('owner-title@example.com', 'owner');
        $this->insertBoard('title-only', ['name' => 'Old Title']);

        $response = $this->createApp()->handle(
            $this->putRequest('title-only', ['name' => 'New Title'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE slug = :s', ['s' => 'title-only']);
        self::assertIsArray($row);
        self::assertSame('New Title', $row['name']);
    }

    // =========================================================================
    // AC2 — Slug only
    // =========================================================================

    public function test_owner_renames_slug_only(): void
    {
        $ownerId = $this->seedUser('owner-slug@example.com', 'owner');
        $this->insertBoard('slug-old', ['name' => 'Stable Title']);

        $response = $this->createApp()->handle(
            $this->putRequest('slug-old', ['slug' => 'slug-new'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE slug = :s', ['s' => 'slug-new']);
        self::assertIsArray($row);
        self::assertSame('Stable Title', $row['name']);

        $oldCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'slug-old']);
        self::assertSame(0, $oldCount);
    }

    // =========================================================================
    // AC3 — Both at once
    // =========================================================================

    public function test_owner_renames_title_and_slug_together(): void
    {
        $ownerId = $this->seedUser('owner-both@example.com', 'owner');
        $this->insertBoard('both-old', ['name' => 'Old']);

        $response = $this->createApp()->handle(
            $this->putRequest('both-old', ['name' => 'New', 'slug' => 'both-new'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE slug = :s', ['s' => 'both-new']);
        self::assertIsArray($row);
        self::assertSame('New', $row['name']);
    }

    // =========================================================================
    // AC4 — Admin can rename; moderator is restricted to comment/idea
    // moderation only and cannot (accountAdmin no longer includes moderator).
    // =========================================================================

    public function test_admin_can_rename(): void
    {
        $adminId = $this->seedUser('admin-rename@example.com', 'admin');
        $this->insertBoard('admin-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('admin-rename-board', ['name' => 'Admin Renamed'], $adminId),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_moderator_cannot_rename_returns_403(): void
    {
        $modId = $this->seedUser('mod-rename@example.com', 'moderator');
        $this->insertBoard('mod-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('mod-rename-board', ['name' => 'Mod Renamed'], $modId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // AC5/AC6 — AuthZ
    // =========================================================================

    public function test_anon_rename_returns_401(): void
    {
        $this->insertBoard('anon-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('anon-rename-board', ['name' => 'X'], null),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_user_without_membership_returns_403(): void
    {
        $userId = $this->seedUser('no-membership-rename@example.com');
        $this->insertBoard('no-access-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('no-access-rename-board', ['name' => 'X'], $userId),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // AC7 — CSRF
    // =========================================================================

    public function test_rename_without_csrf_returns_403(): void
    {
        $ownerId = $this->seedUser('owner-csrf-rename@example.com', 'owner');
        $this->insertBoard('csrf-rename-board', ['name' => 'Original']);

        $response = $this->createApp()->handle(
            $this->putRequest('csrf-rename-board', ['name' => 'Hacked'], $ownerId, withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT name FROM boards WHERE slug = :s', ['s' => 'csrf-rename-board']);
        self::assertIsArray($row);
        self::assertSame('Original', $row['name']);
    }

    // =========================================================================
    // AC8 — Slug collision within own account
    // =========================================================================

    public function test_slug_collision_within_account_returns_422(): void
    {
        $ownerId = $this->seedUser('owner-collision-rename@example.com', 'owner');
        $this->insertBoard('taken-rename-slug');
        $this->insertBoard('movable-rename-board', ['name' => 'Movable']);

        $response = $this->createApp()->handle(
            $this->putRequest('movable-rename-board', ['slug' => 'taken-rename-slug'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);

        $row = $this->conn->fetchAssociative('SELECT slug FROM boards WHERE name = :n', ['n' => 'Movable']);
        self::assertIsArray($row);
        self::assertSame('movable-rename-board', $row['slug']);
    }

    // =========================================================================
    // AC9 — Foreign account unaffected
    // =========================================================================

    public function test_rename_to_slug_used_in_a_foreign_account_succeeds(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-rename', 'name' => 'Foreign Account']);
        $this->insertBoard('shared-rename-slug', ['account_id' => $foreignAccountId]);

        $ownerId = $this->seedUser('owner-foreign-rename@example.com', 'owner');
        $this->insertBoard('own-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('own-rename-board', ['slug' => 'shared-rename-slug'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM boards WHERE slug = :s AND account_id = :a',
            ['s' => 'shared-rename-slug', 'a' => $this->defaultAccountId()],
        );
        self::assertSame(1, $count);
    }

    // =========================================================================
    // AC10 — Tombstoned slug
    // =========================================================================

    public function test_rename_to_a_recently_deleted_slug_returns_422(): void
    {
        $ownerId   = $this->seedUser('owner-tombstone-rename@example.com', 'owner');
        $accountId = $this->defaultAccountId();

        $this->conn->insert('slug_tombstones', [
            'scope'      => 'board',
            'account_id' => $accountId,
            'slug'       => 'cooling-down-slug',
            'expires_at' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s'),
        ]);
        $this->insertBoard('tombstone-target-board');

        $response = $this->createApp()->handle(
            $this->putRequest('tombstone-target-board', ['slug' => 'cooling-down-slug'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
    }

    // =========================================================================
    // AC11 — Invalid slug / reserved word
    // =========================================================================

    /** @return iterable<string, array{string}> */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['Invalid-Slug'];
        yield 'spaces' => ['invalid slug'];
        yield 'underscore' => ['invalid_slug'];
        yield 'reserved' => ['admin'];
    }

    #[DataProvider('invalidSlugProvider')]
    public function test_invalid_slug_returns_422_no_500(string $slug): void
    {
        $ownerId = $this->seedUser('owner-invalid-rename-' . md5($slug) . '@example.com', 'owner');
        $this->insertBoard('invalid-rename-target-' . md5($slug));

        $response = $this->createApp()->handle(
            $this->putRequest('invalid-rename-target-' . md5($slug), ['slug' => $slug], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    // =========================================================================
    // AC12 — Explicitly empty fields
    // =========================================================================

    public function test_explicitly_empty_name_returns_422(): void
    {
        $ownerId = $this->seedUser('owner-empty-name-rename@example.com', 'owner');
        $this->insertBoard('empty-name-rename-board', ['name' => 'Keep Me']);

        $response = $this->createApp()->handle(
            $this->putRequest('empty-name-rename-board', ['name' => ''], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT name FROM boards WHERE slug = :s', ['s' => 'empty-name-rename-board']);
        self::assertIsArray($row);
        self::assertSame('Keep Me', $row['name']);
    }

    public function test_explicitly_empty_slug_returns_422(): void
    {
        $ownerId = $this->seedUser('owner-empty-slug-rename@example.com', 'owner');
        $this->insertBoard('empty-slug-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('empty-slug-rename-board', ['slug' => ''], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // =========================================================================
    // AC13 — Omitted fields = no-op
    // =========================================================================

    public function test_omitted_fields_are_a_no_op_success(): void
    {
        $ownerId = $this->seedUser('owner-noop-rename@example.com', 'owner');
        $this->insertBoard('noop-rename-board', ['name' => 'Unchanged']);

        $response = $this->createApp()->handle(
            $this->putRequest('noop-rename-board', [], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT slug, name FROM boards WHERE slug = :s', ['s' => 'noop-rename-board']);
        self::assertIsArray($row);
        self::assertSame('Unchanged', $row['name']);
    }

    // =========================================================================
    // AC14 — Own current slug is not a self-collision
    // =========================================================================

    public function test_renaming_to_own_current_slug_succeeds(): void
    {
        $ownerId = $this->seedUser('owner-self-slug-rename@example.com', 'owner');
        $this->insertBoard('self-slug-rename-board', ['name' => 'Old']);

        $response = $this->createApp()->handle(
            $this->putRequest('self-slug-rename-board', ['slug' => 'self-slug-rename-board', 'name' => 'New'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT name FROM boards WHERE slug = :s', ['s' => 'self-slug-rename-board']);
        self::assertIsArray($row);
        self::assertSame('New', $row['name']);
    }

    // =========================================================================
    // AC15 — Unknown board
    // =========================================================================

    public function test_unknown_board_returns_404(): void
    {
        $ownerId = $this->seedUser('owner-404-rename@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->putRequest('does-not-exist-board', ['name' => 'X'], $ownerId),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // AC16 — Frozen board
    // =========================================================================

    public function test_frozen_board_returns_423(): void
    {
        $ownerId = $this->seedUser('owner-frozen-rename@example.com', 'owner');
        $boardId = $this->insertBoard('frozen-rename-board', ['name' => 'Original']);
        $this->conn->update(
            'boards',
            ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
            ['id' => $boardId],
        );

        $response = $this->createApp()->handle(
            $this->putRequest('frozen-rename-board', ['name' => 'Hacked'], $ownerId),
        );

        self::assertSame(423, $response->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT name FROM boards WHERE slug = :s', ['s' => 'frozen-rename-board']);
        self::assertIsArray($row);
        self::assertSame('Original', $row['name']);
    }

    // =========================================================================
    // AC17 — Audit log
    // =========================================================================

    public function test_successful_rename_writes_audit_log(): void
    {
        $ownerId = $this->seedUser('owner-audit-rename@example.com', 'owner');
        $this->insertBoard('audit-rename-board');

        $response = $this->createApp()->handle(
            $this->putRequest('audit-rename-board', ['name' => 'Audited'], $ownerId),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('board.renamed', $this->readAuditLog());
    }
}
