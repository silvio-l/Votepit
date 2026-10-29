<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Tests\Support\ResolvedPlanFactory;

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
        self::assertSame(PHP_INT_MAX, $p->boardLimit(ResolvedPlanFactory::of($plan)));
        self::assertSame(PHP_INT_MAX, $p->memberLimit(ResolvedPlanFactory::of($plan)));
        self::assertTrue($p->agentApiAllowed(ResolvedPlanFactory::of($plan)));
        self::assertSame(PlanPolicy::ALL_VISIBILITIES, $p->allowedVisibilities(ResolvedPlanFactory::of($plan)));
        self::assertSame(PlanPolicy::ALL_BRANDING_FIELDS, $p->allowedBrandingFields(ResolvedPlanFactory::of($plan)));
        foreach (PlanPolicy::ALL_VISIBILITIES as $v) {
            self::assertTrue($p->isVisibilityAllowed(ResolvedPlanFactory::of($plan), $v));
        }
        foreach (PlanPolicy::ALL_BRANDING_FIELDS as $f) {
            self::assertTrue($p->isBrandingFieldAllowed(ResolvedPlanFactory::of($plan), $f));
        }
        self::assertFalse($p->isVisibilityAllowed(ResolvedPlanFactory::of($plan), 'secret'));
        self::assertFalse($p->isBrandingFieldAllowed(ResolvedPlanFactory::of($plan), 'custom_css'));
    }

    /**
     * Same as every other capability: the Roadmap Gantt extension is
     * gated by its own absence from core (ADR 0003), not by a flag here —
     * once the extension package is installed, it behaves like any other
     * unrestricted Community feature.
     */
    #[DataProvider('plans')]
    public function test_roadmap_gantt_is_allowed(string $plan): void
    {
        self::assertTrue((new UnrestrictedPlanPolicy())->roadmapGanttAllowed(ResolvedPlanFactory::of($plan)));
    }

    /** Slack/Discord chat notifications: self-host gets them unrestricted (decision 1a). */
    #[DataProvider('plans')]
    public function test_chat_notifications_are_allowed(string $plan): void
    {
        self::assertTrue((new UnrestrictedPlanPolicy())->chatNotificationsAllowed(ResolvedPlanFactory::of($plan)));
    }

    #[DataProvider('plans')]
    public function test_widget_is_allowed(string $plan): void
    {
        self::assertTrue((new UnrestrictedPlanPolicy())->widgetAllowed(ResolvedPlanFactory::of($plan)));
    }

    /** Linear/GitHub issue links: self-host gets them unrestricted (issue 19, decision 1a). */
    #[DataProvider('plans')]
    public function test_issue_tracker_is_allowed(string $plan): void
    {
        self::assertTrue((new UnrestrictedPlanPolicy())->issueTrackerAllowed(ResolvedPlanFactory::of($plan)));
    }

    #[DataProvider('plans')]
    public function test_anonymous_submission_is_allowed(string $plan): void
    {
        self::assertTrue((new UnrestrictedPlanPolicy())->anonymousSubmissionAllowed(ResolvedPlanFactory::of($plan)));
    }

    public function test_every_feature_is_allowed_and_nothing_needs_an_upgrade(): void
    {
        $p = new UnrestrictedPlanPolicy();

        foreach (PlanPolicy::FEATURES as $feature) {
            self::assertTrue($p->featureAllowed(ResolvedPlanFactory::of('self-host'), $feature), $feature);
            self::assertNull($p->lowestPlanAllowing($feature), $feature);
        }
        self::assertFalse($p->featureAllowed(ResolvedPlanFactory::of('self-host'), 'not_a_feature'));
    }
}
