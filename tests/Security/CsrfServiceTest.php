<?php

declare(strict_types=1);

namespace Votepit\Tests\Security;

use PHPUnit\Framework\TestCase;
use Votepit\Security\CsrfService;

final class CsrfServiceTest extends TestCase
{
    private function service(?string $key = null): CsrfService
    {
        return new CsrfService($key ?? str_repeat('a', 64), 3600, false);
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

        self::assertNull($svc->read($body . 'x.' . $mac), 'manipulierter Token');
        self::assertNull($svc->read($body . '.' . $mac . 'x'), 'manipulierter MAC');
    }

    public function test_read_rejects_malformed_or_foreign_key(): void
    {
        $svc   = $this->service();
        $token = $svc->generate();

        self::assertNull($svc->read(null));
        self::assertNull($svc->read('keinpunkt'));
        // Mit fremdem app_key signiert → MAC passt nicht.
        $foreign = $this->service(str_repeat('b', 64))->sign($token);
        self::assertNull($svc->read($foreign));
    }
}
