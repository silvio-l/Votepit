<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Votepit\Domain\Entitlements;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Domain\UnrestrictedPlanPolicy;

/**
 * The entitlements payload (P-38): one answer per FEATURES key, built only
 * from PlanPolicy, plus can_change_plan (owner AND billing).
 */
final class EntitlementsTest extends TestCase
{
    private function tiered(): TablePlanPolicy
    {
        return new TablePlanPolicy([
            'free' => [
                'board_limit' => 1, 'member_limit' => 1, 'agent_api' => false,
                'visibilities' => ['public'], 'branding_fields' => [], 'roadmap_gantt' => false,
                'features' => ['board_header'],
            ],
            'lite' => [
                'board_limit' => 5, 'member_limit' => 5, 'agent_api' => false,
                'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => ['secondary_color', 'logo_url', 'intro'],
                'roadmap_gantt' => true, 'widget' => true,
                'features' => ['board_header', 'cover_upload'],
            ],
            'pro' => [
                'board_limit' => null, 'member_limit' => null, 'agent_api' => true,
                'visibilities' => PlanPolicy::ALL_VISIBILITIES, 'branding_fields' => PlanPolicy::ALL_BRANDING_FIELDS,
                'roadmap_gantt' => true, 'widget' => true, 'issue_tracker' => true,
                'features' => PlanPolicy::FEATURES,
            ],
        ], 'free', 'pro', ['free', 'lite', 'pro']);
    }

    public function test_payload_has_one_entry_per_feature_key(): void
    {
        $payload = Entitlements::forAccount($this->tiered(), 'lite', 'lite', 'owner', true);

        self::assertSame(PlanPolicy::FEATURES, array_keys($payload['features']));
    }

    public function test_locked_feature_names_the_lowest_plan_that_includes_it(): void
    {
        $payload = Entitlements::forAccount($this->tiered(), 'free', 'free', 'moderator', false);

        self::assertFalse($payload['features']['logo_url']['allowed']);
        self::assertSame('lite', $payload['features']['logo_url']['required_plan']);
        self::assertFalse($payload['features']['issue_tracker']['allowed']);
        self::assertSame('pro', $payload['features']['issue_tracker']['required_plan']);
        self::assertFalse($payload['features']['cover_upload']['allowed']);
        self::assertSame('lite', $payload['features']['cover_upload']['required_plan']);
    }

    public function test_allowed_feature_has_no_required_plan(): void
    {
        $payload = Entitlements::forAccount($this->tiered(), 'lite', 'lite', 'owner', true);

        self::assertTrue($payload['features']['logo_url']['allowed']);
        self::assertNull($payload['features']['logo_url']['required_plan']);
        self::assertTrue($payload['features']['team_members']['allowed']);
    }

    public function test_team_members_is_a_limit_above_one(): void
    {
        self::assertFalse(Entitlements::forAccount($this->tiered(), 'free', 'free', 'owner', true)['features']['team_members']['allowed']);
        self::assertTrue(Entitlements::forAccount($this->tiered(), 'pro', 'pro', 'owner', true)['features']['team_members']['allowed']);
    }

    public function test_can_change_plan_needs_owner_role_and_billing(): void
    {
        $policy = $this->tiered();

        self::assertTrue(Entitlements::forAccount($policy, 'lite', 'lite', 'owner', true)['can_change_plan']);
        self::assertFalse(Entitlements::forAccount($policy, 'lite', 'lite', 'owner', false)['can_change_plan']);
        self::assertFalse(Entitlements::forAccount($policy, 'lite', 'lite', 'admin', true)['can_change_plan']);
        self::assertFalse(Entitlements::forAccount($policy, 'lite', 'lite', 'moderator', true)['can_change_plan']);
        self::assertFalse(Entitlements::forAccount($policy, 'lite', 'lite', null, true)['can_change_plan']);
    }

    public function test_current_plan_is_the_stored_plan_while_features_follow_the_enforced_plan(): void
    {
        $payload = Entitlements::forAccount($this->tiered(), 'free', 'pro', 'owner', true);

        self::assertSame('free', $payload['current_plan']);
        self::assertTrue($payload['features']['hide_badge']['allowed']);
    }

    public function test_unknown_plan_locks_everything_and_points_at_the_lowest_paid_tier(): void
    {
        $payload = Entitlements::forAccount($this->tiered(), 'garbage', 'garbage', 'owner', true);

        self::assertFalse($payload['features']['board_header']['allowed']);
        self::assertSame('free', $payload['features']['board_header']['required_plan']);
    }

    public function test_community_reports_everything_allowed_without_a_required_plan(): void
    {
        $payload = Entitlements::forAccount(new UnrestrictedPlanPolicy(), 'self-host', 'self-host', 'owner', false);

        foreach ($payload['features'] as $feature => $entry) {
            self::assertTrue($entry['allowed'], $feature);
            self::assertNull($entry['required_plan'], $feature);
        }
        self::assertFalse($payload['can_change_plan']);
    }
}
