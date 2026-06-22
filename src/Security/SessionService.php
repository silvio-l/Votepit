<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * Stateless signed-Cookie-Session.
 *
 * Cookie-Aufbau: base64url(json(payload)) + '.' + base64url(hmac_sha256(...)).
 * Der Server hält keine Server-Session (kein /tmp, keine File-Locks, shared-
 * hosting-tauglich). Payload enthält nur eine Nutzer-ID (und ggf. ein
 * CSRF-Token-Seed); bei Logout wird das Cookie clientseitig gelöscht UND die
 * Gültigkeit durch eine Server-seitige Session-Tabelle fallbar gemacht
 * (Sprint 2: rotierender `session_series`).
 *
 * Konstant: HMAC-Verify via hash_equals (Timing-Angriff-resistent).
 */
final readonly class SessionService
{
    private const COOKIE_NAME = 'votepit_sess';

    public function __construct(
        private string $appKey,
        private int $lifetime,
        private bool $secure,
    ) {}

    /**
     * Signiert eine Payload zu einem Cookie-Wert.
     *
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $mac  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->appKey, true)), '+/', '-_'), '=');
        return $body . '.' . $mac;
    }

    /**
     * Verifiziert einen Cookie-Wert; liefert die Payload oder null.
     *
     * @return array<string, mixed>|null
     */
    public function verify(?string $cookie): ?array
    {
        if ($cookie === null || !str_contains($cookie, '.')) {
            return null;
        }
        [$body, $mac] = explode('.', $cookie, 2);
        if ($body === '' || $mac === '') {
            return null;
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $this->appKey, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $mac)) {
            return null;
        }
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($payload) ? $payload : null;
    }

    /**
     * Setzt das Session-Cookie auf dem Response.
     *
     * @param array<string, mixed> $payload
     */
    public function issue(ResponseInterface $response, array $payload): ResponseInterface
    {
        return $response->withHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $this->sign($payload)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
    }

    /** Löscht das Session-Cookie (Logout). */
    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=0'
        );
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }
}
