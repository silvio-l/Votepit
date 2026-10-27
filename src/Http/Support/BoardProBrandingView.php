<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Domain\PlanPolicy;
use Votepit\Security\BoardProBrandingValidator;

/**
 * The Pro branding values a PUBLIC read path may expose (migration 0093).
 *
 * Single source for BoardHomeAction and BoardRoadmapAction. Stored values are
 * re-validated here and only the ones the account's CURRENT plan includes are
 * applied; everything else falls back to the Votepit default. A downgrade
 * therefore keeps the stored values (kept, not applied) and a re-upgrade
 * applies them again without any write.
 */
final class BoardProBrandingView
{
    /**
     * @param array<string, mixed> $board
     * @return array{
     *     typography: string,
     *     shape: string,
     *     density: string,
     *     wording: array<string, array<string, string>>,
     *     header_links: list<array{url: string, label: string}>,
     *     roadmap_hidden: bool
     * }
     */
    public static function applied(array $board, PlanPolicy $policy, string $plan): array
    {
        $presets = $policy->featureAllowed($plan, 'typography_presets');
        $wording = $policy->featureAllowed($plan, 'custom_wording');
        $links   = $policy->featureAllowed($plan, 'header_links_sections');

        return [
            'typography'     => $presets ? (BoardProBrandingValidator::typography($board['typography_preset'] ?? null) ?? 'default') : 'default',
            'shape'          => $presets ? (BoardProBrandingValidator::shape($board['shape_style'] ?? null) ?? 'rounded') : 'rounded',
            'density'        => $presets ? (BoardProBrandingValidator::density($board['density'] ?? null) ?? 'comfortable') : 'comfortable',
            'wording'        => $wording ? BoardProBrandingValidator::decodeWording(self::text($board, 'wording_json')) : [],
            'header_links'   => $links ? BoardProBrandingValidator::decodeHeaderLinks(self::text($board, 'header_links_json')) : [],
            'roadmap_hidden' => $links && (bool) ($board['roadmap_hidden'] ?? false),
        ];
    }

    /** @param array<string, mixed> $board */
    private static function text(array $board, string $key): ?string
    {
        $value = $board[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
