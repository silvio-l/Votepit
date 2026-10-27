<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

use Votepit\Security\Webhook\AllowlistedUrlPolicy;

/**
 * The issue trackers an account can connect (competitor-parity issue 19).
 *
 * Every outbound request goes to the provider's ONE fixed API host
 * (apiPolicy()); a tenant can never steer a request — or its stored token —
 * to a host of their choosing (SSRF, OWASP A01). Issue URLs returned by the
 * provider are only stored when they point at the provider's own web host
 * (issueUrlPattern()), so a link rendered in the admin UI can never be an
 * active (`javascript:`) or foreign URL.
 */
enum IssueTrackerProvider: string
{
    case Linear = 'linear';
    case GitHub = 'github';

    /**
     * "owner/repo" as GitHub allows it: owner = 1-39 alphanumerics/hyphens,
     * repo = 1-100 of [A-Za-z0-9._-] but never "." or ".." (a dot segment
     * would let curl normalize the API path to a different endpoint).
     */
    public const GITHUB_REPO_PATTERN = '[A-Za-z0-9][A-Za-z0-9-]{0,38}/(?!\.\.?(?:/|$))[A-Za-z0-9._-]{1,100}';

    /** Linear team key, e.g. "ENG". */
    public const LINEAR_TEAM_KEY_PATTERN = '[A-Za-z0-9]{1,10}';

    public function apiPolicy(): AllowlistedUrlPolicy
    {
        return match ($this) {
            self::Linear => new AllowlistedUrlPolicy(['api.linear.app'], '#^/graphql$#'),
            self::GitHub => new AllowlistedUrlPolicy(['api.github.com'], '#^/repos/' . self::GITHUB_REPO_PATTERN . '(/issues)?$#'),
        };
    }

    /** @return non-empty-string PCRE a stored issue URL must fully match */
    public function issueUrlPattern(): string
    {
        return match ($this) {
            self::Linear => '#^https://linear\.app/[A-Za-z0-9._~/%-]{1,400}$#',
            self::GitHub => '#^https://github\.com/[A-Za-z0-9._-]{1,100}/[A-Za-z0-9._-]{1,100}/issues/\d{1,12}$#',
        };
    }

    /** Request header carrying the provider's HMAC-SHA256 webhook signature. */
    public function signatureHeader(): string
    {
        return match ($this) {
            self::Linear => 'Linear-Signature',
            self::GitHub => 'X-Hub-Signature-256',
        };
    }

    /**
     * Non-secret display form of a stored token ("…a1b2"). The token itself
     * is never returned to the client after saving.
     */
    public static function tokenHint(string $token): string
    {
        return '…' . substr($token, -4);
    }
}
