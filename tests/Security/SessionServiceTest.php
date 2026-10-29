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

    /**
     * A browser that still holds a pre-cloud-migration host-only session
     * cookie stores it alongside the new Domain-scoped one (both domain-match
     * the same host) and may send the stale one first, which then never
     * verifies — silently bouncing a freshly-logged-in user back to /login.
     * Every issue()/clear() on a Domain-scoped deployment must also expire
     * any host-only cookie of the same name to self-heal that browser state.
     */
    public function test_cloud_mode_issue_also_clears_a_legacy_host_only_cookie(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true, 'app.example.com');

        $response = $sessions->issue((new ResponseFactory())->createResponse(), ['uid' => 1]);
        $headers  = $response->getHeader('Set-Cookie');

        // [0] main cookie, [1] legacy same-name host-only cleared, [2]
        // pre-__Host-/__Secure-rename plain-named cookie cleared (always).
        self::assertCount(3, $headers);
        self::assertStringContainsString('Domain=app.example.com', $headers[0]);
        self::assertStringNotContainsString('Domain=', $headers[1]);
        self::assertStringContainsString('Max-Age=0', $headers[1]);
        self::assertStringStartsWith('votepit_sess=;', $headers[2]);
        self::assertStringContainsString('Max-Age=0', $headers[2]);
    }

    public function test_cloud_mode_clear_also_clears_a_legacy_host_only_cookie(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true, 'app.example.com');

        $response = $sessions->clear((new ResponseFactory())->createResponse());
        $headers  = $response->getHeader('Set-Cookie');

        self::assertCount(3, $headers);
        self::assertStringContainsString('Domain=app.example.com', $headers[0]);
        self::assertStringNotContainsString('Domain=', $headers[1]);
        self::assertStringContainsString('Max-Age=0', $headers[1]);
        self::assertStringStartsWith('votepit_sess=;', $headers[2]);
    }

    public function test_self_host_issue_emits_the_main_cookie_and_the_pre_hardening_cleanup(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true);

        $response = $sessions->issue((new ResponseFactory())->createResponse(), ['uid' => 1]);
        $headers  = $response->getHeader('Set-Cookie');

        self::assertCount(2, $headers);
        self::assertStringStartsWith('__Host-votepit_sess=', $headers[0]);
        self::assertStringStartsWith('votepit_sess=;', $headers[1]);
        self::assertStringContainsString('Max-Age=0', $headers[1]);
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

    // -------------------------------------------------------------------------
    // V3.3.1/V3.3.3: __Host-/__Secure- cookie-prefix naming
    // -------------------------------------------------------------------------

    public function test_self_host_cookie_name_carries_host_prefix(): void
    {
        self::assertSame('__Host-votepit_sess', (new SessionService(self::APP_KEY, 3600, true))->cookieName());
    }

    public function test_cloud_cookie_name_carries_secure_prefix(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true, 'app.example.com');
        self::assertSame('__Secure-votepit_sess', $sessions->cookieName());
    }

    public function test_issued_cookie_uses_the_computed_cookie_name(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, true);
        $header   = $this->setCookieHeader($sessions);
        self::assertStringStartsWith('__Host-votepit_sess=', $header);
    }

    /**
     * Regression (2026-09-10): a `__Host-`/`__Secure-`-prefixed cookie issued
     * WITHOUT Secure is invalid per RFC 6265bis and gets silently dropped by
     * Chromium — reproduced end-to-end by the Playwright E2E suite (every
     * spec failing on a csrf.mismatch at the first POST /login since
     * ed9297d, since a dropped session cookie behaves the same way). Neither
     * prefix may ever appear unless Secure is also present on the same line.
     */
    public function test_cookie_name_never_carries_a_prefix_when_not_secure(): void
    {
        self::assertSame('votepit_sess', (new SessionService(self::APP_KEY, 3600, false))->cookieName());
        self::assertSame(
            'votepit_sess',
            (new SessionService(self::APP_KEY, 3600, false, 'app.example.com'))->cookieName()
        );
    }

    public function test_issue_emits_a_single_unprefixed_cookie_when_not_secure(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);
        $response = $sessions->issue((new ResponseFactory())->createResponse(), ['uid' => 1]);
        $headers  = $response->getHeader('Set-Cookie');

        self::assertCount(1, $headers);
        self::assertStringStartsWith('votepit_sess=', $headers[0]);
        self::assertStringNotContainsString('__Host-', $headers[0]);
        self::assertStringNotContainsString('__Secure-', $headers[0]);
        self::assertStringNotContainsString('Secure', $headers[0]);
    }

    // -------------------------------------------------------------------------
    // V9.2.2: domain-separated HMAC input (session vs. CSRF, TotpSetupToken pattern)
    // -------------------------------------------------------------------------

    public function test_signature_uses_a_domain_separated_hmac_input(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600, false);
        $cookie   = $sessions->sign(['uid' => 7, 'v' => 0]);
        [$body, $mac] = explode('.', $cookie, 2);

        // An un-prefixed HMAC over the same body must NOT match — the
        // scheme is now bound to the 'session:' prefix.
        $unprefixed = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::APP_KEY, true)), '+/', '-_'), '=');
        self::assertNotSame($mac, $unprefixed);
    }

    // -------------------------------------------------------------------------
    // CON.10.A3 / ASVS V7.3.1: rolling inactivity timeout independent of `exp`
    // -------------------------------------------------------------------------

    public function test_session_within_inactivity_window_is_accepted(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600 * 24 * 30, false, null, 3600);
        $cookie   = $sessions->sign(['uid' => 7, 'v' => 0, 'lact' => time() - 1000]);

        self::assertIsArray($sessions->verify($cookie));
    }

    public function test_session_past_the_inactivity_window_is_rejected_even_with_a_future_exp(): void
    {
        $sessions = new SessionService(self::APP_KEY, 3600 * 24 * 30, false, null, 3600);
        $cookie   = $sessions->sign(['uid' => 7, 'v' => 0, 'lact' => time() - 3601]);

        self::assertNull($sessions->verify($cookie));
    }

    public function test_session_without_a_last_active_claim_is_rejected(): void
    {
        // Same fail-secure treatment as a missing `exp` (legacy-payload test
        // above) — a payload minted before this feature, or a forged one
        // that strips the claim, must not be treated as freshly active.
        $sessions = new SessionService(self::APP_KEY, 3600, false);
        $body     = rtrim(strtr(base64_encode((string) json_encode(['uid' => 7, 'v' => 0, 'exp' => time() + 3600])), '+/', '-_'), '=');
        $mac      = rtrim(strtr(base64_encode(hash_hmac('sha256', 'session:' . $body, self::APP_KEY, true)), '+/', '-_'), '=');

        self::assertNull($sessions->verify($body . '.' . $mac));
    }
}
