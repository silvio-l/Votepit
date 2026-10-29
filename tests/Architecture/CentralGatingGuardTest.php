<?php

declare(strict_types=1);

namespace Votepit\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guard for plan, operator and role gating. Each rule names the
 * central API to use and an allow-list of files where the raw pattern is
 * legitimate. Comments are stripped before matching, so docblocks may mention
 * anything. Scans core/src and, when present (private monorepo), cloud/src;
 * the ResolvedPlan rule also scans the tests trees.
 *
 * Plan provenance is enforced by PHPStan, not here: every PlanPolicy gate takes
 * a ResolvedPlan, so a raw string cannot reach a gate. This guard only pins
 * where a ResolvedPlan may be created.
 *
 * Paths are "core/..." or "cloud/..." relative to the src root; a trailing "/"
 * allows a whole directory.
 */
final class CentralGatingGuardTest extends TestCase
{
    /** Stored plan read, `$tenant['plan']`, `$row->plan`, `'plan' => …`: storage, billing and admin code only. */
    private const RAW_PLAN_ALLOWED = [
        'core/Domain/AccountPlanResolver.php',   // the reader itself
        'core/Persistence/',                      // repositories read and write the stored column
        'core/Http/Action/SupportRequestAction.php', // support diagnostics show the stored plan
        'core/Http/Action/BoardBrandingAction.php',  // storedPlan is display-only; gating uses the resolver
        'core/Http/Action/BoardProBrandingAction.php', // same
        'cloud/Admin/',                           // admin panels and metrics report the stored plan
        'cloud/Billing/',                         // billing state is the stored plan by definition
        'cloud/Marketing/',                       // announcement targeting by paying status
        'cloud/Referrals/',                       // plan transitions
        'cloud/TestAccount/',                     // fixture resets write the plan
        'cloud/Demo/',                            // fixture resets write the plan
    ];

    /** Only AccountPlanResolver mints a ResolvedPlan (forAccount / storedPlan). */
    private const RESOLVED_PLAN_ISSUE_ALLOWED = [
        'core/Domain/AccountPlanResolver.php',
    ];

    /** Tests that need a ResolvedPlan go through the test-only factory. */
    private const RESOLVED_PLAN_ISSUE_TEST_ALLOWED = [
        'core/tests/Support/ResolvedPlanFactory.php', // the factory itself
        'core/tests/Architecture/CentralGatingGuardTest.php', // names the forbidden call in its failure message
    ];

    /** storedPlan() skips the operator rule: billing, admin and new-account decisions only. */
    private const STORED_PLAN_ALLOWED = [
        'core/Http/Action/SignupAccountAction.php', // new account: initialPlan() has no stored row yet
        'cloud/Billing/',                           // webhook, cancel and display act on what is paid
        'cloud/Admin/',                             // admin grants, revokes and metrics act on the stored plan
        'core/Domain/PlanLimitReconciler.php',      // applies the stored plan after a billing or admin change
    ];

    /** `is_operator` array read or write: go through PlatformRoles::isOperator() for reads. */
    private const OPERATOR_FLAG_ALLOWED = [
        'core/Domain/PlatformRoles.php',                       // the helper
        'core/Persistence/',                                   // hydration and inserts
        'core/Http/Middleware/WidgetTokenAuthMiddleware.php',  // strips the flag from widget users (write)
        'cloud/TestAccount/',                                  // fixture resets
        'cloud/Demo/',                                         // fixture resets
    ];

    /**
     * Tier-name literals (free|starter|lite|pro|self-host) in comparisons: the
     * tier table itself only. Gates use the CloudPlanPolicy constants, so a
     * renamed or added tier cannot silently miss a gate. Billing, admin and
     * referral code compare via constants too, so they need no entry.
     */
    private const TIER_LITERAL_ALLOWED = [
        'cloud/CloudPlanPolicy.php', // defines the tier names and their ladder
    ];

    /**
     * `routing_mode` ('self-host' | 'cloud') is a deployment setting that shares
     * the spelling 'self-host' with the self-host tier. Lines that read it are
     * not tier comparisons and are excluded from the tier-literal rule only.
     */
    private const ROUTING_MODE_LINE = '/routing_?mode|MODE_SELF_HOST/i';

    /** Literal owner/admin/moderator comparisons: use AccountRoles::canAdminister/canModerate/isOwner. */
    private const ROLE_LITERAL_ALLOWED = [
        'core/Domain/AccountRoles.php',         // the helper
        'core/Http/Action/InviteAction.php',    // role vocabulary validation for invite payloads, not authorization
        'core/Http/Action/MemberAction.php',    // role vocabulary validation for member payloads, not authorization
        'core/Dev/DemoSeeder.php',              // dev tooling
    ];

    public function test_effective_plan_is_only_resolved_through_the_account_plan_resolver(): void
    {
        $this->assertNoViolations(
            '/\bEffectivePlan::(resolve|class)\b/',
            ['core/Domain/AccountPlanResolver.php', 'core/Domain/EffectivePlan.php'],
            'Use Votepit\\Domain\\AccountPlanResolver::forAccount() instead of EffectivePlan::resolve().',
        );
    }

    public function test_stored_plan_is_not_read_raw_outside_storage_and_billing(): void
    {
        $this->assertNoViolations(
            "/\\[\\s*'plan'\\s*\\]|->plan\\b(?!\\s*\\()/",
            self::RAW_PLAN_ALLOWED,
            'Read the enforced plan with AccountPlanResolver::forAccount($accountId, $user[, $loadedRow]), '
            . 'not the raw accounts.plan column.',
        );
    }

