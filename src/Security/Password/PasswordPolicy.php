<?php

declare(strict_types=1);

namespace Votepit\Security\Password;

/**
 * Three-layer password-strength check, run before hashing/storing any new
 * password (first-time set, change, reset — see SetPasswordAction and
 * PasswordResetConfirmAction, the only two callers, OWASP/BSI audit
 * remediation issue 04):
 *
 *   1. A vendored top-3000 password-frequency list (resources/security/
 *      password-denylist-top3000.txt, see LICENSE-SecLists.txt there).
 *   2. A caller-supplied context denylist (product name, username, account
 *      slug, ...) — case-insensitive substring match. ADR 0002 pseudonymizes
 *      email (only email_hmac is ever persisted), so "email local part" from
 *      the audit issue's example list is not available here; callers pass
 *      whatever plaintext context they actually have instead (see
 *      SetPasswordAction/PasswordResetConfirmAction).
 *   3. The HIBP Pwned Passwords range API (k-anonymity: only a 5-char SHA-1
 *      prefix is ever sent, see checkHibp()) — fails OPEN on any transport
 *      failure (HIBP being unreachable must never block a legitimate
 *      password set).
 *
 * Pure/stateless beyond the loaded denylist and the injected HTTP seam — no
 * DB access.
 */
final readonly class PasswordPolicy
{
    private const HIBP_RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    /** Context terms shorter than this are too generic to meaningfully block (avoids false positives on e.g. a 1-2 char username). */
    private const MIN_CONTEXT_TERM_LENGTH = 3;

    /** @var array<string, true> lowercased top-3000 passwords, keyed for O(1) lookup */
    private array $commonPasswords;

    public function __construct(
        private HibpHttpClient $hibp,
        string $denylistPath = __DIR__ . '/../../../resources/security/password-denylist-top3000.txt',
    ) {
        $this->commonPasswords = $this->loadDenylist($denylistPath);
    }

    /**
     * @param list<string> $contextTerms Case-insensitive substrings that must
     *                                   not appear in the password (product
     *                                   name, username, account slug, ...).
     *                                   Terms shorter than
     *                                   MIN_CONTEXT_TERM_LENGTH are ignored.
     */
    public function check(string $password, array $contextTerms = []): PasswordPolicyResult
    {
        $normalized = mb_strtolower($password, 'UTF-8');

        if (isset($this->commonPasswords[$normalized])) {
            return PasswordPolicyResult::violation(
                'password_too_common',
                'This password is one of the most commonly used passwords and is not allowed.',
            );
        }

        foreach ($contextTerms as $term) {
            $term = mb_strtolower(trim($term), 'UTF-8');
            if (mb_strlen($term) >= self::MIN_CONTEXT_TERM_LENGTH && str_contains($normalized, $term)) {
                return PasswordPolicyResult::violation(
                    'password_contains_context',
                    'The password must not contain your username, account name, or the product name.',
                );
            }
        }

        if (($this->checkHibp($password) ?? 0) > 0) {
            return PasswordPolicyResult::violation(
                'password_pwned',
                'This password has appeared in a known data breach. Please choose a different one.',
            );
        }

        return PasswordPolicyResult::ok();
    }

    /**
     * Queries the HIBP Pwned Passwords range API via k-anonymity: only the
     * first 5 hex chars of the password's SHA-1 hash are sent; the full
     * suffix-list response is matched locally, so the actual password never
     * leaves this process. Returns the breach count, 0 if not found, or null
     * if the check could not be completed at all (fail-open — caller treats
     * null like "no hit").
     */
    private function checkHibp(string $password): ?int
    {
        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $body = $this->hibp->get(self::HIBP_RANGE_URL . $prefix);
        if ($body === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        foreach ($lines !== false ? $lines : [] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp($parts[0], $suffix) === 0) {
                return (int) $parts[1];
            }
        }

        return 0;
    }

    /** @return array<string, true> */
    private function loadDenylist(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $map = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $map[mb_strtolower($line, 'UTF-8')] = true;
            }
        }

        return $map;
    }
}
