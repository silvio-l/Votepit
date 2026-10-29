<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Tests\Support\ResolvedPlanFactory;

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
                'roadmap_gantt'   => false,
            ],
            'large' => [
                'board_limit'     => null,
                'member_limit'    => null,
                'agent_api'       => true,
                'visibilities'    => PlanPolicy::ALL_VISIBILITIES,
                'branding_fields' => ['secondary_color', 'logo_url'],
                'roadmap_gantt'   => true,
                'widget'          => true,
                'chat_notifications' => true,
                'issue_tracker'   => true,
                'anonymous_submission' => true,
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
        new TablePlanPolicy(['a' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false]], 'missing');
    }

    public function test_limited_tier_numbers(): void
    {
        $p = $this->policy();
        self::assertTrue($p->isKnownPlan('small'));
        self::assertSame(1, $p->boardLimit(ResolvedPlanFactory::of('small')));
        self::assertSame(2, $p->memberLimit(ResolvedPlanFactory::of('small')));
        self::assertFalse($p->agentApiAllowed(ResolvedPlanFactory::of('small')));
        self::assertSame(['public'], $p->allowedVisibilities(ResolvedPlanFactory::of('small')));
        self::assertTrue($p->isVisibilityAllowed(ResolvedPlanFactory::of('small'), 'public'));
        self::assertFalse($p->isVisibilityAllowed(ResolvedPlanFactory::of('small'), 'unlisted'));
        self::assertFalse($p->isVisibilityAllowed(ResolvedPlanFactory::of('small'), 'private'));
        self::assertSame([], $p->allowedBrandingFields(ResolvedPlanFactory::of('small')));
        self::assertFalse($p->isBrandingFieldAllowed(ResolvedPlanFactory::of('small'), 'logo_url'));
        self::assertFalse($p->roadmapGanttAllowed(ResolvedPlanFactory::of('small')));
        // 'small' omits the optional chat_notifications key → denied (fail-safe default).
        self::assertFalse($p->chatNotificationsAllowed(ResolvedPlanFactory::of('small')));
    }

    public function test_null_limit_means_unlimited(): void
    {
        $p = $this->policy();
        self::assertTrue($p->isKnownPlan('large'));
        self::assertSame(PHP_INT_MAX, $p->boardLimit(ResolvedPlanFactory::of('large')));
        self::assertSame(PHP_INT_MAX, $p->memberLimit(ResolvedPlanFactory::of('large')));
        self::assertTrue($p->agentApiAllowed(ResolvedPlanFactory::of('large')));
        self::assertSame(PlanPolicy::ALL_VISIBILITIES, $p->allowedVisibilities(ResolvedPlanFactory::of('large')));
        self::assertTrue($p->isVisibilityAllowed(ResolvedPlanFactory::of('large'), 'private'));
        self::assertSame(['secondary_color', 'logo_url'], $p->allowedBrandingFields(ResolvedPlanFactory::of('large')));
        self::assertTrue($p->isBrandingFieldAllowed(ResolvedPlanFactory::of('large'), 'logo_url'));
        self::assertFalse($p->isBrandingFieldAllowed(ResolvedPlanFactory::of('large'), 'hide_badge'));
        self::assertTrue($p->roadmapGanttAllowed(ResolvedPlanFactory::of('large')));
        self::assertTrue($p->chatNotificationsAllowed(ResolvedPlanFactory::of('large')));
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
        self::assertSame(0, $p->boardLimit(ResolvedPlanFactory::of($plan)));
        self::assertSame(0, $p->memberLimit(ResolvedPlanFactory::of($plan)));
        self::assertFalse($p->agentApiAllowed(ResolvedPlanFactory::of($plan)));
        self::assertSame(['public'], $p->allowedVisibilities(ResolvedPlanFactory::of($plan)));
        self::assertTrue($p->isVisibilityAllowed(ResolvedPlanFactory::of($plan), 'public'));
        self::assertFalse($p->isVisibilityAllowed(ResolvedPlanFactory::of($plan), 'unlisted'));
        self::assertFalse($p->isVisibilityAllowed(ResolvedPlanFactory::of($plan), 'private'));
        self::assertSame([], $p->allowedBrandingFields(ResolvedPlanFactory::of($plan)));
        foreach (PlanPolicy::ALL_BRANDING_FIELDS as $field) {
            self::assertFalse($p->isBrandingFieldAllowed(ResolvedPlanFactory::of($plan), $field));
        }
        self::assertFalse($p->roadmapGanttAllowed(ResolvedPlanFactory::of($plan)));
        self::assertFalse($p->chatNotificationsAllowed(ResolvedPlanFactory::of($plan)));
    }

    public function test_widget_is_gated_per_tier_and_defaults_to_denied(): void
    {
        $p = $this->policy();

        // 'small' omits the optional `widget` key entirely → denied.
        self::assertFalse($p->widgetAllowed(ResolvedPlanFactory::of('small')));
        self::assertTrue($p->widgetAllowed(ResolvedPlanFactory::of('large')));
        self::assertFalse($p->widgetAllowed(ResolvedPlanFactory::of('no-such-plan')));
    }

    public function test_issue_tracker_is_gated_per_tier_and_defaults_to_denied(): void
    {
        $p = $this->policy();

        // 'small' omits the optional `issue_tracker` key entirely → denied.
        self::assertFalse($p->issueTrackerAllowed(ResolvedPlanFactory::of('small')));
        self::assertTrue($p->issueTrackerAllowed(ResolvedPlanFactory::of('large')));
        self::assertFalse($p->issueTrackerAllowed(ResolvedPlanFactory::of('no-such-plan')));
    }

    public function test_anonymous_submission_is_gated_per_tier_and_defaults_to_denied(): void
    {
        $p = $this->policy();

        self::assertFalse($p->anonymousSubmissionAllowed(ResolvedPlanFactory::of('small')));
        self::assertTrue($p->anonymousSubmissionAllowed(ResolvedPlanFactory::of('large')));
        self::assertFalse($p->anonymousSubmissionAllowed(ResolvedPlanFactory::of('no-such-plan')));
    }

    public function test_feature_flags_follow_the_tier_row(): void
    {
        $p = new TablePlanPolicy([
            'small' => ['board_limit' => 1, 'member_limit' => 2, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false],
            'large' => [
                'board_limit' => null, 'member_limit' => null, 'agent_api' => true,
                'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => ['secondary_color'],
                'roadmap_gantt' => true, 'widget' => true, 'features' => ['cover_upload'],
            ],
        ], 'small', 'large', ['small', 'large']);

        self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('large'), 'secondary_color'));
        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('large'), 'logo_url'));
        self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('large'), 'visibility_private'));
        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('small'), 'visibility_private'));
        self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('large'), 'team_members'));
        // 'small' allows 2 members: more than the owner alone, so team_members is on.
        self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('small'), 'team_members'));
        self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('large'), 'cover_upload'));
        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('small'), 'cover_upload'));
    }

    public function test_unknown_plan_or_feature_is_never_allowed(): void
    {
        $p = $this->policy();

        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('no-such-plan'), 'roadmap_gantt'));
        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('large'), 'not_a_feature'));
    }

    public function test_lowest_plan_follows_the_explicit_ladder_not_the_tier_order(): void
    {
        // 'large' is declared first on purpose: the ladder, not the array order, decides.
        $p = new TablePlanPolicy([
            'large' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS, 'roadmap_gantt' => true],
            'small' => ['board_limit' => 1, 'member_limit' => 1, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => ['intro'], 'roadmap_gantt' => false],
        ], 'small', 'large', ['small', 'large']);

        self::assertSame('small', $p->lowestPlanAllowing('intro'));
        self::assertSame('large', $p->lowestPlanAllowing('hide_badge'));
        self::assertNull($p->lowestPlanAllowing('not_a_feature'));
    }

    public function test_ladder_must_list_every_tier_exactly_once(): void
    {
        $tiers = [
            'small' => ['board_limit' => 1, 'member_limit' => 1, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false],
            'large' => ['board_limit' => null, 'member_limit' => null, 'agent_api' => true, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => true],
        ];

        $this->expectException(\InvalidArgumentException::class);
        new TablePlanPolicy($tiers, 'small', 'large', ['small', 'small']);
    }

    public function test_ladder_with_an_unknown_tier_is_rejected(): void
    {
        $tiers = [
            'small' => ['board_limit' => 1, 'member_limit' => 1, 'agent_api' => false, 'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false],
        ];

        $this->expectException(\InvalidArgumentException::class);
        new TablePlanPolicy($tiers, 'small', 'small', ['small', 'ghost']);
    }
}
