<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST /operator/users/password-reset — Operator/
 * Support-triggered password reset for ANY platform user (Punkt 5d).
 *
 * AuthZ: AuthZMiddleware::support() — is_support OR is_operator, both tiers
 * may use it (support staff handle this day-to-day; operator is the
 * superset role). Like the account-admin variant, the target is resolved by
 * re-typed email (ADR 0002: no plaintext email is ever stored) — unlike
 * MemberAction::passwordReset(), there is no account-membership scoping
 * here, since this tier is explicitly ABOVE account-scoping (any tenant's
 * user is a valid target).
 */
final class OperatorUserActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    private function post(string $email, int $actorId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/operator/users/password-reset')
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($actorId),
                $csrf->cookieName() => $signed,
            ])
            ->withParsedBody(['_csrf' => $token, 'email' => $email]);
    }

    private function operator(): int
    {
        return $this->insertUser('operator-pwreset@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function support(): int
    {
        return $this->insertUser('support-pwreset@example.com', [
            'is_support'      => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_operator_triggers_a_password_reset_for_any_user(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->createApp($mailer);
        $operatorId = $this->operator();
        $accountB   = $this->insertAccount(['slug' => 'acct-operator-pwreset', 'name' => 'Foreign Account']);
        $targetId   = $this->insertUser('foreign-user-pwreset@example.com');
        $this->insertAccountMember($accountB, $targetId, 'moderator');

        $response = $app->handle($this->post('foreign-user-pwreset@example.com', $operatorId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
        self::assertSame('foreign-user-pwreset@example.com', $mailer->sent[0]['to']);
    }

    public function test_support_can_also_trigger_a_password_reset(): void
    {
        $mailer   = new InMemoryMailer();
        $app      = $this->createApp($mailer);
        $supportId = $this->support();
        $this->insertUser('another-user-pwreset@example.com');

        $response = $app->handle($this->post('another-user-pwreset@example.com', $supportId));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $mailer->sent);
    }

    public function test_regular_user_cannot_trigger_a_password_reset_returns_403(): void
    {
        $mailer  = new InMemoryMailer();
        $app     = $this->createApp($mailer);
        $userId  = $this->insertUser('regular-pwreset@example.com');
        $this->insertUser('another-target-pwreset@example.com');

        $response = $app->handle($this->post('another-target-pwreset@example.com', $userId));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_unknown_email_returns_404(): void
    {
        $mailer     = new InMemoryMailer();
        $app        = $this->createApp($mailer);
        $operatorId = $this->operator();

        $response = $app->handle($this->post('ghost-pwreset@example.com', $operatorId));

        self::assertSame(404, $response->getStatusCode());
        self::assertCount(0, $mailer->sent);
    }

    public function test_anon_request_returns_401(): void
    {
        $app    = $this->createApp();
        $csrf   = $this->csrf();
        $token  = $csrf->generate();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/operator/users/password-reset')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withParsedBody(['_csrf' => $token, 'email' => 'anyone@example.com']);

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }
}
