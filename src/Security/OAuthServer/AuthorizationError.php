<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

/**
 * A rejected authorization request. `redirectTo` is set only when the client
 * and its redirect URI were verified first — errors about the client or the
 * redirect URI themselves are never sent anywhere (RFC 6749 §4.1.2.1: no
 * redirect to an unverified URI, which would be an open redirector); the
 * consent screen shows them instead.
 */
final readonly class AuthorizationError
{
    public function __construct(
        public string $error,
        public string $description,
        public ?string $redirectTo = null,
    ) {}
}
