<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\WidgetTokenService;

/**
 * Short-lived Bearer token the widget iframe holds in memory after a
 * successful identify (ADR 0005 §3).
 */
final class WidgetTokenServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function service(string $key = 'a'): WidgetTokenService
    {
        return new WidgetTokenService(str_repeat($key, 64));
    }

    public function test_round_trip(): void
    {
        $token = $this->service()->issue(3, 7, 11, 'abcdef0123456789', self::NOW);

        self::assertSame(
            ['account_id' => 3, 'board_id' => 7, 'user_id' => 11, 'sv' => 'abcdef0123456789'],
            $this->service()->verify($token, self::NOW + 10),
        );
    }

    public function test_token_expires_after_ttl(): void
    {
        $token = $this->service()->issue(3, 7, 11, 'sv', self::NOW);

        self::assertNotNull($this->service()->verify($token, self::NOW + WidgetTokenService::TTL - 1));
        self::assertNull($this->service()->verify($token, self::NOW + WidgetTokenService::TTL));
    }

    public function test_token_of_another_installation_key_is_rejected(): void
    {
        $token = $this->service('a')->issue(3, 7, 11, 'sv', self::NOW);

        self::assertNull($this->service('c')->verify($token, self::NOW));
    }

    public function test_tampered_body_is_rejected(): void
    {
        $token = $this->service()->issue(3, 7, 11, 'sv', self::NOW);
        [$prefix, , $mac] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode((string) json_encode(['a' => 3, 'b' => 8, 'u' => 11, 'sv' => 'sv', 'exp' => self::NOW + 100])), '+/', '-_'), '=');

        self::assertNull($this->service()->verify($prefix . '.' . $forged . '.' . $mac, self::NOW));
    }

    public function test_garbage_is_rejected(): void
    {
        foreach (['', 'vpw1', 'vpw1..', 'x.y.z', 'vpw2.a.b', str_repeat('a', 2000)] as $garbage) {
            self::assertNull($this->service()->verify($garbage, self::NOW), $garbage);
        }
    }

    public function test_fingerprint_changes_on_rotation(): void
    {
        self::assertNotSame(
            WidgetTokenService::secretFingerprint('enc-1', '2026-01-01 00:00:00'),
            WidgetTokenService::secretFingerprint('enc-2', '2026-01-02 00:00:00'),
        );
        self::assertSame(16, strlen(WidgetTokenService::secretFingerprint('enc', null)));
    }
}
