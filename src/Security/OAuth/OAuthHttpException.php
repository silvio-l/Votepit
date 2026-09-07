<?php

declare(strict_types=1);

namespace Votepit\Security\OAuth;

/**
 * Thrown by an OAuthHttpClient implementation on a transport-level failure
 * (DNS/connect/timeout/TLS) — deliberately NOT thrown for a completed HTTP
 * response with a non-2xx status (the caller inspects `status`/`body` for
 * that; a provider's 4xx/5xx is a normal, expected outcome to handle, not an
 * exceptional one). Unlike Votepit\Telemetry\CurlMatomoEventTracker, OAuth
 * failures must surface as a login failure, never be swallowed silently.
 */
final class OAuthHttpException extends \RuntimeException {}
