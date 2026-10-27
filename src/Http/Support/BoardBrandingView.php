<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Votepit\Domain\BoardCoverPattern;
use Votepit\Domain\BoardThemeMode;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Action\BoardAssetAction;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Security\BoardLinkValidator;

/**
 * The board branding that every board-scoped page applies outside the board
 * home: colours and logo (BrandResolver: uploaded logo > legacy link), the
 * cover pattern, the "Powered by" badge and the footer link/note. Idea detail, submit, edit and member profile payloads use
 * this one place, so a board looks the same on every page.
 *
 * Each field is re-checked against the account's CURRENT effective plan. After
 * a downgrade the stored values stay and are simply not applied; a re-upgrade
 * applies them again without any write (Q19=a). Callers resolve the plan the
 * same way BoardHomeAction does (EffectivePlan with the operator bypass).
 */
final class BoardBrandingView
{
    /**
     * @param array<string, mixed> $board
     * @return array{
     *     primary_color: ?string,
     *     secondary_color: ?string,
     *     logo_url: ?string,
     *     logo_public_id: ?string,
     *     logo_allowed: bool,
     *     wordmark_public_id: ?string,
     *     wordmark_ink: ?float,
     *     wordmark_light_public_id: ?string,
     *     wordmark_light_ink: ?float,
     *     cover_pattern: ?string,
     *     theme_mode: string,
     *     show_badge: bool,
     *     back_link: ?array{url: string, label: string},
     *     footer_note: ?string
     * }
     */
    public static function visual(array $board, string $plan, PlanPolicy $policy, BrandResolver $brands): array
    {
        $brand = $brands->forBoard($board, $plan);

        $badgeHideAllowed   = $policy->isBrandingFieldAllowed($plan, 'hide_badge');
        $badgeHideRequested = (bool) ($board['hide_badge'] ?? false);

        $linkAllowed   = $policy->isBrandingFieldAllowed($plan, 'back_link_footer');
        $rawBackUrl    = is_string($board['back_link_url'] ?? null) ? $board['back_link_url'] : '';
        $rawBackLabel  = is_string($board['back_link_label'] ?? null) ? $board['back_link_label'] : '';
        $rawFooterNote = is_string($board['footer_note'] ?? null) ? $board['footer_note'] : '';
        $backLinkUrl   = $rawBackUrl !== '' ? BoardLinkValidator::backLinkUrl($rawBackUrl) : null;
        $backLinkLabel = $rawBackLabel !== '' ? BoardLinkValidator::backLinkLabel($rawBackLabel) : null;

        return [
            'primary_color'   => $brand->primaryColor,
            'secondary_color' => $brand->secondaryColor,
            'logo_url'        => $brand->logoUrl,
            // Uploaded logo (opaque public id, plan-gated) and whether the plan
            // includes a logo at all: a Lite+ board without a logo shows initials.
            'logo_public_id'  => $brand->logoPublicId,
            'logo_allowed'    => $policy->isBrandingFieldAllowed($plan, 'logo_url'),
            // Topbar wordmark (BR-LOGO2): same gate as the logo; its contrast metadata
            // decides the light plate. Both come from the board row (one subselect each).
            'wordmark_public_id' => $policy->isBrandingFieldAllowed($plan, 'logo_url')
                && is_string($board['wordmark_public_id'] ?? null) ? $board['wordmark_public_id'] : null,
            'wordmark_ink'       => $policy->isBrandingFieldAllowed($plan, 'logo_url')
                && is_string($board['wordmark_public_id'] ?? null) && is_numeric($board['wordmark_ink'] ?? null)
                ? (float) $board['wordmark_ink'] : null,
            // Optional light wordmark for dark topbars (phase 4): same gate, own subselects.
            'wordmark_light_public_id' => $policy->isBrandingFieldAllowed($plan, 'logo_url')
                && is_string($board['wordmark_light_public_id'] ?? null) ? $board['wordmark_light_public_id'] : null,
            'wordmark_light_ink'       => $policy->isBrandingFieldAllowed($plan, 'logo_url')
                && is_string($board['wordmark_light_public_id'] ?? null) && is_numeric($board['wordmark_light_ink'] ?? null)
                ? (float) $board['wordmark_light_ink'] : null,
            'cover_pattern'   => $policy->isBrandingFieldAllowed($plan, 'cover_pattern')
                ? BoardCoverPattern::normalize($board['cover_pattern'] ?? null)
                : null,
            // Display mode (P-26): not plan-gated (owner answer 45a).
            'theme_mode'      => BoardThemeMode::normalize($board['theme_mode'] ?? null),
            'show_badge'      => !($badgeHideAllowed && $badgeHideRequested),
            'back_link'       => $linkAllowed && $backLinkUrl !== null && $backLinkLabel !== null
                ? ['url' => $backLinkUrl, 'label' => $backLinkLabel]
                : null,
            'footer_note'     => $linkAllowed && $rawFooterNote !== '' ? BoardLinkValidator::footerNote($rawFooterNote) : null,
        ];
    }

    /**
     * Public ids and contrast metadata of the board's images (cover_wide, cover_phone,
     * favicon, og, logo, wordmark), each re-checked against the CURRENT plan. One
     * listing serves both maps. Same rule on every board-scoped payload.
     *
     * @param array<string, mixed> $board
     * @return array{ids: array<string, string>, ink: array<string, float>}
     *         ids: variant => public id; ink: variant => mean relative luminance (logo slots only matter)
     */
    public static function assetPayload(array $board, string $plan, PlanPolicy $policy, ?BoardAssetRepository $assets): array
    {
        $payload = ['ids' => [], 'ink' => []];
        if (!$assets instanceof \Votepit\Persistence\BoardAssetRepository) {
            return $payload;
        }

        foreach ($assets->listForBoard((int) $board['id']) as $asset) {
            if (!$policy->isBrandingFieldAllowed($plan, BoardAssetAction::gateForVariant($asset['variant']))) {
                continue;
            }
            $payload['ids'][$asset['variant']] = $asset['public_id'];
            if ($asset['ink_luminance'] !== null) {
                $payload['ink'][$asset['variant']] = $asset['ink_luminance'];
            }
        }

        return $payload;
    }
}
