<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Rate-limit integration tests for POST /login.
 *
 * Overrides testConfig() with low thresholds, so the limits are
 * deterministically reachable in a few requests:
 *   - magiclink:email → 1 per window
 *   - magiclink:ip    → 2 per window
 *
 * Uses SQLite in-memory via IntegrationTestCase (no MySQL process).
 * RateLimiter uses the SQLite branch (INSERT OR IGNORE + UPDATE).
 */
final class RateLimitLoginTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function emailHmac(string $email): string
    {
        return (new IdentityHasher(self::identityServerKey()))->hash($email);
    }

    /**
     * Builds a POST request with a valid CSRF cookie and field.
     * REMOTE_ADDR can be set for IP-limit tests.
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
     * Low limits: email = 1, IP = 2 (testable thresholds).
     */
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'            => 'dev',
            'app_url'        => 'http://localhost:8000',
            'app_key'        => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
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
    // AC5: below the limit, the login flow runs unchanged
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

        // User + token were created
        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email_hmac = :email_hmac',
            ['email_hmac' => $this->emailHmac($email)],
        );
        self::assertIsArray($user);

        $tokenCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertSame(1, $tokenCount);
    }

    // -------------------------------------------------------------------------
    // AC1: per-email threshold → 429, no further token/mail sending
    // -------------------------------------------------------------------------

    public function test_exceeding_email_limit_returns_429_and_stops_further_send(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $email  = 'target@example.com';

        // 1st request: under the limit (limit=1 → count=1, 1 <= 1 → allowed)
        $first = $app->handle($this->postLogin($email));
        self::assertSame(200, $first->getStatusCode());

        // 2nd request, same email: over the limit (count=2, 2 > 1 → 429)
        $second = $app->handle($this->postLogin($email));
        self::assertSame(429, $second->getStatusCode());

        // No further mail sent after the limit (still exactly 1, not 2)
        self::assertCount(1, $mailer->sent);

        // No new token entry after the limit
        $user = $this->conn->fetchAssociative(
            'SELECT id FROM users WHERE email_hmac = :email_hmac',
            ['email_hmac' => $this->emailHmac($email)],
        );
        self::assertIsArray($user);
        $tokenCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM login_tokens WHERE user_id = :id',
            ['id' => $user['id']],
        );
        self::assertSame(1, $tokenCount);
    }

    // -------------------------------------------------------------------------
    // AC2: per-IP threshold → 429 (enumeration across many addresses from one IP)
    // -------------------------------------------------------------------------

    public function test_exceeding_ip_limit_returns_429(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $ip     = '10.0.0.99';

        // IP limit = 2: two requests with different addresses go through
        $first  = $app->handle($this->postLogin('a@example.com', $ip));
        $second = $app->handle($this->postLogin('b@example.com', $ip));
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());

        // 3rd request (new email, same IP) → IP threshold exceeded → 429
        $third = $app->handle($this->postLogin('c@example.com', $ip));
        self::assertSame(429, $third->getStatusCode());

        // Only 2 mails sent (c@example.com is not reached)
        self::assertCount(2, $mailer->sent);
    }
}
