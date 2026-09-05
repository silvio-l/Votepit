<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\SessionMiddleware;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
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
            'public_id'  => PublicIdGenerator::generate(),
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
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0])
            ->withAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES, [['uid' => $id, 'v' => 0]]);

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
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => 12345, 'v' => 0])
            ->withAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES, [['uid' => 12345, 'v' => 0]]);

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
            'public_id'     => PublicIdGenerator::generate(),
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
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0])
            ->withAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES, [['uid' => $id, 'v' => 0]]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser); // revoked
    }

    /**
     * Regression test for the invite-accept "wrong account" bug (2026-09-05):
     * a browser holding a duplicate `votepit_sess` FOR THE SAME LOGIN can
     * present a NEWEST candidate whose session has since been revoked (e.g.
     * that login was superseded) alongside an OLDER duplicate that is still
     * a live, valid session for the SAME user. Falling through to that
     * still-valid candidate — instead of stopping at "newest failed, so
     * anonymous" — is what prevents the request from bouncing to logged-out.
     */
    public function test_falls_through_to_an_older_valid_candidate_for_the_same_user_when_the_newest_is_revoked(): void
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash('same-user@example.com'),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 1, // bumped after re-login — the "newest" candidate below no longer matches
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->conn->lastInsertId();

        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $id)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $id, 'v' => 0])
            ->withAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES, [
                ['uid' => $id, 'v' => 0], // newest duplicate, but stale (DB has v=1)
                ['uid' => $id, 'v' => 1], // older duplicate, still matches current token_version
            ]);

        $mw->process($request, $handler);

        self::assertIsArray($handler->seenUser);
        self::assertSame($id, (int) $handler->seenUser['id']);
    }

    /**
     * Security regression test: a newest candidate that is revoked must NOT
     * cause fall-through to an older candidate belonging to a DIFFERENT uid.
     * Two verified cookies for two different users can legitimately coexist
     * on a shared browser — accepting a cross-uid fallback would mean
     * "log out / reset password" on the newer account silently re-
     * authenticates the request as the older account instead of going
     * anonymous (session-fixation-style account takeover).
     */
    public function test_does_not_fall_through_to_a_valid_candidate_of_a_different_user(): void
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash('revoked-newest@example.com'),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 1, // bumped after logout — the "newest" candidate below no longer matches
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $revokedId = (int) $this->conn->lastInsertId();

        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash('still-valid@example.com'),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $otherUserId = (int) $this->conn->lastInsertId();

        $mw      = new AuthNMiddleware(new UserRepository($this->conn));
        $handler = new CapturingHandler();

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(SessionMiddleware::ATTR_USER_ID, $revokedId)
            ->withAttribute(SessionMiddleware::ATTR_SESSION, ['uid' => $revokedId, 'v' => 0])
            ->withAttribute(SessionMiddleware::ATTR_SESSION_CANDIDATES, [
                ['uid' => $revokedId, 'v' => 0],    // newest, but revoked (DB has v=1)
                ['uid' => $otherUserId, 'v' => 0],  // older duplicate — different user, still valid
            ]);

        $mw->process($request, $handler);

        self::assertTrue($handler->called);
        self::assertNull($handler->seenUser); // fail-secure: anonymous, never a different identity
    }
}
