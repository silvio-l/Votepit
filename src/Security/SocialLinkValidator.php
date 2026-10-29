<?php

declare(strict_types=1);

namespace Votepit\Security;

/**
 * Validates the 4 fixed social-profile identifiers BEFORE storage
 * (profile-avatar-social security redesign, sprint social-links-structured).
 *
 * Replaces the earlier free-form "label + arbitrary https:// URL" model
 * (up to 5 rows, see migrations/0019 — since dropped by 0020): a shared-
 * origin app rendering an account owner's arbitrary URL as a trusted
 * clickable link is a structural phishing/XSS-adjacent risk (OWASP A01/A03
 * territory) no amount of scheme-checking on the URL itself fully closes —
 * the host is still whatever the user typed. The fix is to never accept a
 * URL from the user at all: each of the 4 methods below accepts ONLY a bare
 * platform identifier (a domain or a username/handle), validates it against
 * that platform's real grammar, and the caller (AccountProfileAction)
 * constructs the fixed `https://<platform>/<identifier>` URL itself — a
 * scheme, host, path, query string, or fragment can never come from the
 * user, so `javascript:`/`data:`/open-redirect-via-path tricks are
 * structurally impossible rather than merely filtered.
 *
 * Every method: returns the normalized value to store, or null if the raw
 * input fails validation. Fail-secure — no method "cleans up" malformed
 * input; the only normalizations performed anywhere here are the two the
 * task explicitly allows: stripping one leading "@" from an X handle, and
 * (deliberately NOT done for YouTube — see youtubeHandle() below) prefixing
 * "@" onto a YouTube handle at URL-construction time, never at storage time.
 */
final class SocialLinkValidator
{
    private const MAX_DOMAIN_LENGTH = 253;

    private const MAX_DOMAIN_LABEL_LENGTH = 63;

    /** Per-label allowlist mirrors SlugValidator's slug charset — lowercase alnum + interior hyphens only. */
    private const DOMAIN_LABEL_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const X_HANDLE_MAX_LENGTH = 15;

    private const X_HANDLE_PATTERN = '/^\w{1,15}$/';

    private const YOUTUBE_HANDLE_MIN_LENGTH = 3;

    private const YOUTUBE_HANDLE_MAX_LENGTH = 30;

    private const YOUTUBE_HANDLE_PATTERN = '/^[A-Za-z0-9_.-]{3,30}$/';

    private const GITHUB_USERNAME_MAX_LENGTH = 39;

    /** Alnum segments joined by single hyphens — no leading/trailing/doubled hyphen. */
    private const GITHUB_USERNAME_PATTERN = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

    /**
     * Website: a bare domain, e.g. "example.com" — NEVER a full URL (no
     * scheme is ever accepted; the caller always reconstructs
     * "https://" . website(...)).
     *
     * Judgment call (task explicitly leaves this open): bare domain ONLY,
     * no path segment permitted. Reasoning — a path adds meaningful parsing
     * surface (encoded slashes, dot-segments, trailing-slash ambiguity) for
     * a field whose entire purpose is "link to your homepage"; a user who
     * wants to link a specific page (e.g. a portfolio path) can put that
     * link ON their homepage instead. Stricter-than-required is the
     * fail-secure default when the spec leaves a field genuinely optional.
     *
     * Categorically rejects (never "cleaned up", always a hard reject):
     *   - any "://" / scheme prefix (http:, https:, javascript:, data:, …)
     *   - userinfo ("user:pass@")
     *   - a query string ("?") or fragment ("#") — both imply the value
     *     wasn't a bare domain to begin with
     *   - a path ("/anything") — see the path-segment decision above
     *   - non-ASCII / punycode / homoglyph hostnames — every label must
     *     pass the same strict [a-z0-9-] allowlist as SlugValidator's slug
     *     pattern (reused directly, ADR-consistent single charset for
     *     anything that becomes a routable/linkable identifier) — this also
     *     means a label with a LEADING/TRAILING/consecutive hyphen is
     *     rejected the same way a slug would be, even though real-world DNS
     *     is technically more permissive there; the stricter, reused
     *     charset is preferred over a second bespoke domain grammar
     *   - an IPv4 or IPv6 literal host (a "domain" that resolves to a bare
     *     IP is never a legitimate personal-website identifier here, and
     *     IP literals are a classic SSRF/lookalike vector)
     *   - a bare single-label host ("localhost", "example") — a real
     *     public domain always has at least one dot and a plausible TLD
     */
    public static function website(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_DOMAIN_LENGTH) {
            return null;
        }

