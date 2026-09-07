<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\UnrestrictedPlanPolicy;

/**
 * The Community Edition default policy must gate nothing, whatever value
 * happens to sit in accounts.plan.
 */
final class UnrestrictedPlanPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function plans(): iterable
    {
        yield 'seeded default' => [UnrestrictedPlanPolicy::DEFAULT_PLAN];
        yield 'empty'          => [''];
        yield 'legacy value'   => ['some-former-tier'];
    }

    public function test_initial_plan_matches_seeded_default_account(): void
    {
        self::assertSame('self-host', (new UnrestrictedPlanPolicy())->initialPlan());
    }

    #[DataProvider('plans')]
    public function test_everything_is_allowed(string $plan): void
    {
        $p = new UnrestrictedPlanPolicy();
        self::assertTrue($p->isKnownPlan($plan));
        self::assertSame(PHP_INT_MAX, $p->boardLimit($plan));
        self::assertSame(PHP_INT_MAX, $p->memberLimit($plan));
        self::assertTrue($p->agentApiAllowed($plan));
        self::assertSame(PlanPolicy::ALL_VISIBILITIES, $p->allowedVisibilities($plan));
        self::assertSame(PlanPolicy::ALL_BRANDING_FIELDS, $p->allowedBrandingFields($plan));
        foreach (PlanPolicy::ALL_VISIBILITIES as $v) {
            self::assertTrue($p->isVisibilityAllowed($plan, $v));
        }
        foreach (PlanPolicy::ALL_BRANDING_FIELDS as $f) {
            self::assertTrue($p->isBrandingFieldAllowed($plan, $f));
        }
        self::assertFalse($p->isVisibilityAllowed($plan, 'secret'));
        self::assertFalse($p->isBrandingFieldAllowed($plan, 'custom_css'));
    }
}
