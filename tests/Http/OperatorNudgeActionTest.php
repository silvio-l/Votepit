<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Nudge\OperatorNudgeKind;
use Votepit\Security\CsrfService;
use Votepit\Security\OperatorNudgeUnsubscribeToken;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Operator nudge switches and one-click unsubscribe:
 *   GET    /account/operator-nudges          (own switches)
 *   PUT    /account/operator-nudges          (own switches, own row only)
 *   POST   /operator-nudges/unsubscribe      (anon, signed token, one kind OFF)
 */
final class OperatorNudgeActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, array $cookies = [], ?array $body = null): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withCookieParams([$csrf->cookieName() => $csrf->sign($token), ...$cookies])
            ->withHeader('X-CSRF-Token', $token);

        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, bool> */
    private function prefs(int $userId): array
    {
        return (new \Votepit\Persistence\OperatorNudgeRepository($this->conn))->preferences($userId);
    }

    public function test_switches_default_to_on_for_a_fresh_user(): void
    {
        $userId = $this->insertUser('nudge-defaults@example.com');

        $response = $this->createApp()->handle(
            $this->request('GET', '/account/operator-nudges', ['votepit_sess' => $this->sessionCookie($userId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['onboarding' => true, 'quiet_board' => true, 'plan_limit' => true],
            $this->json($response),
        );
    }

    public function test_put_changes_only_the_callers_own_switches(): void
    {
        $userId  = $this->insertUser('nudge-put@example.com');
        $otherId = $this->insertUser('nudge-other@example.com');

        $response = $this->createApp()->handle(
            $this->request('PUT', '/account/operator-nudges', ['votepit_sess' => $this->sessionCookie($userId)], [
                'quiet_board' => false,
                // A user id in the body must never address another user's row.
                'user_id'     => $otherId,
                'onboarding'  => false,
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['onboarding' => false, 'quiet_board' => false, 'plan_limit' => true], $this->prefs($userId));
        self::assertSame(['onboarding' => true, 'quiet_board' => true, 'plan_limit' => true], $this->prefs($otherId));
    }

    public function test_put_rejects_a_non_boolean_switch_without_changing_anything(): void
    {
        $userId = $this->insertUser('nudge-422@example.com');

        $response = $this->createApp()->handle(
            $this->request('PUT', '/account/operator-nudges', ['votepit_sess' => $this->sessionCookie($userId)], [
                'onboarding' => 'off',
            ]),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_preference', $this->json($response)['error']['key'] ?? null);
        self::assertTrue($this->prefs($userId)['onboarding']);
    }

    public function test_one_click_unsubscribe_switches_only_its_own_kind_off(): void
    {
        $userId = $this->insertUser('nudge-unsub@example.com');
        $token  = (new OperatorNudgeUnsubscribeToken(str_repeat('a', 64)))->sign($userId, OperatorNudgeKind::QuietBoard);

        $response = $this->createApp()->handle($this->request('POST', '/operator-nudges/unsubscribe', [], ['token' => $token]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'kind' => 'quiet_board'], $this->json($response));
        self::assertSame(['onboarding' => true, 'quiet_board' => false, 'plan_limit' => true], $this->prefs($userId));
    }

    public function test_forged_or_invalid_unsubscribe_token_gets_one_constant_400(): void
    {
        $userId = $this->insertUser('nudge-forged@example.com');
        $token  = (new OperatorNudgeUnsubscribeToken(str_repeat('b', 64)))->sign($userId, OperatorNudgeKind::Onboarding);

        foreach ([$token, 'garbage', ''] as $bad) {
            $response = $this->createApp()->handle($this->request('POST', '/operator-nudges/unsubscribe', [], ['token' => $bad]));

            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_token', $this->json($response)['error']['key'] ?? null);
        }
        self::assertSame(['onboarding' => true, 'quiet_board' => true, 'plan_limit' => true], $this->prefs($userId));
    }

    public function test_get_is_not_an_unsubscribe(): void
    {
        $userId = $this->insertUser('nudge-get@example.com');
        $token  = (new OperatorNudgeUnsubscribeToken(str_repeat('a', 64)))->sign($userId, OperatorNudgeKind::Onboarding);

        $this->createApp()->handle($this->request('GET', '/operator-nudges/unsubscribe?token=' . $token));

        self::assertTrue($this->prefs($userId)['onboarding']);
    }

    public function test_a_guest_cannot_read_or_change_the_switches(): void
    {
        $get = $this->createApp()->handle($this->request('GET', '/account/operator-nudges'));
        $put = $this->createApp()->handle($this->request('PUT', '/account/operator-nudges', [], ['onboarding' => false]));

        self::assertSame(401, $get->getStatusCode());
        self::assertSame(401, $put->getStatusCode());
    }

    public function test_the_one_click_unsubscribe_is_rate_limited_per_ip(): void
    {
        $userId = $this->insertUser('nudge-limit@example.com');
        $token  = (new OperatorNudgeUnsubscribeToken(str_repeat('a', 64)))->sign($userId, OperatorNudgeKind::Onboarding);
        $app    = $this->createApp(config: \Votepit\Config::fromArray($this->testConfigArray([
            'rate_limits' => ['operator-nudge:unsubscribe' => ['limit' => 2, 'window' => 3600]],
        ])));

        $post = function () use ($token): ServerRequestInterface {
            $csrf      = $this->csrf();
            $csrfToken = $csrf->generate();

            return (new ServerRequestFactory())
                ->createServerRequest('POST', '/operator-nudges/unsubscribe', ['REMOTE_ADDR' => '198.51.100.90'])
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($csrfToken)])
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->withParsedBody(['token' => $token]);
        };

        self::assertSame(200, $app->handle($post())->getStatusCode());
        self::assertSame(200, $app->handle($post())->getStatusCode());
        self::assertSame(429, $app->handle($post())->getStatusCode());
    }

    public function test_state_changing_nudge_routes_without_csrf_change_nothing(): void
    {
        // Unlike the participant one-click (exempt: a mail client carries no cookie),
        // these stay CSRF-protected: the SPA page posts with its token.
        $userId = $this->insertUser('nudge-csrf@example.com');
        $token  = (new OperatorNudgeUnsubscribeToken(str_repeat('a', 64)))->sign($userId, OperatorNudgeKind::Onboarding);

        $unsubscribe = (new ServerRequestFactory())->createServerRequest('POST', '/operator-nudges/unsubscribe')->withParsedBody(['token' => $token]);
        $switch      = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/account/operator-nudges')
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)])
            ->withParsedBody(['onboarding' => false]);

        self::assertSame(403, $this->createApp()->handle($unsubscribe)->getStatusCode());
        self::assertSame(403, $this->createApp()->handle($switch)->getStatusCode());
        self::assertTrue($this->prefs($userId)['onboarding']);
    }
}