        // Reject anything that isn't plain lowercase-after-normalization
        // ASCII up front — control chars, whitespace, "/", "?", "#", "@",
        // ":" (which also rules out a scheme prefix and any port suffix)
        // all fail here before any label-by-label check runs.
        $lower = strtolower($trimmed);
        if (preg_match('/^[a-z0-9.-]+$/', $lower) !== 1) {
            return null;
        }

        // filter_var() IP-literal check BEFORE the label split: a bare IPv4
        // ("1.2.3.4") would otherwise pass the label pattern below (each
        // octet is digits-only, which satisfies DOMAIN_LABEL_PATTERN).
        if (filter_var($lower, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return null;
        }
        if (filter_var($lower, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return null;
        }

        $labels = explode('.', $lower);
        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > self::MAX_DOMAIN_LABEL_LENGTH) {
                return null;
            }
            if (preg_match(self::DOMAIN_LABEL_PATTERN, $label) !== 1) {
                return null;
            }
        }

        // Plausibility check on the TLD: real TLDs are alphabetic and at
        // least 2 chars. Not a full IANA TLD-list validation (that list
        // changes over time and this isn't a registrar) — just enough to
        // reject "example.1" / "example.-" style non-domains that would
        // otherwise pass the generic label pattern above.
        $tld = $labels[count($labels) - 1];
        if (strlen($tld) < 2 || preg_match('/^[a-z]+$/', $tld) !== 1) {
            return null;
        }

        return $lower;
    }

    /**
     * X (Twitter): a bare handle, optional leading "@" stripped before
     * storage (the one explicitly-permitted normalization for this field).
     * The stored value NEVER carries the "@" — the caller always
     * reconstructs "https://x.com/" . xHandle(...).
     *
     * Grammar: alphanumeric + underscore only, max 15 characters — X's
     * long-documented (and still-enforced at the time of writing) username
     * limit. No "/", "?", "#", or whitespace can ever pass — the allowlist
     * pattern is exhaustive, nothing is stripped except the one leading "@".
     */
    public static function xHandle(string $value): ?string
    {
        $trimmed = trim($value);
        $handle  = str_starts_with($trimmed, '@') ? substr($trimmed, 1) : $trimmed;

        if ($handle === '' || strlen($handle) > self::X_HANDLE_MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::X_HANDLE_PATTERN, $handle) !== 1) {
            return null;
        }

        return $handle;
    }

    /**
     * YouTube: a bare handle for the modern "@handle" URL format
     * (https://www.youtube.com/@<handle>).
     *
     * Judgment call (task explicitly leaves this open): the "@" is added
     * ONLY when constructing the URL, NEVER accepted from the user and
     * NEVER stored. Unlike xHandle() above, a leading "@" in the raw input
     * here is a hard REJECT, not stripped — the task permits exactly two
     * normalizations (strip "@" for X; optionally prefix "@" for YouTube at
     * construction time), and accepting-then-stripping a YouTube "@" would
     * be a third, unlisted one. Rejecting it costs the user nothing (the
     * field hint tells them to type the bare handle) and keeps every
     * normalization in this class enumerable and intentional.
     *
     * Grammar: YouTube's actual handle rules are looser than X's — letters,
     * digits, underscore, hyphen, and period, 3-30 characters. No official
     * hard username-registry to check against here (YouTube handles are
     * self-service, no fixed max like X publishes), so 30 is a generous
     * but bounded ceiling rather than a documented platform constant.
     */
    public static function youtubeHandle(string $value): ?string
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '@')) {
            return null;
        }

        if (strlen($trimmed) < self::YOUTUBE_HANDLE_MIN_LENGTH || strlen($trimmed) > self::YOUTUBE_HANDLE_MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::YOUTUBE_HANDLE_PATTERN, $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * GitHub: a bare username, validated against GitHub's real (documented)
     * username grammar — alphanumeric characters and single hyphens only,
     * no leading/trailing hyphen, no two consecutive hyphens, max 39
     * characters. GITHUB_USERNAME_PATTERN is structurally incapable of
     * matching a leading/trailing/doubled hyphen (each segment between
     * hyphens must itself be non-empty alnum), so no separate check is
     * needed for those cases.
     */
    public static function githubUsername(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::GITHUB_USERNAME_MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::GITHUB_USERNAME_PATTERN, $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }
}
