<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /logout.
 *
 * Boots via AppFactory with SQLite in-memory.
 * Assertions check only observable behavior:
 * HTTP status, Set-Cookie, DB state (token_version), audit log.
 */
final class LogoutActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seeds a user; returns its ID. */
    private function seedUser(string $email = 'user@example.com'): int
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::identityServerKey()))->hash($email),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->conn->lastInsertId();
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    /**
     * Builds a POST /logout request with a valid session cookie and CSRF token.
     *
     * @param array<string, string> $extraCookies
     */
    private function logoutRequest(int $userId, int $tokenVersion = 0, array $extraCookies = []): ServerRequestInterface
    {
        $csrf       = $this->csrf();
        $csrfToken  = $csrf->generate();
        $sessCookie = $this->sessions()->sign(['uid' => $userId, 'v' => $tokenVersion]);

        return (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams(array_merge(
                ['votepit_sess' => $sessCookie, 'votepit_csrf' => $csrf->sign($csrfToken)],
                $extraCookies,
            ))
            ->withParsedBody(['_csrf' => $csrfToken]);
    }

    /** Searches the Set-Cookie headers for a cookie value. */
    private function cookieValue(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                $first = explode(';', $header, 2)[0];
                return substr($first, strlen($name) + 1);
            }
        }
        return null;
    }

    /** Returns the full Set-Cookie header string of a cookie. */
    private function cookieHeader(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                return $header;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // AC1: POST /logout (logged in, valid CSRF) bumps token_version + clears the cookie
    // -------------------------------------------------------------------------

    public function test_logout_bumps_token_version_and_clears_session_cookie(): void
    {
        $userId = $this->seedUser();

        $response = $this->createApp()->handle($this->logoutRequest($userId));

        self::assertSame(200, $response->getStatusCode());

        // token_version must now be 1
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);

        // Session cookie must have been cleared with Max-Age=0
        $setCookie = $this->cookieHeader($response, 'votepit_sess');
        self::assertNotNull($setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie);
        self::assertSame('', $this->cookieValue($response, 'votepit_sess'));
    }

    // -------------------------------------------------------------------------
    // AC2: pre-logout cookie is no longer accepted AFTER logout
    // -------------------------------------------------------------------------

    public function test_pre_logout_session_cookie_is_rejected_after_logout(): void
    {
        $userId = $this->seedUser('revoke@example.com');
        $app    = $this->createApp();

        // Session cookie before the logout (v=0)
        $oldCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        // Perform the logout — token_version gets bumped to 1
        $app->handle($this->logoutRequest($userId, 0));

        // Follow-up request with the old cookie: AuthN must reject (v=0 ≠ token_version=1)
        $followup = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $oldCookie]);
        $response = $app->handle($followup);

        // Smoke route responds with 200, but the user must be null (not logged in)
        self::assertSame(200, $response->getStatusCode());

        // Verify directly: token_version=1 in DB, old cookie had v=0 → mismatch
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);
    }

    // -------------------------------------------------------------------------
    // AC3: POST /logout without a valid CSRF token → 403
    // -------------------------------------------------------------------------

    public function test_logout_without_valid_csrf_returns_403(): void
    {
        $userId     = $this->seedUser();
        $sessCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams(['votepit_sess' => $sessCookie]) // no CSRF cookie
            ->withParsedBody(['_csrf' => 'wrong-token']);

        $response = $this->createApp()->handle($request);

        self::assertSame(403, $response->getStatusCode());

        // token_version must NOT have increased
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $version);
    }

    // -------------------------------------------------------------------------
    // AC4: POST /logout as anon → AuthZ `user` rejects (401)
    // -------------------------------------------------------------------------

    public function test_logout_as_anon_is_rejected_by_authz(): void
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams(['votepit_csrf' => $csrf->sign($csrfToken)]) // no session cookie
            ->withParsedBody(['_csrf' => $csrfToken]);

        $response = $this->createApp()->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5: a stolen/copied cookie is worthless after the user's logout
    // -------------------------------------------------------------------------

    public function test_stolen_cookie_is_worthless_after_logout(): void
    {
        $userId = $this->seedUser('victim@example.com');
        $app    = $this->createApp();

        // "Stolen" cookie with v=0
        $stolenCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        // Legitimate logout of the user (token_version → 1)
        $app->handle($this->logoutRequest($userId, 0));

        // Attacker tries to use the stolen cookie
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $attackRequest = (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams([
                'votepit_sess' => $stolenCookie,
                'votepit_csrf' => $csrf->sign($csrfToken),
            ])
            ->withParsedBody(['_csrf' => $csrfToken]);

        $attackResponse = $app->handle($attackRequest);

        // AuthZ rejects: v=0 ≠ token_version=1 → user=null → 401
        self::assertSame(401, $attackResponse->getStatusCode());

        // token_version stays at 1 (no second bump)
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);
    }

    // -------------------------------------------------------------------------
    // AC6: AuditLogger entry 'Logout' is pseudonymized (no email/token in the log)
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_logout_event_pseudonymised(): void
    {
        $email  = 'audit-logout@example.com';
        $userId = $this->seedUser($email);

        $this->createApp()->handle($this->logoutRequest($userId));

        $log = $this->readAuditLog();

        self::assertStringContainsString('user.logout', $log);
        self::assertStringNotContainsString($email, $log);   // email not in the log
        self::assertStringContainsString((string) $userId, $log); // pseudonymized uid OK
    }
}
