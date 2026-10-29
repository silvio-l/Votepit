<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Security\CsrfService;

final class CsrfServiceTest extends TestCase
{
    private function service(?string $key = null, bool $secure = false): CsrfService
    {
        return new CsrfService($key ?? str_repeat('a', 64), 3600, $secure);
    }

    public function test_sign_then_read_round_trips_the_token(): void
    {
        $svc   = $this->service();
        $token = $svc->generate();

        self::assertSame($token, $svc->read($svc->sign($token)));
    }

    public function test_read_rejects_tampered_token_or_mac(): void
    {
        $svc   = $this->service();
        $token = $svc->generate();
        $cookie = $svc->sign($token);

        [$body, $mac] = explode('.', $cookie, 2);

        self::assertNull($svc->read($body . 'x.' . $mac), 'tampered token');
        self::assertNull($svc->read($body . '.' . $mac . 'x'), 'tampered MAC');
    }

    public function test_read_rejects_malformed_or_foreign_key(): void
    {
        $svc   = $this->service();
        $token = $svc->generate();

        self::assertNull($svc->read(null));
        self::assertNull($svc->read('nodot'));
        // Signed with a foreign app_key → MAC does not match.
        $foreign = $this->service(str_repeat('b', 64))->sign($token);
        self::assertNull($svc->read($foreign));
    }

    // -------------------------------------------------------------------------
    // V3.3.1: __Host- cookie-prefix naming — only valid together with Secure
    // (RFC 6265bis; Chromium enforces this by silently refusing to store a
    // prefixed cookie without it). $secure gates the prefix.
    // -------------------------------------------------------------------------

    public function test_cookie_name_carries_the_host_prefix_only_when_secure(): void
    {
        self::assertSame('__Host-votepit_csrf', $this->service(secure: true)->cookieName());
        self::assertSame('votepit_csrf', $this->service(secure: false)->cookieName());
    }

    /**
     * Regression (2026-09-10): a `__Host-`-prefixed cookie issued WITHOUT
     * Secure is invalid per RFC 6265bis and gets silently dropped by
     * Chromium — reproduced end-to-end by the Playwright E2E suite (every
     * spec failing on a csrf.mismatch at the first POST /login since
     * ed9297d). Asserting the literal header string here is the unit-level
     * proof: cookieName() must never be __Host-/__Secure- unless the Secure
     * attribute is actually being added to the same Set-Cookie line.
     */
    public function test_issue_never_pairs_a_prefixed_cookie_name_without_secure(): void
    {
        $svc      = $this->service(secure: false);
        $response = $svc->issue((new ResponseFactory())->createResponse(), $svc->generate());
        $cookieHeader = $response->getHeader('Set-Cookie')[0];

        self::assertStringStartsWith('votepit_csrf=', $cookieHeader);
        self::assertStringNotContainsString('__Host-', $cookieHeader);
        self::assertStringNotContainsString('__Secure-', $cookieHeader);
    }

    /**
     * Not secure: cookieName() already IS the pre-hardening name, so the
     * pre-hardening-cleanup Set-Cookie must be skipped — emitting it anyway
     * would immediately re-expire (Max-Age=0) the cookie just issued above.
     */
    public function test_issue_emits_a_single_cookie_when_not_secure(): void
    {
        $svc      = $this->service(secure: false);
        $response = $svc->issue((new ResponseFactory())->createResponse(), $svc->generate());

        self::assertCount(1, $response->getHeader('Set-Cookie'));
    }

    public function test_issue_also_clears_the_pre_hardening_cookie_name(): void
    {
        $svc      = $this->service(secure: true);
        $response = $svc->issue((new ResponseFactory())->createResponse(), $svc->generate());
        $headers  = $response->getHeader('Set-Cookie');

        self::assertCount(2, $headers);
        self::assertStringStartsWith('__Host-votepit_csrf=', $headers[0]);
        self::assertStringStartsWith('votepit_csrf=;', $headers[1]);
        self::assertStringContainsString('Max-Age=0', $headers[1]);
    }

    // -------------------------------------------------------------------------
    // V9.2.2: domain-separated HMAC input (mirrors SessionService's `session:`)
    // -------------------------------------------------------------------------

    public function test_mac_uses_a_domain_separated_hmac_input(): void
    {
        $svc    = $this->service();
        $token  = $svc->generate();
        $cookie = $svc->sign($token);
        [, $mac] = explode('.', $cookie, 2);

        $key        = str_repeat('a', 64);
        $unprefixed = rtrim(strtr(base64_encode(hash_hmac('sha256', $token, $key, true)), '+/', '-_'), '=');
        self::assertNotSame($mac, $unprefixed);
    }
}
