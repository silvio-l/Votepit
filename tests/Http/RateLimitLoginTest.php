<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-Limit-Integrationstests für POST /login (Sprint 2 — Issue 06).
 *
 * Overrides testConfig() mit niedrigen Schwellwerten, damit die Limits
 * deterministisch in wenigen Requests erreichbar sind:
 *   - magiclink:email → 1 pro Fenster
 *   - magiclink:ip    → 2 pro Fenster
 *
 * Verwendet SQLite-In-Memory via IntegrationTestCase (kein MySQL-Prozess).
 * RateLimiter verwendet den SQLite-Zweig (INSERT OR IGNORE + UPDATE).
 */
final class RateLimitLoginTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * Erzeugt eine POST-Request mit gültigem CSRF-Cookie und -Feld.
     * REMOTE_ADDR kann für IP-Limit-Tests gesetzt werden.
     */
    private function postLogin(string $email, string $remoteAddr = '127.0.0.1'): \Psr\Http\Message\ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())->createServerRequest('POST', '/login', ['REMOTE_ADDR' => $remoteAddr])
            ->withCookieParams([$csrf->cookieName() => $signed])
            ->withParsedBody(['email' => $email, '_csrf' => $token]);
    }

    /**
     * Niedrige Limits: email = 1, IP = 2 (Issue 06 — testbare Schwellwerte).
     */
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => str_repeat('a', 64),
            'db'             => ['name' => ':memory:'],
            'smtp'           => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl' => 900,
            'rate_limits'    => [
                'magiclink:email' => ['limit' => 1, 'window' => 3600],
                'magiclink:ip'    => ['limit' => 2, 'window' => 3600],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // AC5: Unterhalb des Limits läuft der Issue-02-Flow unverändert
    // -------------------------------------------------------------------------

    public function test_under_limit_full_issue02_flow_works(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'flow@example.com';

        $response = $app->handle($this->postLogin($email));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
        self::assertSame($email, $mailer->sent[0]['to']);

        // User + Token wurden angelegt
        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email],
        );
        self::assertIsArray($user);

        $tokenCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertSame(1, $tokenCount);
    }

    // -------------------------------------------------------------------------
    // AC1: Per-E-Mail-Schwelle → 429, kein weiterer Token/Mailversand
    // -------------------------------------------------------------------------

    public function test_exceeding_email_limit_returns_429_and_stops_further_send(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'target@example.com';

        // 1. Anfrage: unter Limit (limit=1 → count=1, 1 <= 1 → erlaubt)
        $first = $app->handle($this->postLogin($email));
        self::assertSame(200, $first->getStatusCode());

        // 2. Anfrage gleiche E-Mail: über Limit (count=2, 2 > 1 → 429)
        $second = $app->handle($this->postLogin($email));
        self::assertSame(429, $second->getStatusCode());

        // Kein weiterer Mailversand nach dem Limit (immer noch exakt 1, nicht 2)
        self::assertCount(1, $mailer->sent);

        // Kein neuer Token-Eintrag nach dem Limit
        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email = :email',
            ['email' => $email],
        );
        self::assertIsArray($user);
        $tokenCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertSame(1, $tokenCount);
    }

    // -------------------------------------------------------------------------
    // AC2: Per-IP-Schwelle → 429 (Enumeration über viele Adressen von einer IP)
    // -------------------------------------------------------------------------

    public function test_exceeding_ip_limit_returns_429(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $ip     = '10.0.0.99';

        // IP-Limit = 2: zwei Anfragen mit verschiedenen Adressen durch
        $first  = $app->handle($this->postLogin('a@example.com', $ip));
        $second = $app->handle($this->postLogin('b@example.com', $ip));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());

        // 3. Anfrage (neue E-Mail, gleiche IP) → IP-Schwelle überschritten → 429
        $third = $app->handle($this->postLogin('c@example.com', $ip));
        self::assertSame(429, $third->getStatusCode());

        // Nur 2 Mails versendet (c@example.com wird nicht erreicht)
        self::assertCount(2, $mailer->sent);
    }
}
