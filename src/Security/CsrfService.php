<?php

declare(strict_types=1);

namespace Votepit\Security;

use Psr\Http\Message\ResponseInterface;

/**
 * CSRF-Synchronizer-Token (ADR-6, Amendment 2026-06-22).
 *
 * Das ursprüngliche slim/csrf braucht serverseitigen $_SESSION-Storage und
 * widerspricht damit der bewusst stateless gehaltenen Signed-Cookie-Session
 * (SessionService). Stattdessen lebt der CSRF-Token in einem eigenen, HMAC-
 * signierten Cookie (gleiches base64url+SHA256-Schema wie SessionService) und
 * wird vom Server in jedes Formular gespiegelt — ein echter Synchronizer-Token
 * ohne Server-State.
 *
 * Cookie-Aufbau: <token-hex> '.' base64url(hmac_sha256(token, app_key)).
 * Der Token selbst ist Hex (keine Punkte), der MAC base64url (keine Punkte) →
 * eindeutig splitbar. Verifikation konstant-zeitig via hash_equals.
 */
final readonly class CsrfService
{
    private const COOKIE_NAME = 'votepit_csrf';
    private const FIELD_NAME  = '_csrf';

    public function __construct(
        private string $appKey,
        private int $lifetime,
        private bool $secure,
    ) {}

    /** Erzeugt einen frischen Zufalls-Token (32 Byte → 64 hex). */
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Signiert einen Token zu einem Cookie-Wert. */
    public function sign(string $token): string
    {
        return $token . '.' . $this->mac($token);
    }

    /** Liest+verifiziert einen Cookie-Wert; liefert den Token oder null. */
    public function read(?string $cookie): ?string
    {
        if ($cookie === null || !str_contains($cookie, '.')) {
            return null;
        }
        [$token, $mac] = explode('.', $cookie, 2);
        if ($token === '' || $mac === '') {
            return null;
        }
        if (!hash_equals($this->mac($token), $mac)) {
            return null;
        }
        return $token;
    }

    /** Setzt das CSRF-Cookie auf dem Response (HttpOnly: nur der Server spiegelt den Token). */
    public function issue(ResponseInterface $response, string $token): ResponseInterface
    {
        return $response->withAddedHeader(
            'Set-Cookie',
            self::COOKIE_NAME . '=' . $this->sign($token)
            . '; Path=/; HttpOnly; SameSite=Strict'
            . ($this->secure ? '; Secure' : '')
            . '; Max-Age=' . $this->lifetime
        );
    }

    public function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    private function mac(string $token): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $token, $this->appKey, true)), '+/', '-_'), '=');
    }
}
