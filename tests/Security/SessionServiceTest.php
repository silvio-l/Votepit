<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Security\SessionService;

/**
 * SessionService cookie domain configurability (cloud path routing).
 *
 * Self-host (default, no cookieDomain override): host-only cookie — NO
 * Domain attribute in the Set-Cookie header. Cloud (cookieDomain set, e.g.
 * 'app.example.com'): Domain attribute explicitly set. Secure/HttpOnly/
 * SameSite remain unchanged in both cases (not checked here).
 */
final class SessionServiceTest extends TestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function setCookieHeader(SessionService $sessions): string
    {
        $response = $sessions->issue((new ResponseFactory())->createResponse(), ['uid' => 1]);
        $headers  = $response->getHeader('Set-Cookie');
        self::assertNotEmpty($headers);
        return $headers[0];
    }

    public function test_self_host_default_issues_host_only_cookie_without_domain_attribute(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true);

        $header = $this->setCookieHeader($sessions);

        self::assertStringNotContainsString('Domain=', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
        self::assertStringContainsString('Secure', $header);
    }

    public function test_cloud_mode_sets_configured_cookie_domain(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true, 'app.example.com');

        $header = $this->setCookieHeader($sessions);

        self::assertStringContainsString('Domain=app.example.com', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
        self::assertStringContainsString('Secure', $header);
    }

    public function test_clear_also_honors_configured_cookie_domain(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false, 'app.example.com');

        $response = $sessions->clear((new ResponseFactory())->createResponse());
        $header   = $response->getHeader('Set-Cookie')[0] ?? '';

        self::assertStringContainsString('Domain=app.example.com', $header);
        self::assertStringContainsString('Max-Age=0', $header);
    }

    public function test_empty_string_cookie_domain_behaves_like_unset(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false, '');

        $header = $this->setCookieHeader($sessions);

        self::assertStringNotContainsString('Domain=', $header);
    }

    // -------------------------------------------------------------------------
    // Security review: server-side absolute session expiry (ASVS V3.3, CWE-613).
    // -------------------------------------------------------------------------

    public function test_signed_session_carries_exp_and_iat_claims_bound_to_lifetime(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);
        $before   = time();

        $payload = $sessions->verify($sessions->sign(['uid' => 7, 'v' => 0]));

        self::assertIsArray($payload);
        self::assertSame(7, $payload['uid']);
        self::assertIsInt($payload['iat']);
        self::assertIsInt($payload['exp']);
        self::assertGreaterThanOrEqual($before, $payload['iat']);
        self::assertSame($payload['iat'] + 3600, $payload['exp']);
    }

    public function test_expired_session_is_rejected_even_with_valid_signature(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);

        $expired = $sessions->sign(['uid' => 7, 'v' => 0, 'exp' => time() - 1]);

        self::assertNull($sessions->verify($expired));
    }

    public function test_legacy_session_without_exp_claim_is_rejected(): void
    {
        // A cookie minted before this rule existed (or a forged payload that
        // omits exp) must not be accepted as an unlimited-lifetime session.
        $body = rtrim(strtr(base64_encode((string) json_encode(['uid' => 7, 'v' => 0])), '+/', '-_'), '=');
        $mac  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::APP_KEY, true)), '+/', '-_'), '=');

        self::assertNull((new SessionService(self::APP_KEY, 3600, false))->verify($body . '.' . $mac));
    }

    public function test_non_integer_exp_claim_is_rejected(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);

        self::assertNull($sessions->verify($sessions->sign(['uid' => 7, 'exp' => '9999999999'])));
        self::assertNull($sessions->verify($sessions->sign(['uid' => 7, 'exp' => true])));
    }

    public function test_tampered_exp_claim_breaks_signature(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);
        $cookie   = $sessions->sign(['uid' => 7, 'v' => 0, 'exp' => time() - 1]);
        [$body]   = explode('.', $cookie, 2);

        $forgedBody = rtrim(strtr(base64_encode((string) json_encode(['uid' => 7, 'v' => 0, 'exp' => time() + 3600])), '+/', '-_'), '=');
        $mac        = explode('.', $cookie, 2)[1];

        self::assertNotSame($body, $forgedBody);
        self::assertNull($sessions->verify($forgedBody . '.' . $mac));
    }
}
