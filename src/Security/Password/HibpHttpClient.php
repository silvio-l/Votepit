<?php

declare(strict_types=1);

namespace Votepit\Security\Password;

/**
 * Outbound HTTP seam for the HIBP Pwned Passwords range API (k-anonymity:
 * PasswordPolicy sends only a 5-char SHA-1 prefix, never the password or its
 * full hash — see PasswordPolicy::checkHibp()). Mirrors Votepit\Security\
 * OAuth\OAuthHttpClient's interface+curl-impl+in-memory-fake pattern (this
 * codebase has no Guzzle/PSR HTTP client dependency, curl only).
 *
 * Unlike OAuthHttpClient, a transport-level failure here is NOT an
 * exception — it returns null, and PasswordPolicy fails open (a password
 * that could not be checked against HIBP is still accepted, per the audit
 * issue's explicit "Fail-Open bei Netzwerkfehlern" requirement: HIBP being
 * unreachable must never block someone from setting a password).
 */
interface HibpHttpClient
{
    /** Returns the raw response body, or null on any transport-level failure or non-2xx status. */
    public function get(string $url): ?string;
}
