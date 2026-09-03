<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TablePlanPolicy;

/**
 * Unit tests for the generic table-driven PlanPolicy.
 *
 * Uses synthetic tiers — core ships no real tier table. Pins the mapping of
 * table cells to the interface contract (null = unlimited = PHP_INT_MAX) and
 * the fail-safe behaviour for an unrecognized plan value: every getter must
 * deny, never silently allow.
 */
final class TablePlanPolicyTest extends TestCase
{
    private function policy(): TablePlanPolicy
    {
        return new TablePlanPolicy([
            'small' => [
                'board_limit'     => 1,
                'member_limit'    => 2,
                'agent_api'       => false,
                'visibilities'    => ['public'],
                'branding_fields' => [],
            ],
            'large' => [
                'board_limit'     => null,
                'member_limit'    => null,
                'agent_api'       => true,
                'visibilities'    => PlanPolicy::ALL_VISIBILITIES,
                'branding_fields' => ['secondary_color', 'logo_url'],
            ],
        ], 'small');
    }

    public function test_initial_plan_is_returned(): void
    {
        self::assertSame('small', $this->policy()->initialPlan());
    }

    public function test_initial_plan_must_be_a_configured_tier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TablePlanPolicy(['a' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => ['public'], 'branding_fields' => []]], 'missing');
    }

    public function test_limited_tier_numbers(): void
    {
        $p = $this->policy();
        self::assertTrue($p->isKnownPlan('small'));
        self::assertSame(1, $p->boardLimit('small'));
        self::assertSame(2, $p->memberLimit('small'));
        self::assertFalse($p->agentApiAllowed('small'));
        self::assertSame(['public'], $p->allowedVisibilities('small'));
        self::assertTrue($p->isVisibilityAllowed('small', 'public'));
        self::assertFalse($p->isVisibilityAllowed('small', 'unlisted'));
        self::assertFalse($p->isVisibilityAllowed('small', 'private'));
        self::assertSame([], $p->allowedBrandingFields('small'));
        self::assertFalse($p->isBrandingFieldAllowed('small', 'logo_url'));
    }

    public function test_null_limit_means_unlimited(): void
    {
        $p = $this->policy();
        self::assertTrue($p->isKnownPlan('large'));
        self::assertSame(PHP_INT_MAX, $p->boardLimit('large'));
        self::assertSame(PHP_INT_MAX, $p->memberLimit('large'));
        self::assertTrue($p->agentApiAllowed('large'));
        self::assertSame(PlanPolicy::ALL_VISIBILITIES, $p->allowedVisibilities('large'));
        self::assertTrue($p->isVisibilityAllowed('large', 'private'));
        self::assertSame(['secondary_color', 'logo_url'], $p->allowedBrandingFields('large'));
        self::assertTrue($p->isBrandingFieldAllowed('large', 'logo_url'));
        self::assertFalse($p->isBrandingFieldAllowed('large', 'hide_badge'));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownPlans(): iterable
    {
        yield 'empty string' => [''];
        yield 'typo'         => ['smal'];
        yield 'case'         => ['Small'];
        yield 'garbage'      => ['enterprise-unlimited'];
    }

    #[DataProvider('unknownPlans')]
    public function test_unknown_plan_fails_safe(string $plan): void
    {
        $p = $this->policy();
        self::assertFalse($p->isKnownPlan($plan));
        self::assertSame(0, $p->boardLimit($plan));
        self::assertSame(0, $p->memberLimit($plan));
        self::assertFalse($p->agentApiAllowed($plan));
        self::assertSame(['public'], $p->allowedVisibilities($plan));
        self::assertTrue($p->isVisibilityAllowed($plan, 'public'));
        self::assertFalse($p->isVisibilityAllowed($plan, 'unlisted'));
        self::assertFalse($p->isVisibilityAllowed($plan, 'private'));
        self::assertSame([], $p->allowedBrandingFields($plan));
        foreach (PlanPolicy::ALL_BRANDING_FIELDS as $field) {
            self::assertFalse($p->isBrandingFieldAllowed($plan, $field));
        }
    }
}
