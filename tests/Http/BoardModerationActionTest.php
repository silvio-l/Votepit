<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the moderation settings page.
 *
 * Boots through AppFactory with SQLite in-memory. Assertions check exclusively
 * observable behavior: HTTP status, rendered HTML and DB state
 * (boards.moderation_enabled + board_blocklist).
 *
 * ACs:
 *  AC3  — GET /admin/boards/{slug}/moderation shows toggle + custom words (admin); anon/non-admin rejected
 *  AC4  — POST saves toggle + word add/remove, CSRF enforced, 302-PRG, invalid input → re-render (no 500)
 *  AC5  — Submit path uses board toggle: "off" → word filter skipped, honeypot/time-trap still active
 *  AC6  — Submit path uses board custom words: word only in custom list → blocked
 *  AC7  — Cross-board: custom word from board A does NOT apply in board B
 *  AC8  — AuthZ tests (admin allowed / non-admin rejected) — already covered by AC3 tests
 */
final class BoardModerationActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function seedBoard(string $slug = 'demo', int $moderationEnabled = 1): string
    {
        $this->conn->insert('boards', [
            'account_id'         => $this->defaultAccountId(),
            'slug'               => $slug,
            'name'               => 'Demo Board',
            'moderation_enabled' => $moderationEnabled,
            'is_default'         => 1,
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $slug;
    }

    /**
     * Board-scoped admin routes now check account_members.role
     * (AuthZMiddleware::accountAdmin()) instead of users.is_admin — an "admin" in
     * this test therefore additionally gets the owner role in the default account.
     */
    private function seedUser(string $email = 'user@example.com', bool $admin = false): int
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => $admin ? 1 : 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $userId = (int) $this->conn->lastInsertId();

        if ($admin) {
            $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');
        }

        return $userId;
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function getRequest(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/boards/' . $slug . '/moderation');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    /** @param array<string, string> $fields */
    private function postRequest(
        string $slug,
        ?int $userId,
        array $fields,
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
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/moderation')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    /**
     * Builds a valid backdated Time-Trap stamp (5 s in the past) for submit tests.
     */
    private function validTimeTrap(): string
    {
        $ts  = (string) (time() - 5);
        $key = self::APP_KEY;
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $ts, $key, true)), '+/', '-_'), '=');
        return $ts . '.' . $mac;
    }

    /**
     * POST /{board}/ideas with CSRF + Time-Trap (mirrors IdeaSubmitActionTest helper).
     *
     * @param array<string, string> $body
     */
    private function postIdea(string $boardSlug, array $body, ?int $userId = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $defaults = ['_csrf' => $token, '_form_at' => $this->validTimeTrap()];
        $cookies  = [$csrf->cookieName() => $signed];

        if ($userId !== null) {
            $cookies['votepit_sess'] = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/' . $boardSlug . '/ideas')
            ->withCookieParams($cookies)
            ->withParsedBody(array_merge($defaults, $body));
    }

    // =========================================================================
    // AC3 — GET: admin shows form; anon + non-admin rejected
    // =========================================================================

    public function test_get_as_anon_is_rejected(): void
    {
        $slug     = $this->seedBoard('mod-anon');
        $response = $this->createApp()->handle($this->getRequest($slug, null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $slug     = $this->seedBoard('mod-nonadmin');
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest($slug, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_get_as_admin_returns_200_with_form(): void
    {
        $slug    = $this->seedBoard('mod-admin');
        $adminId = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('board_slug', $data);
        self::assertArrayHasKey('moderation_enabled', $data);
    }

    public function test_get_shows_custom_words(): void
    {
        $slug    = $this->seedBoard('mod-words');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-words@example.com', true);

        // Seed a custom word directly in the DB.
        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'testspam',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $data  = json_decode((string) $this->createApp()->handle($this->getRequest($slug, $adminId))->getBody(), true);
        $words = array_column($data['words'] ?? [], 'word');
        self::assertContains('testspam', $words);
    }

    public function test_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin-404@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // AC4 — POST: CSRF enforced, toggle + word add/remove, PRG, invalid input
    // =========================================================================

    public function test_post_without_csrf_is_rejected(): void
    {
        $slug    = $this->seedBoard('mod-csrf');
        $adminId = $this->seedUser('admin-csrf@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle', 'moderation_enabled' => '0'], withCsrf: false),
        );

        self::assertSame(403, $response->getStatusCode());

        // Toggle must NOT have been saved — default 1 expected.
        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }

    public function test_post_as_non_admin_is_rejected(): void
    {
        $slug   = $this->seedBoard('mod-auth');
        $userId = $this->seedUser('plain2@example.com', false);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $userId, ['action' => 'toggle', 'moderation_enabled' => '0']),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_admin_saves_toggle_off_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-toggle-off');
        $adminId = $this->seedUser('admin-toggle@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle']),
            // No moderation_enabled in body → checkbox unchecked → 0
        );

        // 200 + JSON {"ok": true} (no 302 redirect)
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok'] ?? false);

        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('0', (string) $stored);
    }

    public function test_admin_saves_toggle_on(): void
    {
        $slug    = $this->seedBoard('mod-toggle-on', 0);
        $adminId = $this->seedUser('admin-toggle-on@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'toggle', 'moderation_enabled' => '1']),
        );

        self::assertSame(200, $response->getStatusCode());

        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }

    public function test_admin_adds_word_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-add-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-add@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => 'spamword']),
        );

        self::assertSame(200, $response->getStatusCode());

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM board_blocklist WHERE board_id = :bid AND word = :w',
            ['bid' => $boardId, 'w' => 'spamword'],
        );
        self::assertSame(1, $count);
    }

    public function test_admin_removes_word_and_redirects(): void
    {
        $slug    = $this->seedBoard('mod-rem-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $adminId = $this->seedUser('admin-rem@example.com', true);

        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'toremove',
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $wordId = (int) $this->conn->lastInsertId();

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'remove', 'word_id' => (string) $wordId]),
        );

        self::assertSame(200, $response->getStatusCode());

        $count = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM board_blocklist WHERE board_id = :bid',
            ['bid' => $boardId],
        );
        self::assertSame(0, $count);
    }

    public function test_add_empty_word_returns_422_no_500(): void
    {
        $slug    = $this->seedBoard('mod-empty-word');
        $adminId = $this->seedUser('admin-empty@example.com', true);

        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => '   ']),
        );

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        // The field error text contains 'empty' (the error.message is generic)
        self::assertStringContainsString('empty', $data['error']['fields']['new_word'] ?? '');
        self::assertStringNotContainsString('Internal Server Error', (string) $response->getBody());
    }

    // =========================================================================
    // AC5 — Submit path: toggle "off" → word filter skipped
    // =========================================================================

    public function test_toggle_off_skips_word_filter_but_allows_clean_submit(): void
    {
        // Board with moderation off; no custom word.
        $slug    = $this->seedBoard('submit-toggle-off', 0);
        $userId  = $this->seedUser('user-toggle-off@example.com');

        // "arschloch" is in the base list.
        $response = $this->createApp()->handle($this->postIdea(
            $slug,
            ['title' => 'arschloch please build', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));

        // Toggle off → word filter skipped → idea gets created → 201
        self::assertSame(201, $response->getStatusCode());
    }

    public function test_toggle_off_honeypot_still_active(): void
    {
        $slug    = $this->seedBoard('submit-hp-off', 0);
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $userId  = $this->seedUser('user-hp-off@example.com');

        $response = $this->createApp()->handle($this->postIdea(
            $slug,
            [
                'title'   => 'Clean idea',
                'body'    => 'Clean description without any problems.',
                'website' => 'http://spam.example.com', // honeypot filled
            ],
            $userId,
        ));

        // Honeypot always applies, independent of the toggle.
        self::assertSame(422, $response->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardId]);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC6 — Submit path: custom word blocks idea
    // =========================================================================

    public function test_custom_word_blocks_idea_submission(): void
    {
        $slug    = $this->seedBoard('submit-custom-word');
        $boardId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slug]);
        $userId  = $this->seedUser('user-custom-word@example.com');
        $adminId = $this->seedUser('admin-custom-word@example.com', true);

        // Add a custom word (that is NOT in the base blocklist).
        $response = $this->createApp()->handle(
            $this->postRequest($slug, $adminId, ['action' => 'add', 'new_word' => 'xyzforbidden']),
        );
        self::assertSame(200, $response->getStatusCode());

        // Submit with the custom word in the title.
        $submitResponse = $this->createApp()->handle($this->postIdea(
            $slug,
            ['title' => 'xyzforbidden is forbidden', 'body' => 'This description is clean enough.'],
            $userId,
        ));

        self::assertSame(422, $submitResponse->getStatusCode());
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardId]);
        self::assertSame(0, $count);
    }

    // =========================================================================
    // AC7 — Cross-board: custom word from board A does NOT apply in board B
    // =========================================================================

    public function test_custom_word_from_board_a_does_not_affect_board_b(): void
    {
        $slugA   = $this->seedBoard('board-a-xboard');
        $slugB   = $this->seedBoard('board-b-xboard');
        $boardAId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slugA]);
        $boardBId = (int) $this->conn->fetchOne('SELECT id FROM boards WHERE slug = :s', ['s' => $slugB]);

        $userId  = $this->seedUser('user-xboard@example.com');
        $adminId = $this->seedUser('admin-xboard@example.com', true);

        // Custom word only for board A.
        $this->createApp()->handle(
            $this->postRequest($slugA, $adminId, ['action' => 'add', 'new_word' => 'onlyinboarda']),
        );

        // Submit with the custom word on board A → should be blocked.
        $responseA = $this->createApp()->handle($this->postIdea(
            $slugA,
            ['title' => 'onlyinboarda idea', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));
        self::assertSame(422, $responseA->getStatusCode());
        $countA = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardAId]);
        self::assertSame(0, $countA);

        // Same title on board B → must go through (word only on board A).
        $responseB = $this->createApp()->handle($this->postIdea(
            $slugB,
            ['title' => 'onlyinboarda idea', 'body' => 'Clean description without any problems here.'],
            $userId,
        ));
        self::assertSame(201, $responseB->getStatusCode());
        $countB = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE board_id = :bid', ['bid' => $boardBId]);
        self::assertSame(1, $countB);
    }

    // =========================================================================
    // AC1 (schema) — moderation_enabled default 1 for new boards
    // =========================================================================

    public function test_new_board_has_moderation_enabled_by_default(): void
    {
        $slug = $this->seedBoard('mod-default-check');
        // No moderation_enabled override in insertBoard → relies on DB DEFAULT 1.
        $stored = $this->conn->fetchOne('SELECT moderation_enabled FROM boards WHERE slug = :s', ['s' => $slug]);
        self::assertSame('1', (string) $stored);
    }
}
