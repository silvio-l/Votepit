<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\ClientIp;

/**
 * Unit tests for ClientIp::resolve (proxy-aware client IP).
 *
 * Pins down the fail-secure direction: without explicit trust (origin lock
 * not yet configured), `CF-Connecting-IP` is NEVER used, even if the header
 * is present — otherwise any client reaching the origin directly could
 * freely spoof its rate-limit/log identity.
 */
final class ClientIpTest extends TestCase
{
    private function request(string $remoteAddr, ?string $cfHeader = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => $remoteAddr]);
        if ($cfHeader !== null) {
            $request = $request->withHeader('CF-Connecting-IP', $cfHeader);
        }
        return $request;
    }

    public function test_untrusted_mode_always_uses_remote_addr_even_with_header_present(): void
    {
        $request = $this->request('203.0.113.9', '198.51.100.7');
        self::assertSame('203.0.113.9', ClientIp::resolve($request, trustCloudflareIp: false));
    }

    public function test_trusted_mode_prefers_valid_cf_header(): void
    {
        $request = $this->request('203.0.113.9', '198.51.100.7');
        self::assertSame('198.51.100.7', ClientIp::resolve($request, trustCloudflareIp: true));
    }

    public function test_trusted_mode_falls_back_to_remote_addr_when_header_missing(): void
    {
        $request = $this->request('203.0.113.9');
        self::assertSame('203.0.113.9', ClientIp::resolve($request, trustCloudflareIp: true));
    }

    public function test_trusted_mode_falls_back_to_remote_addr_when_header_malformed(): void
    {
        $request = $this->request('203.0.113.9', 'not-an-ip; DROP TABLE');
        self::assertSame('203.0.113.9', ClientIp::resolve($request, trustCloudflareIp: true));
    }

    public function test_returns_null_when_no_ip_available_at_all(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        self::assertNull(ClientIp::resolve($request, trustCloudflareIp: false));
    }
}
