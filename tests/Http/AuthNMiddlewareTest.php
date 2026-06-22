<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Persistence\UserRepository;
use Votepit\Tests\Support\CapturingHandler;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * AuthN-Hydratation (Sprint 2 — Issue 03 + Issue 04).
 *
 * Beweist beobachtbar: bei vorhandener uid lädt die Middleware den User
 * (ATTR_USER = Datensatz) bzw. verwirft die Session (ATTR_USER = null), wenn
 * der Datensatz fehlt oder token_version nicht übereinstimmt.
 * Getestet über die öffentliche process()-Seam.
 */
final class AuthNMiddlewareTest extends IntegrationTestCase
{
    public function test_hydrates_user_for_existing_uid(): void
    {
        $this->conn->insert('users', [
            'email'      => 'hydrate@example.com',
            'is_admin'   => 1,
            'is_blocked' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->conn->lastInsertId();

        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $id)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0]);

        $mw->process($request, $handler);

        self::assertIsArray($handler->seenUser);
        self::assertSame('hydrate@example.com', $handler->seenUser['email']);
        self::assertSame(1, (int) $handler->seenUser['is_admin']);
        self::assertSame(0, (int) $handler->seenUser['token_version']);
    }

    public function test_discards_session_when_user_missing(): void
    {
        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, 12345) // existiert nicht
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => 12345, 'v' => 0]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser);
    }

    public function test_user_null_without_session(): void
    {
        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/'); // keine uid

        $mw->process($request, $handler);

        self::assertNull($handler->seenUser);
    }

    public function test_discards_session_on_token_version_mismatch(): void
    {
        $this->conn->insert('users', [
            'email'         => 'revoked@example.com',
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 1, // DB hat v=1 (nach Logout gebumpt)
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->conn->lastInsertId();

        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        // Altes Cookie: v=0 — stimmt nicht mehr mit DB token_version=1 überein.
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $id)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser); // revoziert
    }
}
