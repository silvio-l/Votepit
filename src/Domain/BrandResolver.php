<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Votepit\Persistence\BoardAssetRepository;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Security\BrandingValidator;

/**
 * Single place that decides which branding a board shows. Branding is a
 * per-board setting only (owner 2026-10-25: there is no account-level
 * branding); each board optionally sets its own colours and logo.
 *
 * Per field, all server-side: the board's own validated value, then the
 * per-field plan gate — `secondary_color` and the logo are dropped when the
 * plan does not include them (values stay stored, Q19=a). The primary colour
 * is ungated, as before.
 *
 * Logo (single source for every board page, the widget and the topbar):
 * uploaded logo > legacy `logo_url` link > none. The legacy link is never
 * fetched server-side; it is only displayed, and only while no upload exists.
 *
 * Read path: format-only validation (BrandingValidator::color / logoUrl), the
 * same as BoardHomeAction always did. Contrast is a WRITE-time gate, so an
 * already-saved value is never silently dropped on read. Callers must pass
 * the account-level plan (EffectivePlan without a viewer bypass for public
 * readers), never a client-supplied plan.
 */
final readonly class BrandResolver
{
    public function __construct(
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private ?BoardAssetRepository $assets = null,
    ) {}

    /**
     * Resolves a board row (as returned by BoardRepository, with `id` and
     * `account_id`) for the given effective plan.
     *
     * @param array<string, mixed> $board
     */
    public function forBoard(array $board, string $plan): ResolvedBrand
    {
        return self::compute($board, $plan, $this->planPolicy, $this->uploadedLogoId($board));
    }

    /**
     * Public id of the board's uploaded logo rendition, or null (no upload or no asset store).
     *
     * @param array<string, mixed> $board
     */
    private function uploadedLogoId(array $board): ?string
    {
        // Board rows from the BoardRepository selects carry the id already (one round trip).
        if (array_key_exists('logo_public_id', $board)) {
            return is_string($board['logo_public_id']) ? $board['logo_public_id'] : null;
        }
        if (!$this->assets instanceof \Votepit\Persistence\BoardAssetRepository || !is_int($board['id'] ?? null)) {
            return null;
        }

        return $this->assets->publicIdForVariant($board['id'], BoardAssetProcessor::VARIANT_LOGO);
    }

    /**
     * Anonymous-reader plan of the board's account (no viewer bypass). Used by
     * the widget and the mail paths, which have no logged-in user to bypass for.
     *
     * @param array<string, mixed> $board
     */
    public function accountPlanForBoard(array $board): string
    {
        return $this->plans->forAccount((int) ($board['account_id'] ?? 0), null);
    }

    /**
     * Colour tokens the embeddable widget may follow (P-32 widget theming, Pro).
     * Null when the account plan does not include `widget_theming`; the widget
     * then keeps the default Votepit look. Values are validated colours only.
     *
     * @param array<string, mixed> $board
     * @return array{primary_color?: string, secondary_color?: string}|null
     */
    public function widgetTheme(array $board): ?array
    {
        $plan = $this->accountPlanForBoard($board);
        if (!$this->planPolicy->featureAllowed($plan, 'widget_theming')) {
            return null;
        }

        $brand = $this->forBoard($board, $plan);
        $theme = [];
        if ($brand->primaryColor !== null) {
            $theme['primary_color'] = $brand->primaryColor;
        }

        if ($brand->secondaryColor !== null) {
            $theme['secondary_color'] = $brand->secondaryColor;
        }

        return $theme;
    }

    /**
     * Pure resolution logic (no I/O), used by forBoard() and the unit tests.
     *
     * @param array<string, mixed> $board board row (primary_color, secondary_color, logo_url)
     * @param string|null $uploadedLogoId public id of the uploaded logo rendition, if any
     */
    public static function compute(array $board, string $plan, PlanPolicy $policy, ?string $uploadedLogoId = null): ResolvedBrand
    {
        $logoAllowed = $policy->isBrandingFieldAllowed($plan, 'logo_url');
        $logoUpload  = $logoAllowed && $uploadedLogoId !== null && preg_match('/^[0-9a-f]{32}$/', $uploadedLogoId) === 1
            ? $uploadedLogoId
            : null;

        $primary   = self::validate($board['primary_color'] ?? null);
        $secondary = $policy->isBrandingFieldAllowed($plan, 'secondary_color')
            ? self::validate($board['secondary_color'] ?? null)
            : null;
        // Uploaded logo wins; the legacy link is shown only while no upload exists.
        $logoLegacy = $logoAllowed && $logoUpload === null
            ? BrandingValidator::logoUrl(is_string($board['logo_url'] ?? null) ? $board['logo_url'] : '')
            : null;

        return new ResolvedBrand($primary, $secondary, $logoLegacy, $logoUpload);
    }

    private static function validate(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return BrandingValidator::color($raw);
    }
}
