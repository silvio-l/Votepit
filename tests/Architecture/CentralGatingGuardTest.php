<?php

declare(strict_types=1);

namespace Votepit\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guard for plan, operator and role gating. Each rule names the
 * central API to use and an allow-list of files where the raw pattern is
 * legitimate. Comments are stripped before matching, so docblocks may mention
 * anything. Scans core/src and, when present (private monorepo), cloud/src.
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

    /** PlanPolicy gate methods: the file must hold a plan from AccountPlanResolver, or be a view/helper fed one. */
    private const GATE_METHODS = 'featureAllowed|isBrandingFieldAllowed|isVisibilityAllowed|allowedVisibilities|'
        . 'allowedBrandingFields|defaultVisibility|boardLimit|memberLimit|widgetAllowed|agentApiAllowed|'
        . 'issueTrackerAllowed|chatNotificationsAllowed|anonymousSubmissionAllowed|roadmapGanttAllowed|aiFeaturesAllowed';

    private const GATE_ALLOWED_WITHOUT_RESOLVER = [
        'core/Http/Support/BoardBrandingView.php',    // receives an already-resolved $plan from its caller
        'core/Http/Support/BoardProBrandingView.php', // same
        'core/Domain/Entitlements.php',               // same (enforced plan passed in)
        'core/Domain/PlanPolicy.php',                 // interface declarations
        'core/Domain/TablePlanPolicy.php',            // implementation
        'core/Domain/UnrestrictedPlanPolicy.php',     // implementation
        'core/Http/Action/SignupAccountAction.php',   // new account: initialPlan(), no stored plan yet
        'cloud/CloudPlanPolicy.php',                  // implementation
        'cloud/Admin/',                               // admin panels and metrics report the stored plan
        'cloud/Billing/',                             // billing screens and provider decisions on the stored plan
    ];

    /** `is_operator` array read or write: go through PlatformRoles::isOperator() for reads. */
    private const OPERATOR_FLAG_ALLOWED = [
        'core/Domain/PlatformRoles.php',                       // the helper
        'core/Persistence/',                                   // hydration and inserts
        'core/Http/Middleware/WidgetTokenAuthMiddleware.php',  // strips the flag from widget users (write)
        'cloud/TestAccount/',                                  // fixture resets
        'cloud/Demo/',                                         // fixture resets
    ];

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

    public function test_plan_gate_methods_are_only_used_where_the_resolver_is(): void
    {
        $this->assertNoViolations(
            '/(?:->|::)(?:' . self::GATE_METHODS . ')\s*\(/',
            self::GATE_ALLOWED_WITHOUT_RESOLVER,
            'Gate with a plan from AccountPlanResolver::forAccount(); a file that calls PlanPolicy gate methods '
            . 'must reference AccountPlanResolver (inject it) so the operator-member rule applies.',
            requireResolverReference: true,
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
     * @param list<string>                        $allowed
     * @param bool                                $requireResolverReference when true, a non-allow-listed file
     *                                                                       must also contain the token
     *                                                                       "AccountPlanResolver"
     */
    private function assertNoViolations(
        string $pattern,
        array $allowed,
        string $hint,
        bool $requireResolverReference = false,
    ): void {
        $violations   = [];
        $filesScanned = 0;

        foreach ($this->sourceFiles() as $label => $path) {
            if ($this->isAllowed($label, $allowed)) {
                continue;
            }
            $filesScanned++;
            $code = $this->codeWithoutComments($path);
            $hit  = preg_match($pattern, $code) === 1;
            if ($requireResolverReference) {
                $hit = $hit && !str_contains($code, 'AccountPlanResolver');
            }
            if ($hit) {
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
    private function sourceFiles(): array
    {
        $roots = ['core' => __DIR__ . '/../../src', 'cloud' => __DIR__ . '/../../../cloud/src'];
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
                $files[$area . '/' . $relative] = $path;
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
