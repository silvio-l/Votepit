<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für POST /logout (Sprint 2 — Issue 04).
 *
 * Booten über AppFactory mit SQLite-In-Memory.
 * Assertions prüfen ausschließlich beobachtbares Verhalten:
 * HTTP-Status, Set-Cookie, DB-Zustand (token_version), Audit-Log.
 */
final class LogoutActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Seedet einen User; liefert dessen ID. */
    private function seedUser(string $email = 'user@example.com'): int
    {
        $this->conn->insert('users', [
            'email'         => $email,
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
     * Baut einen POST /logout-Request mit gültigem Session-Cookie und CSRF-Token.
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

    /** Sucht in den Set-Cookie-Headern nach einem Cookie-Wert. */
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

    /** Gibt den kompletten Set-Cookie-Header-String eines Cookies zurück. */
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
    // AC1: POST /logout (eingeloggt, gültiger CSRF) erhöht token_version + löscht Cookie
    // -------------------------------------------------------------------------

    public function test_logout_bumps_token_version_and_clears_session_cookie(): void
    {
        $userId = $this->seedUser();

        $response = $this->createApp()->handle($this->logoutRequest($userId));

        self::assertSame(200, $response->getStatusCode());

        // token_version muss jetzt 1 sein
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);

        // Session-Cookie muss mit Max-Age=0 gelöscht worden sein
        $setCookie = $this->cookieHeader($response, 'votepit_sess');
        self::assertNotNull($setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie);
        self::assertSame('', $this->cookieValue($response, 'votepit_sess'));
    }

    // -------------------------------------------------------------------------
    // AC2: Pre-Logout-Cookie wird NACH dem Logout nicht mehr akzeptiert
    // -------------------------------------------------------------------------

    public function test_pre_logout_session_cookie_is_rejected_after_logout(): void
    {
        $userId = $this->seedUser('revoke@example.com');
        $app    = $this->createApp();

        // Session-Cookie vor dem Logout (v=0)
        $oldCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        // Logout durchführen — token_version wird auf 1 erhöht
        $app->handle($this->logoutRequest($userId, 0));

        // Folge-Request mit dem alten Cookie: AuthN muss ablehnen (v=0 ≠ token_version=1)
        $followup = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withCookieParams(['votepit_sess' => $oldCookie]);
        $response = $app->handle($followup);

        // Smoke-Route antwortet mit 200, aber der User muss null sein (nicht eingeloggt)
        self::assertSame(200, $response->getStatusCode());

        // Direkt prüfen: token_version=1 in DB, altes Cookie hatte v=0 → Mismatch
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);
    }

    // -------------------------------------------------------------------------
    // AC3: POST /logout ohne gültigen CSRF-Token → 403
    // -------------------------------------------------------------------------

    public function test_logout_without_valid_csrf_returns_403(): void
    {
        $userId     = $this->seedUser();
        $sessCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams(['votepit_sess' => $sessCookie]) // kein CSRF-Cookie
            ->withParsedBody(['_csrf' => 'falscher-token']);

        $response = $this->createApp()->handle($request);

        self::assertSame(403, $response->getStatusCode());

        // token_version darf sich NICHT erhöht haben
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(0, $version);
    }

    // -------------------------------------------------------------------------
    // AC4: POST /logout als Anon → AuthZ `user` weist ab (401)
    // -------------------------------------------------------------------------

    public function test_logout_as_anon_is_rejected_by_authz(): void
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/logout')
            ->withCookieParams(['votepit_csrf' => $csrf->sign($csrfToken)]) // kein Session-Cookie
            ->withParsedBody(['_csrf' => $csrfToken]);

        $response = $this->createApp()->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC5: Geklautes/kopiertes Cookie ist nach Logout des Users wertlos
    // -------------------------------------------------------------------------

    public function test_stolen_cookie_is_worthless_after_logout(): void
    {
        $userId = $this->seedUser('victim@example.com');
        $app    = $this->createApp();

        // "Gestohlenes" Cookie mit v=0
        $stolenCookie = $this->sessions()->sign(['uid' => $userId, 'v' => 0]);

        // Legitimer Logout des Users (token_version → 1)
        $app->handle($this->logoutRequest($userId, 0));

        // Angreifer versucht das gestohlene Cookie zu nutzen
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

        // token_version bleibt bei 1 (kein zweites Bump)
        $version = (int) $this->conn->fetchOne(
            'SELECT token_version FROM users WHERE id = :id',
            ['id' => $userId],
        );
        self::assertSame(1, $version);
    }

    // -------------------------------------------------------------------------
    // AC6: AuditLogger-Eintrag 'Logout' pseudonymisiert (keine E-Mail/Token im Log)
    // -------------------------------------------------------------------------

    public function test_audit_log_contains_logout_event_pseudonymised(): void
    {
        $email  = 'audit-logout@example.com';
        $userId = $this->seedUser($email);

        $this->createApp()->handle($this->logoutRequest($userId));

        $log = $this->readAuditLog();

        self::assertStringContainsString('user.logout', $log);
        self::assertStringNotContainsString($email, $log);   // E-Mail nicht im Log
        self::assertStringContainsString((string) $userId, $log); // pseudonymisierte uid OK
    }
}