    public function test_resolved_plan_is_only_issued_by_the_account_plan_resolver(): void
    {
        $this->assertNoViolations(
            '/\bResolvedPlan::issue\s*\(/',
            self::RESOLVED_PLAN_ISSUE_ALLOWED,
            'Only AccountPlanResolver creates a ResolvedPlan: use AccountPlanResolver::forAccount($accountId, $user) '
            . 'for the enforced plan, or AccountPlanResolver::storedPlan() for billing and admin decisions.',
        );
    }

    public function test_resolved_plan_is_not_issued_in_tests_outside_the_factory(): void
    {
        $this->assertNoViolations(
            '/\bResolvedPlan::issue\s*\(/',
            self::RESOLVED_PLAN_ISSUE_TEST_ALLOWED,
            'Tests build a ResolvedPlan with Votepit\\Tests\\Support\\ResolvedPlanFactory::of(), not ResolvedPlan::issue().',
            'tests',
        );
    }

    public function test_stored_plan_bypass_is_only_used_for_billing_and_admin(): void
    {
        $this->assertNoViolations(
            '/\bAccountPlanResolver::storedPlan\s*\(/',
            self::STORED_PLAN_ALLOWED,
            'AccountPlanResolver::storedPlan() skips the operator rule and is reserved for billing and admin decisions; '
            . 'request-time gates must use AccountPlanResolver::forAccount().',
        );
    }

    public function test_operator_flag_is_only_read_through_platform_roles(): void
    {
        $this->assertNoViolations(
            "/\\[\\s*'is_operator'\\s*\\]/",
            self::OPERATOR_FLAG_ALLOWED,
            'Use Votepit\\Domain\\PlatformRoles::isOperator($user) instead of reading users.is_operator directly.',
        );
    }

    public function test_tier_names_are_compared_by_constant_not_by_literal(): void
    {
        $tier = '(?:free|starter|lite|pro|self-host)';
        $this->assertNoViolations(
            "/(?:===|!==|==|!=)\\s*'{$tier}'"
            . "|'{$tier}'\\s*(?:===|!==|==|!=)"
            . "|in_array\\([^;]*'{$tier}'"
            . "|\\bcase\\s+'{$tier}'\\s*:/",
            self::TIER_LITERAL_ALLOWED,
            'Compare plan tiers with the Votepit\\Cloud\\CloudPlanPolicy constants (FREE, STARTER, LITE, PRO, SELF_HOST), not string literals.',
            'src',
            self::ROUTING_MODE_LINE,
        );
    }

    public function test_role_strings_are_only_compared_through_account_roles(): void
    {
        $this->assertNoViolations(
            "/(?:===|!==)\\s*'(?:owner|admin|moderator)'|'(?:owner|admin|moderator)'\\s*(?:===|!==)"
            . "|in_array\\([^;]*'(?:owner|admin|moderator)'"
            . "|const\\s+\\w*ROLES\\s*=\\s*\\[\\s*'(?:owner|admin|moderator)'/",
            self::ROLE_LITERAL_ALLOWED,
            'Use Votepit\\Domain\\AccountRoles::canAdminister() (owner|admin), canModerate() (+moderator) or '
            . 'isOwner() instead of comparing role strings.',
        );
    }

    /**
     * @param list<string> $allowed
     * @param 'src'|'tests' $tree which tree to scan: production source or test code
     * @param ?string $ignoreLinePattern lines matching this are skipped (documented exemptions only)
     */
    private function assertNoViolations(
        string $pattern,
        array $allowed,
        string $hint,
        string $tree = 'src',
        ?string $ignoreLinePattern = null,
    ): void {
        $violations   = [];
        $filesScanned = 0;

        foreach ($this->sourceFiles($tree) as $label => $path) {
            if ($this->isAllowed($label, $allowed)) {
                continue;
            }
            $filesScanned++;
            $code = $this->codeWithoutComments($path);
            if ($ignoreLinePattern !== null) {
                $code = implode("\n", array_filter(
                    explode("\n", $code),
                    static fn (string $line): bool => preg_match($ignoreLinePattern, $line) !== 1,
                ));
            }
            if (preg_match($pattern, $code) === 1) {
                $violations[] = $label;
            }
        }

        self::assertGreaterThan(0, $filesScanned, 'Guard scanned no files — paths are wrong.');
        self::assertSame([], $violations, $hint . ' Violations: ' . implode(', ', $violations));
    }

    /**
     * @param list<string> $allowed
     */
    private function isAllowed(string $label, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if ($entry === $label) {
                return true;
            }
            if (str_ends_with($entry, '/') && str_starts_with($label, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> label ("core/Domain/X.php") => real path */
    private function sourceFiles(string $tree): array
    {
        $roots = $tree === 'tests'
            ? ['core' => __DIR__ . '/../../tests', 'cloud' => __DIR__ . '/../../../cloud/tests']
            : ['core' => __DIR__ . '/../../src', 'cloud' => __DIR__ . '/../../../cloud/src'];
        $files = [];
        foreach ($roots as $area => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $base     = (string) realpath($root);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path            = (string) realpath($file->getPathname());
                $relative        = str_replace('\\', '/', substr($path, strlen($base) + 1));
                $label           = $tree === 'tests' ? $area . '/tests/' . $relative : $area . '/' . $relative;
                $files[$label]   = $path;
            }
        }

        return $files;
    }

    private function codeWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
