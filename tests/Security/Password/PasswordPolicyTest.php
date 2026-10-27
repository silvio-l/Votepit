<?php

declare(strict_types=1);

namespace Votepit\Tests\Security\Password;

use PHPUnit\Framework\TestCase;
use Votepit\Security\Password\InMemoryHibpHttpClient;
use Votepit\Security\Password\PasswordPolicy;

final class PasswordPolicyTest extends TestCase
{
    private function policy(?InMemoryHibpHttpClient $hibp = null): PasswordPolicy
    {
        return new PasswordPolicy($hibp ?? new InMemoryHibpHttpClient());
    }

    public function test_rejects_a_top3000_common_password_case_insensitively(): void
    {
        $result = $this->policy()->check('QwertyUiop');

        self::assertFalse($result->ok);
        self::assertSame('password_too_common', $result->errorKey);
    }

    public function test_accepts_a_password_not_in_the_top3000_list(): void
    {
        $result = $this->policy()->check('correcthorsebatterystaple9');

        self::assertTrue($result->ok);
    }

    public function test_rejects_a_password_containing_a_context_term_case_insensitively(): void
    {
        $result = $this->policy()->check('mySuperVOTEPITpass', ['votepit']);

        self::assertFalse($result->ok);
        self::assertSame('password_contains_context', $result->errorKey);
    }

    public function test_ignores_context_terms_shorter_than_the_minimum_length(): void
    {
        // A 2-char context term ("ab") would false-positive on almost any
        // password — too generic to be a meaningful signal.
        $result = $this->policy()->check('correcthorsebatterystaple9', ['ab']);

        self::assertTrue($result->ok);
    }

    public function test_rejects_a_password_found_in_the_hibp_range_response(): void
    {
        $password = 'a-quite-unusual-string-27';
        $sha1     = strtoupper(sha1($password));
        $prefix   = substr($sha1, 0, 5);
        $suffix   = substr($sha1, 5);

        $hibp = new InMemoryHibpHttpClient();
        $hibp->stub('https://api.pwnedpasswords.com/range/' . $prefix, "{$suffix}:42\r\nDEADBEEFDEADBEEFDEADBEEFDEADBEEFAAA:1");

        $result = $this->policy($hibp)->check($password);

        self::assertFalse($result->ok);
        self::assertSame('password_pwned', $result->errorKey);
    }

    public function test_accepts_a_password_absent_from_the_hibp_range_response(): void
    {
        $password = 'a-quite-unusual-string-29';
        $sha1     = strtoupper(sha1($password));
        $prefix   = substr($sha1, 0, 5);

        $hibp = new InMemoryHibpHttpClient();
        $hibp->stub('https://api.pwnedpasswords.com/range/' . $prefix, "DEADBEEFDEADBEEFDEADBEEFDEADBEEFAAA:1");

        $result = $this->policy($hibp)->check($password);

        self::assertTrue($result->ok);
    }

    /** Fail-open: a HIBP transport failure must never block a legitimate password. */
    public function test_accepts_a_password_when_hibp_is_unreachable(): void
    {
        $password = 'a-quite-unusual-string-30';
        $sha1     = strtoupper(sha1($password));
        $prefix   = substr($sha1, 0, 5);

        $hibp = new InMemoryHibpHttpClient();
        $hibp->stubFailure('https://api.pwnedpasswords.com/range/' . $prefix);

        $result = $this->policy($hibp)->check($password);

        self::assertTrue($result->ok);
    }

    public function test_common_password_check_takes_priority_over_hibp_when_both_would_fail(): void
    {
        // No stub registered for this prefix at all — if the common-password
        // check didn't short-circuit first, this would hit the "no stub
        // registered" default (null → fail-open) and could mask a real bug
        // in which check wins; asserting the specific error key nails it down.
        $result = $this->policy()->check('qwertyuiop');

        self::assertSame('password_too_common', $result->errorKey);
    }
}
