<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /account/password-reset — the logged-in
 * self-service "send me a reset link" affordance in Profile (Punkt 5b).
 *
 * Since ADR 0002 stores email only as a one-way HMAC, even an authenticated
 * user's own plaintext address isn't retrievable from storage — so the user
 * re-enters it as a confirmation step (same convention InviteAction already
 * uses for existing-account invites). A mismatch (fat-fingered address, or
 * an attempt to target someone else's mailbox from an authenticated but
 * unrelated session) is rejected rather than silently mailing the typed
 * address, since this route funnels into a real credential-replacement link.
 */
final class AccountPasswordResetActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function post(string $email, ?int $actingUserId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        $cookies = [$csrf->cookieName() => $signed];
        if ($actingUserId !== null) {
            $cookies['votepit_sess'] = $this->sessionCookie($actingUserId);
        }

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/account/password-reset')
            ->withCookieParams($cookies)
            ->withParsedBody(['_csrf' => $token, 'email' => $email]);
    }

    public function test_confirming_own_email_sends_a_reset_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->insertUser('self-reset@example.com');

        $response = $app->handle($this->post('self-reset@example.com', $userId));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertCount(1, $mailer->sent);
        self::assertSame('self-reset@example.com', $mailer->sent[0]['to']);

        $tokenCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM login_tokens WHERE purpose = 'password_reset' AND user_id = :uid",
            ['uid' => $userId],
        );
        self::assertSame(1, $tokenCount);
    }

    public function test_email_is_case_insensitively_matched(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->insertUser('caseself@example.com');

        $response = $app->handle($this->post('CaseSelf@Example.com', $userId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
    }

    public function test_mismatched_email_returns_422_and_sends_no_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer);
        $userId = $this->insertUser('realaddress@example.com');

        $response = $app->handle($this->post('someone-else@example.com', $userId));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('email_mismatch', $data['error']['key']);
        self::assertCount(0, $mailer->sent);
    }

    public function test_anon_request_returns_401(): void
    {
        $app = $this->createApp();

        $response = $app->handle($this->post('anyone@example.com', null));

        self::assertSame(401, $response->getStatusCode());
    }
}
