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
 * Integration tests for the ongoing board-operator-acknowledgment settings
 * page (board-operator-compliance ticket 05 follow-up).
 *
 * Boots through AppFactory with SQLite in-memory, which resolves the
 * Community default (NullBoardOperatorAcknowledgmentGate) — status() is
 * always null and validate()/record() never reject/persist, so these tests
 * cover the AuthZ/routing/response-shape contract every edition shares.
 * The Cloud-specific gate behavior (BoardCreationAcknowledgmentGate
 * delegating to the repository) is covered in cloud/tests/Legal/.
 */
final class BoardOperatorAcknowledgmentActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function seedBoard(string $slug = 'demo'): string
    {
        $this->conn->insert('boards', [
            'account_id' => $this->defaultAccountId(),
            'slug'       => $slug,
            'name'       => 'Demo Board',
            'visibility' => 'public',
            'is_default' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $slug;
    }

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
            ->createServerRequest('GET', '/admin/boards/' . $slug . '/operator-acknowledgment');

        if ($userId !== null) {
            $request = $request->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
            ]);
        }

        return $request;
    }

    /** @param array<string, string> $fields */
    private function postRequest(string $slug, ?int $userId, array $fields = [], bool $withCsrf = true): ServerRequestInterface
    {
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
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/operator-acknowledgment')
            ->withCookieParams($cookies)
            ->withParsedBody($fields);
    }

    // -------------------------------------------------------------------------
    // AuthZ
    // -------------------------------------------------------------------------

    public function test_get_as_anon_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $response = $this->createApp()->handle($this->getRequest($slug, null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_get_as_non_admin_is_rejected(): void
    {
        $slug     = $this->seedBoard();
        $userId   = $this->seedUser('plain@example.com', false);
        $response = $this->createApp()->handle($this->getRequest($slug, $userId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_post_without_csrf_returns_403(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin-csrf@example.com', true);
        $response = $this->createApp()->handle($this->postRequest($slug, $adminId, [], withCsrf: false));

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // 404 for a foreign/unknown board
    // -------------------------------------------------------------------------

    public function test_get_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin-404@example.com', true);
        $response = $this->createApp()->handle($this->getRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_post_unknown_board_returns_404(): void
    {
        $adminId  = $this->seedUser('admin-404-post@example.com', true);
        $response = $this->createApp()->handle($this->postRequest('does-not-exist', $adminId));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Response shape (Community default: never acknowledged, POST is a no-op)
    // -------------------------------------------------------------------------

    public function test_get_as_admin_returns_unacknowledged_status(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin@example.com', true);
        $response = $this->createApp()->handle($this->getRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['acknowledged'] ?? null);
        self::assertArrayHasKey('acknowledged_at', $data);
        self::assertNull($data['acknowledged_at']);
        self::assertArrayHasKey('acknowledged_by_user_id', $data);
        self::assertNull($data['acknowledged_by_user_id']);
    }

    public function test_post_as_admin_succeeds_without_persisting_in_community_edition(): void
    {
        $slug     = $this->seedBoard();
        $adminId  = $this->seedUser('admin-post@example.com', true);
        $response = $this->createApp()->handle($this->postRequest($slug, $adminId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertFalse($data['acknowledged'] ?? null);
    }
}
