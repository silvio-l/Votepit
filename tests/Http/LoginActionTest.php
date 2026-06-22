<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integrationstests für GET /login + POST /login (Sprint 2 — Issue 02).
 *
 * Booten über AppFactory::create($config, $conn, $mailer, $audit) mit
 * SQLite-In-Memory (IntegrationTestCase) und InMemoryMailer. Kein echter
 * SMTP-Versand, kein MySQL-Prozess.
 *
 * CSRF-Token: Die CsrfService-Instanz wird mit dem gleichen app_key gebaut
 * wie AppFactory intern — so können Tests gültige Cookie+Feld-Paare erzeugen.
 */
final class LoginActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** Erzeugt eine POST-Request mit gültigem CSRF-Cookie und -Feld. */
    private function postLogin(string $email, ?string $returnTo = null): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $body = ['email' => $email, '_csrf' => $token];
        if ($returnTo !== null) {
            $body['r'] = $returnTo;
        }

        return (new ServerRequestFactory())->createServerRequest('POST', '/login')
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody($body);
    }

    // -------------------------------------------------------------------------
    // AC1: GET /login → 200 + Formular mit CSRF-Token
    // -------------------------------------------------------------------------

    public function test_get_login_returns_200_with_form_and_csrf_field(): void
    {
        $app      = $this->createApp();
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/login');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('<form', $body);
        // Hidden CSRF-Feld ist im Template vorhanden (Wert kommt vom Middleware-Attribut)
        self::assertStringContainsString('name="_csrf"', $body);
        // Autoescape: der Token-Wert steht im value-Attribut (kein leeres value)
        self::assertStringContainsString('value=', $body);
    }

    // -------------------------------------------------------------------------
    // AC2: POST /login mit gültiger E-Mail → 1 User + 1 gehashter Token + 1 Mail
    // -------------------------------------------------------------------------

    public function test_post_login_creates_user_token_and_sends_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'new@example.com';

        $response = $app->handle($this->postLogin($email));

        self::assertSame(200, $response->getStatusCode());

        // 1 User in DB
        $user = $this->conn->fetchAssociative(
            'SELECT * FROM users WHERE email = :email',
            ['email' => $email],
        );
        self::assertIsArray($user);
        self::assertSame($email, $user['email']);

        // 1 Token-Datensatz
        $tokens = $this->conn->fetchAllAssociative(
            'SELECT * FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertCount(1, $tokens);
        self::assertNull($tokens[0]['used_at']);

        // Genau 1 Mail
        self::assertCount(1, $mailer->sent);
        self::assertSame($email, $mailer->sent[0]['to']);
    }

    // -------------------------------------------------------------------------
    // AC3: Unbekannte + bekannte Adresse → identische Response (Anti-Enumeration)
    // -------------------------------------------------------------------------

    public function test_unknown_and_known_address_produce_identical_response(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $responseUnknown = $app->handle($this->postLogin('unknown@example.com'));
        $responseKnown   = $app->handle($this->postLogin('unknown@example.com')); // 2. Aufruf = bekannt

        // Beide 200
        self::assertSame(200, $responseUnknown->getStatusCode());
        self::assertSame(200, $responseKnown->getStatusCode());

        // Identischer Body (gleiche neutrale Bestätigungsseite)
        self::assertSame(
            (string) $responseUnknown->getBody(),
            (string) $responseKnown->getBody(),
        );
    }

    // -------------------------------------------------------------------------
    // AC4: Ungültige E-Mail-Syntax → kein Versand, neutrales 200
    // -------------------------------------------------------------------------

    public function test_invalid_email_syntax_sends_no_mail_and_returns_200(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $response = $app->handle($this->postLogin('not-an-email'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);

        // Kein User angelegt
        $count = $this->conn->fetchOne('SELECT COUNT(*) FROM users');
        self::assertSame(0, (int) $count);
    }

    // -------------------------------------------------------------------------
    // AC5: Erneute Anfrage desselben Users → vorherige offene Tokens werden gelöscht
    // -------------------------------------------------------------------------

    public function test_repeated_request_invalidates_previous_open_tokens(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'repeat@example.com';

        // Erste Anfrage
        $app->handle($this->postLogin($email));

        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email],
        );
        self::assertIsArray($user);

        $countAfterFirst = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id AND used_at IS NULL',
            ['id' => $user['id']],
        );
        self::assertSame(1, $countAfterFirst);

        // Zweite Anfrage — muss vorherigen Token löschen
        $app->handle($this->postLogin($email));

        $countAfterSecond = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id AND used_at IS NULL',
            ['id' => $user['id']],
        );
        self::assertSame(1, $countAfterSecond); // immer noch genau 1, nicht 2
    }

    // -------------------------------------------------------------------------
    // AC6: POST ohne gültigen CSRF-Token → 403
    // -------------------------------------------------------------------------

    public function test_post_without_csrf_token_returns_403(): void
    {
        $app      = $this->createApp();
        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/login')
            ->withParsedBody(['email' => 'foo@example.com']);

        $response = $app->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // AC7: Nur Hash in DB; kein Klartext-Token im Log; maskierte E-Mail im Log
    // -------------------------------------------------------------------------

    public function test_db_stores_hash_not_plaintext_and_log_is_clean(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'audit@example.com';

        $app->handle($this->postLogin($email));

        // Klartext-Token aus dem gesendeten Link extrahieren
        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        self::assertStringContainsString('/login/verify?token=', $mail['body']);

        preg_match('/token=([a-f0-9]{64})/', $mail['body'], $m);
        self::assertArrayHasKey(1, $m, 'Kein Token im Mail-Body gefunden');
        $plaintext = $m[1];

        // DB: token_hash = sha256(plaintext), NICHT plaintext selbst
        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email],
        );
        self::assertIsArray($user);

        $tokenRow = $this->conn->fetchAssociative(
            'SELECT token_hash FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertIsArray($tokenRow);

        $expectedHash = hash('sha256', $plaintext);
        self::assertSame($expectedHash, $tokenRow['token_hash']);
        self::assertNotSame($plaintext, $tokenRow['token_hash']); // Hash != Klartext

        // Audit-Log: kein Klartext-Token, nur maskierte E-Mail
        $logContent = $this->readAuditLog();
        self::assertStringNotContainsString($plaintext, $logContent);
        self::assertStringContainsString('magic_link.requested', $logContent);
        // Maskierte E-Mail enthält @ und #-Hash-Suffix (AuditLogger-Format: "a**@e**#abc123")
        self::assertStringNotContainsString($email, $logContent);
        self::assertStringContainsString('@', $logContent);
    }

    // -------------------------------------------------------------------------
    // AC8: Mailer ist injizierbar (InMemoryMailer kein echter SMTP)
    // -------------------------------------------------------------------------

    public function test_mailer_is_injectable_no_real_smtp(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin('inject@example.com'));

        // InMemoryMailer hat die Mail empfangen (kein Netzwerk-Aufruf)
        self::assertCount(1, $mailer->sent);
        self::assertStringContainsString('inject@example.com', $mailer->sent[0]['to']);
    }

    // -------------------------------------------------------------------------
    // Issue 05: Return-To (Open-Redirect-sicheres Deep-Linking)
    // -------------------------------------------------------------------------

    public function test_valid_return_to_is_embedded_in_magic_link(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin('deeplink@example.com', '/some/board/path'));

        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        // Der Link muss den URL-codierten Return-To-Pfad enthalten.
        self::assertStringContainsString('&r=', $mail['body']);
        self::assertStringContainsString(rawurlencode('/some/board/path'), $mail['body']);
    }

    public function test_protocol_relative_return_to_is_not_embedded(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin('evil@example.com', '//evil.com'));

        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        // Ungültiger Return-To darf NICHT im Link erscheinen.
        self::assertStringNotContainsString('evil.com', $mail['body']);
        self::assertStringNotContainsString('&r=', $mail['body']);
    }

    public function test_absolute_url_return_to_is_not_embedded(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin('abs@example.com', 'https://evil.com'));

        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        self::assertStringNotContainsString('evil.com', $mail['body']);
        self::assertStringNotContainsString('&r=', $mail['body']);
    }

    public function test_missing_return_to_produces_no_r_param_in_link(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);

        $app->handle($this->postLogin('nort@example.com'));

        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        self::assertStringNotContainsString('&r=', $mail['body']);
    }

    public function test_get_login_with_valid_r_renders_hidden_field(): void
    {
        $app     = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login')
            ->withQueryParams(['r' => '/some/board/path']);

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('name="r"', $body);
        self::assertStringContainsString('/some/board/path', $body);
    }

    public function test_get_login_with_invalid_r_does_not_render_hidden_field(): void
    {
        $app     = $this->createApp();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/login')
            ->withQueryParams(['r' => '//evil.com']);

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        // Kein hidden r-Feld mit bösartigem Wert
        self::assertStringNotContainsString('evil.com', $body);
    }
}
