<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Participant\ParticipantMailCategory;
use Votepit\Persistence\ParticipantMailRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\ParticipantUnsubscribeToken;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * POST /participant-mail/unsubscribe — one-click unsubscribe of the participant
 * mails. No session: the signed token is the capability and can only switch
 * off the categories it names, for the one user it was issued for.
 */
final class ParticipantUnsubscribeActionTest extends IntegrationTestCase
{
    /** @param array<string, mixed> $body */
    private function anonPost(array $body, string $ip = '203.0.113.10'): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/participant-mail/unsubscribe', ['REMOTE_ADDR' => $ip])
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
            ->withHeader('X-CSRF-Token', $token)
            ->withParsedBody($body);
    }

    private function cadence(int $userId, string $category): string
    {
        return (new ParticipantMailRepository($this->conn))->cadenceFor($userId, $category);
    }

    public function test_a_valid_token_switches_only_its_category_off(): void
    {
        $userId = $this->insertUser('pu-ok@example.com');
        $repo   = new ParticipantMailRepository($this->conn);
        $repo->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);
        $repo->setCadence($userId, ParticipantMailCategory::NEW_IDEAS, ParticipantMailCategory::CADENCE_WEEKLY);

        $token    = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $response = $this->createApp(config: $this->config())->handle($this->anonPost(['token' => $token]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ParticipantMailCategory::CADENCE_OFF, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
        self::assertSame(ParticipantMailCategory::CADENCE_WEEKLY, $this->cadence($userId, ParticipantMailCategory::NEW_IDEAS));
    }

    public function test_forged_and_tampered_tokens_are_rejected_and_change_nothing(): void
    {
        $userId = $this->insertUser('pu-forged@example.com');
        $repo   = new ParticipantMailRepository($this->conn);
        $repo->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $valid    = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $forged   = (new ParticipantUnsubscribeToken(str_repeat('z', 64)))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $tampered = $valid . 'x';

        foreach ([$forged, $tampered, 'garbage'] as $i => $token) {
            $response = $this->createApp(config: $this->config())->handle($this->anonPost(['token' => $token], '203.0.113.' . (20 + $i)));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_token', json_decode((string) $response->getBody(), true)['error']['key']);
        }

        self::assertSame(ParticipantMailCategory::CADENCE_DAILY, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
    }

    public function test_a_token_for_one_user_never_changes_another_user(): void
    {
        $victim   = $this->insertUser('pu-victim@example.com');
        $attacker = $this->insertUser('pu-attacker@example.com');
        $repo     = new ParticipantMailRepository($this->conn);
        $repo->setCadence($victim, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $token = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($attacker, ParticipantMailCategory::PARTICIPATED);
        $this->createApp(config: $this->config())->handle($this->anonPost(['token' => $token]));

        self::assertSame(ParticipantMailCategory::CADENCE_DAILY, $this->cadence($victim, ParticipantMailCategory::PARTICIPATED));
    }

    public function test_the_endpoint_is_rate_limited_per_ip(): void
    {
        $userId = $this->insertUser('pu-limit@example.com');
        $token  = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $app    = $this->createApp(config: $this->config());

        // The limit is 2 per window (see config()); the third request from the same IP is refused.
        $app->handle($this->anonPost(['token' => $token], '198.51.100.7'));
        $app->handle($this->anonPost(['token' => $token], '198.51.100.7'));
        $third = $app->handle($this->anonPost(['token' => $token], '198.51.100.7'));

        self::assertSame(429, $third->getStatusCode());
    }

    /**
     * What a mail client sends for an RFC 8058 one-click unsubscribe: the token in
     * the List-Unsubscribe URL's query string, the body is only the marker, and
     * no cookie or CSRF field (the client has neither).
     */
    private function mailClientOneClickPost(string $token, string $ip): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/participant-mail/unsubscribe?token=' . $token, ['REMOTE_ADDR' => $ip])
            ->withParsedBody(['List-Unsubscribe' => 'One-Click']);
    }

    public function test_an_rfc8058_one_click_post_from_a_mail_client_switches_the_category_off(): void
    {
        $userId = $this->insertUser('pu-one-click@example.com');
        (new ParticipantMailRepository($this->conn))->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $token    = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $response = $this->createApp(config: $this->config())->handle($this->mailClientOneClickPost($token, '203.0.113.60'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ParticipantMailCategory::CADENCE_OFF, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
    }

    public function test_a_tampered_token_in_the_query_string_is_rejected_and_changes_nothing(): void
    {
        $userId = $this->insertUser('pu-query-forged@example.com');
        (new ParticipantMailRepository($this->conn))->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $forged   = (new ParticipantUnsubscribeToken(str_repeat('z', 64)))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $response = $this->createApp(config: $this->config())->handle($this->mailClientOneClickPost($forged, '203.0.113.61'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ParticipantMailCategory::CADENCE_DAILY, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
    }

    public function test_a_get_on_the_endpoint_never_unsubscribes(): void
    {
        $userId = $this->insertUser('pu-get@example.com');
        (new ParticipantMailRepository($this->conn))->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        // Link scanners prefetch with GET: the token in the URL must not be enough.
        $token = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $this->createApp(config: $this->config())->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/participant-mail/unsubscribe?token=' . $token, ['REMOTE_ADDR' => '203.0.113.62']),
        );

        self::assertSame(ParticipantMailCategory::CADENCE_DAILY, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
    }

    public function test_a_repeated_post_is_idempotent(): void
    {
        $userId = $this->insertUser('pu-twice@example.com');
        (new ParticipantMailRepository($this->conn))->setCadence($userId, ParticipantMailCategory::PARTICIPATED, ParticipantMailCategory::CADENCE_DAILY);

        $token = (new ParticipantUnsubscribeToken($this->testConfig()->appKey))->signForUser($userId, ParticipantMailCategory::PARTICIPATED);
        $app   = $this->createApp(config: $this->config());

        self::assertSame(200, $app->handle($this->anonPost(['token' => $token], '203.0.113.63'))->getStatusCode());
        self::assertSame(200, $app->handle($this->anonPost(['token' => $token], '203.0.113.63'))->getStatusCode());
        self::assertSame(ParticipantMailCategory::CADENCE_OFF, $this->cadence($userId, ParticipantMailCategory::PARTICIPATED));
    }

    private function config(): Config
    {
        return Config::fromArray($this->testConfigArray([
            'rate_limits' => ['digest:unsubscribe' => ['limit' => 2, 'window' => 3600]],
        ]));
    }
}
