<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Security\DigestUnsubscribeToken;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * POST /digest/unsubscribe — the weekly digest's one-click unsubscribe
 * (competitor-parity issue 14). No session: the signed token is the
 * capability, and it can only ever switch that one user's digest OFF.
 */
final class DigestUnsubscribeActionTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $body */
    private function anonPost(array $body): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/digest/unsubscribe')
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody($body);
    }

    private function digestFlag(int $userId): mixed
    {
        return $this->conn->fetchOne('SELECT notify_weekly_digest_email FROM users WHERE id = :id', ['id' => $userId]);
    }

    public function test_valid_token_switches_the_digest_off_without_a_session(): void
    {
        $userId = $this->insertUser('unsub-ok@example.com', ['notify_weekly_digest_email' => 1]);
        $token  = (new DigestUnsubscribeToken(str_repeat('a', 64)))->sign($userId);

        $response = $this->createApp()->handle($this->anonPost(['token' => $token]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode((string) $response->getBody(), true));
        self::assertSame(0, (int) $this->digestFlag($userId));
        self::assertStringContainsString('user.digest_unsubscribed', $this->readAuditLog());
    }

    public function test_forged_or_missing_token_is_rejected_and_changes_nothing(): void
    {
        $userId = $this->insertUser('unsub-forged@example.com');
        $forged = (new DigestUnsubscribeToken(str_repeat('z', 64)))->sign($userId);

        foreach ([['token' => $forged], ['token' => 'garbage'], []] as $body) {
            $response = $this->createApp()->handle($this->anonPost($body));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_token', json_decode((string) $response->getBody(), true)['error']['key']);
        }

        self::assertNull($this->digestFlag($userId));
    }

    public function test_token_only_affects_its_own_user(): void
    {
        $victim   = $this->insertUser('unsub-victim@example.com', ['notify_weekly_digest_email' => 1]);
        $attacker = $this->insertUser('unsub-attacker@example.com', ['notify_weekly_digest_email' => 1]);
        $token    = (new DigestUnsubscribeToken(str_repeat('a', 64)))->sign($attacker);

        $this->createApp()->handle($this->anonPost(['token' => $token]));

        self::assertSame(1, (int) $this->digestFlag($victim));
        self::assertSame(0, (int) $this->digestFlag($attacker));
    }

    public function test_requires_csrf_like_every_other_mutating_route(): void
    {
        $userId = $this->insertUser('unsub-csrf@example.com', ['notify_weekly_digest_email' => 1]);
        $token  = (new DigestUnsubscribeToken(str_repeat('a', 64)))->sign($userId);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/digest/unsubscribe')->withParsedBody(['token' => $token]),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, (int) $this->digestFlag($userId));
    }
}
