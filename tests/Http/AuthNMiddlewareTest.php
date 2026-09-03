<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Tests\Support\CapturingHandler;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * AuthN hydration.
 *
 * Proves observably: when a uid is present, the middleware loads the user
 * (ATTR_USER = record) or discards the session (ATTR_USER = null) when
 * the record is missing or token_version doesn't match.
 * Tested through the public process() seam.
 */
final class AuthNMiddlewareTest extends IntegrationTestCase
{
    public function test_hydrates_user_for_existing_uid(): void
    {
        $emailHmac = (new IdentityHasher(self::identityServerKey()))->hash('hydrate@example.com');
        $this->conn->insert('users', [
            'email_hmac' => $emailHmac,
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
        self::assertSame($emailHmac, $handler->seenUser['email_hmac']);
        self::assertSame(1, (int) $handler->seenUser['is_admin']);
        self::assertSame(0, (int) $handler->seenUser['token_version']);
    }

    public function test_discards_session_when_user_missing(): void
    {
        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, 12345) // does not exist
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => 12345, 'v' => 0]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser);
    }

    public function test_user_null_without_session(): void
    {
        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/'); // no uid

        $mw->process($request, $handler);

        self::assertNull($handler->seenUser);
    }

    public function test_discards_session_on_token_version_mismatch(): void
    {
        $this->conn->insert('users', [
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash('revoked@example.com'),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 1, // DB has v=1 (bumped after logout)
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->conn->lastInsertId();

        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        // Old cookie: v=0 — no longer matches DB token_version=1.
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $id)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser); // revoked
    }
}
