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
 * Integration tests for the board-creation write path.
 *
 * Boots through AppFactory with SQLite in-memory. Assertions check exclusively
 * observable behavior: HTTP status, JSON response and DB state (boards).
 *
 * ACs:
 *  AC1  — Owner creates a valid board → 201, board exists (account_id =
 *         defaultAccountId(), is_default = 0)
 *  AC2  — Moderator creates a board → also succeeds
 *  AC3  — Anon → 401
 *  AC4  — Logged in without account membership → 403
 *  AC5  — Slug collision within own account → 422 fields.slug, no second board
 *  AC6  — Same slug in a foreign account → own creation still succeeds
 *  AC7  — Invalid slug characters (uppercase, spaces, underscore, umlaut) → 422 fields.slug
 *  AC8  — Reserved-word slug → 422 fields.slug
 *  AC9  — Empty/too-long name or slug → 422, no 500
 *  AC10 — Missing CSRF → 403
 *  AC11 — Successful creation produces an audit log entry
 */
final class BoardCreateActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /**
     * Seeds a verified user; optionally with a role in the
     * default account (owner/moderator). Without $role: no membership.
     */
    private function seedUser(string $email, ?string $role = null): int
    {
        $userId = $this->insertUser($email);

        if ($role !== null) {
            $this->insertAccountMember($this->defaultAccountId(), $userId, $role);
        }

        return $userId;
    }

    /** @param array<string, mixed> $fields */
    private function postRequest(
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
            ->createServerRequest('POST', '/admin/boards')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // =========================================================================
    // AC1 — Owner creates a valid board
    // =========================================================================

    public function test_owner_creates_board_returns_201_and_persists(): void
    {
        $ownerId = $this->seedUser('owner@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'New board', 'slug' => 'new-board'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);
        self::assertSame('new-board', $data['slug'] ?? null);
        self::assertSame('New board', $data['name'] ?? null);

        $row = $this->conn->fetchAssociative(
            'SELECT account_id, is_default FROM boards WHERE slug = :s',
            ['s' => 'new-board'],
        );
        self::assertIsArray($row);
        self::assertSame($this->defaultAccountId(), (int) $row['account_id']);
        self::assertSame(0, (int) $row['is_default']);
    }

    // =========================================================================
    // Board templates (setup wizard) — optional anonymous_voting_enabled at
    // creation, same strict parsing as BoardBrandingAction: only true/'1'/1
    // turn it on, anything else (or omitting it) leaves the safe default off.
    // =========================================================================

    public function test_create_with_anonymous_voting_enabled_persists_it(): void
    {
        $ownerId = $this->seedUser('owner@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Summer party', 'slug' => 'summer-party', 'anonymous_voting_enabled' => true], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->anonymousVotingFlag('summer-party'));
    }

    public function test_create_without_anonymous_voting_field_keeps_it_off(): void
    {
        $ownerId = $this->seedUser('owner@example.com', 'owner');

        $this->createApp()->handle(
            $this->postRequest(['name' => 'New board', 'slug' => 'new-board'], $ownerId),
        );

        self::assertSame(0, $this->anonymousVotingFlag('new-board'));
    }

    public function test_create_with_non_boolean_anonymous_voting_value_keeps_it_off(): void
    {
        $ownerId = $this->seedUser('owner@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'New board', 'slug' => 'new-board', 'anonymous_voting_enabled' => 'yes'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(0, $this->anonymousVotingFlag('new-board'));
    }

    private function anonymousVotingFlag(string $slug): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT anonymous_voting_enabled FROM boards WHERE slug = :s',
            ['s' => $slug],
        );
    }

    // =========================================================================
    // AC2 — Admin creates a board; moderator is restricted to comment/idea
    // moderation only and cannot (AuthZMiddleware::accountAdmin() no longer
    // includes 'moderator', see its class doc).
    // =========================================================================

    public function test_admin_creates_board_succeeds(): void
    {
        $adminId = $this->seedUser('admin@example.com', 'admin');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Admin Board', 'slug' => 'admin-board'], $adminId),
        );

        self::assertSame(201, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'admin-board']);
        self::assertSame(1, $count);
    }

    public function test_moderator_cannot_create_board_returns_403(): void
    {
        $modId = $this->seedUser('mod@example.com', 'moderator');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Mod Board', 'slug' => 'mod-board'], $modId),
        );

        self::assertSame(403, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'mod-board']);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC3 — Anon → 401
    // =========================================================================

    public function test_anon_create_returns_401(): void
    {
        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Anon Board', 'slug' => 'anon-board'], null),
        );

        self::assertSame(401, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'anon-board']);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC4 — Logged in without account membership → 403
    // =========================================================================

    public function test_user_without_membership_returns_403(): void
    {
        $userId = $this->seedUser('no-membership@example.com');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'No Access Board', 'slug' => 'no-access-board'], $userId),
        );

        self::assertSame(403, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'no-access-board']);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC5 — Slug collision within own account
    // =========================================================================

    public function test_slug_collision_within_account_returns_422(): void
    {
        $ownerId = $this->seedUser('owner-collision@example.com', 'owner');
        $this->insertBoard('taken-slug');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Another Board', 'slug' => 'taken-slug'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'taken-slug']);
        self::assertSame(1, $count);
    }

    // =========================================================================
    // AC6 — Same slug in a foreign account still succeeds
    // =========================================================================

    public function test_same_slug_in_foreign_account_still_succeeds(): void
    {
        $foreignAccountId = $this->insertAccount(['slug' => 'acct-foreign-create', 'name' => 'Foreign Account']);
        $this->insertBoard('shared-name', ['account_id' => $foreignAccountId]);

        $ownerId = $this->seedUser('owner-shared-name@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Shared Name Board', 'slug' => 'shared-name'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM boards WHERE slug = :s AND account_id = :a',
            ['s' => 'shared-name', 'a' => $this->defaultAccountId()],
        );
        self::assertSame(1, $count);
    }

    // =========================================================================
    // AC7 — Invalid slug characters
    // =========================================================================

    /** @return iterable<string, array{string}> */
    public static function invalidSlugProvider(): iterable
    {
        yield 'uppercase' => ['Invalid-Slug'];
        yield 'spaces' => ['invalid slug'];
        yield 'underscore' => ['invalid_slug'];
        yield 'umlaut' => ['ünvalid-slug']; // export-ok: comment-language (deliberate umlaut input)
    }

    #[DataProvider('invalidSlugProvider')]
    public function test_invalid_slug_characters_return_422_no_500(string $slug): void
    {
        $ownerId = $this->seedUser('owner-invalid-' . md5($slug) . '@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Invalid Slug Board', 'slug' => $slug], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    // =========================================================================
    // AC8 — Reserved-word slug
    // =========================================================================

    public function test_reserved_word_slug_returns_422(): void
    {
        $ownerId = $this->seedUser('owner-reserved@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Admin Board', 'slug' => 'admin'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
    }

    // =========================================================================
    // AC9 — Empty/too-long name or slug
    // =========================================================================

    public function test_empty_name_returns_422_no_500(): void
    {
        $ownerId = $this->seedUser('owner-empty-name@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => '', 'slug' => 'empty-name-board'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('name', $data['error']['fields'] ?? []);
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    public function test_too_long_name_returns_422_no_500(): void
    {
        $ownerId = $this->seedUser('owner-long-name@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => str_repeat('a', 129), 'slug' => 'long-name-board'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('name', $data['error']['fields'] ?? []);
    }

    public function test_empty_slug_returns_422_no_500(): void
    {
        $ownerId = $this->seedUser('owner-empty-slug@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Empty Slug Board', 'slug' => ''], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    public function test_too_long_slug_returns_422_no_500(): void
    {
        $ownerId = $this->seedUser('owner-long-slug@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Long Slug Board', 'slug' => str_repeat('a', 65)], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('slug', $data['error']['fields'] ?? []);
    }

    // =========================================================================
    // AC10 — Missing CSRF → 403
    // =========================================================================

    public function test_post_without_csrf_returns_403(): void
    {
        $ownerId = $this->seedUser('owner-csrf@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'No CSRF Board', 'slug' => 'no-csrf-board'], $ownerId, withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'no-csrf-board']);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC11 — Successful creation produces an audit log entry
    // =========================================================================

    public function test_successful_create_writes_audit_log(): void
    {
        $ownerId = $this->seedUser('owner-audit@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Audit Board', 'slug' => 'audit-board'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('board.created', $this->readAuditLog());
    }

    // =========================================================================
    // Tier enforcement: board-count plan limit
    // =========================================================================

    public function test_free_plan_blocks_second_board(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $ownerId = $this->seedUser('owner-free-boards@example.com', 'owner');
        $this->insertBoard('free-board-one'); // Free's 1-board limit already reached.

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Second Board', 'slug' => 'free-board-two'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_boards', $data['error']['key'] ?? null);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'free-board-two']);
        self::assertSame(0, $count);
    }

    /**
     * OWASP/BSI audit remediation issue 18 (business-logic locking): two
     * requests racing at the board limit must not both succeed. Simulated
     * as two immediate, back-to-back requests against the same app/DB
     * connection (same pattern the repo already uses for "concurrent"
     * behavior, see InviteAcceptActionTest's replay-window tests) — the
     * second one's atomic re-check (BoardRepository::createWithinLimit())
     * must see the first one's committed INSERT and reject.
     */
    public function test_two_requests_racing_at_the_board_limit_only_one_succeeds(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free'); // board_limit = 1
        $ownerId = $this->seedUser('owner-race-boards@example.com', 'owner');
        $app     = $this->createApp();

        $first  = $app->handle($this->postRequest(['name' => 'Race One', 'slug' => 'race-board-one'], $ownerId));
        $second = $app->handle($this->postRequest(['name' => 'Race Two', 'slug' => 'race-board-two'], $ownerId));

        $statuses = [$first->getStatusCode(), $second->getStatusCode()];
        sort($statuses);
        self::assertSame([201, 422], $statuses);

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM boards WHERE account_id = :a',
            ['a' => $this->defaultAccountId()],
        );
        self::assertSame(1, $count);
    }

    public function test_lite_plan_blocks_sixth_board(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $ownerId = $this->seedUser('owner-lite-boards@example.com', 'owner');
        for ($i = 1; $i <= 5; $i++) {
            $this->insertBoard("lite-board-{$i}");
        }

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Sixth Board', 'slug' => 'lite-board-6'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_boards', $data['error']['key'] ?? null);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'lite-board-6']);
        self::assertSame(0, $count);
    }

    public function test_pro_plan_allows_many_boards(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $ownerId = $this->seedUser('owner-pro-boards@example.com', 'owner');
        for ($i = 1; $i <= 50; $i++) {
            $this->insertBoard("pro-board-{$i}");
        }

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Board 51', 'slug' => 'pro-board-51'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_unknown_plan_denies_board_creation(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'not-a-real-plan');
        $ownerId = $this->seedUser('owner-unknown-plan-boards@example.com', 'owner');
        // No pre-existing boards at all — still must be denied (fail-safe:
        // an unrecognized plan's boardLimit() is 0, so 0 >= 0 denies outright).

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Unknown Plan Board', 'slug' => 'unknown-plan-board'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('plan_limit_boards', $data['error']['key'] ?? null);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'unknown-plan-board']);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // Explicit, fail-secure visibility choice at board creation
    // =========================================================================

    public function test_explicit_valid_visibility_is_persisted(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite'); // all visibilities allowed
        $ownerId = $this->seedUser('owner-visibility-explicit@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Unlisted Board', 'slug' => 'unlisted-explicit', 'visibility' => 'unlisted'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('unlisted', $data['visibility'] ?? null);

        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => 'unlisted-explicit']);
        self::assertSame('unlisted', $visibility);
    }

    public function test_invalid_visibility_value_returns_422(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $ownerId = $this->seedUser('owner-visibility-invalid@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Bad Visibility Board', 'slug' => 'bad-visibility', 'visibility' => 'planet-wide'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'bad-visibility']);
        self::assertSame(0, $count);
    }

    public function test_visibility_not_allowed_on_plan_returns_422(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free'); // only 'public' allowed
        $ownerId = $this->seedUser('owner-visibility-gated@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Gated Board', 'slug' => 'gated-visibility', 'visibility' => 'private'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'gated-visibility']);
        self::assertSame(0, $count);
    }

    public function test_missing_visibility_defaults_to_safest_plan_allowed_value(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'lite'); // all visibilities allowed
        $ownerId = $this->seedUser('owner-visibility-default-full@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Default Visibility Board', 'slug' => 'default-visibility-full'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('private', $data['visibility'] ?? null);

        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => 'default-visibility-full']);
        self::assertSame('private', $visibility);
    }

    public function test_missing_visibility_on_public_only_plan_creates_public_board_not_422(): void
    {
        $this->setAccountPlan($this->defaultAccountId(), 'free'); // only 'public' allowed
        $ownerId = $this->seedUser('owner-visibility-default-public@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Default Visibility Board', 'slug' => 'default-visibility-public'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('public', $data['visibility'] ?? null);

        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => 'default-visibility-public']);
        self::assertSame('public', $visibility);
    }

    // -------------------------------------------------------------------------
    // Legal-operator gate (board-operator-compliance issue 02,
    // migrations/0058_add_legal_operator_identity.sql)
    // -------------------------------------------------------------------------

    public function test_public_visibility_rejected_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->seedUser('owner-legal-gate-public@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Gated Board', 'slug' => 'legal-gate-public', 'visibility' => 'public'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'legal-gate-public']);
        self::assertSame(0, $count);
    }

    public function test_unlisted_visibility_rejected_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->seedUser('owner-legal-gate-unlisted@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Gated Board', 'slug' => 'legal-gate-unlisted', 'visibility' => 'unlisted'], $ownerId),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('visibility', $data['error']['fields'] ?? []);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM boards WHERE slug = :s', ['s' => 'legal-gate-unlisted']);
        self::assertSame(0, $count);
    }

    public function test_private_visibility_allowed_when_legal_operator_name_empty(): void
    {
        $this->conn->update('accounts', ['legal_operator_name' => null], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->seedUser('owner-legal-gate-private@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Private Board', 'slug' => 'legal-gate-private', 'visibility' => 'private'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => 'legal-gate-private']);
        self::assertSame('private', $visibility);
    }

    public function test_public_visibility_accepted_when_legal_operator_name_set(): void
    {
        $this->conn->update('accounts', [
            'legal_operator_name'    => 'Acme GmbH',
            'legal_operator_address' => 'Musterstr. 1, 12345 Berlin',
            'legal_operator_email'   => 'jane@example.com',
        ], ['id' => $this->defaultAccountId()]);
        $ownerId = $this->seedUser('owner-legal-gate-set@example.com', 'owner');

        $response = $this->createApp()->handle(
            $this->postRequest(['name' => 'Public Board', 'slug' => 'legal-gate-set', 'visibility' => 'public'], $ownerId),
        );

        self::assertSame(201, $response->getStatusCode());
        $visibility = $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :s', ['s' => 'legal-gate-set']);
        self::assertSame('public', $visibility);
    }
}
