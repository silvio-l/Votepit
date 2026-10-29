<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BoardCoverPattern;
use Votepit\Domain\BoardLogoPlate;
use Votepit\Domain\BoardThemeMode;
use Votepit\Domain\Entitlements;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\ResolvedPlan;
use Votepit\Domain\TranslationGate;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\BrandingValidator;

/**
 * GET  /admin/boards/{slug}/branding — branding settings page (AuthZ: admin).
 * POST /admin/boards/{slug}/branding — saves the branding (AuthZ: admin,
 * CSRF globally enforced).
 *
 * Every value is strictly validated BEFORE saving; invalid → null →
 * default theme (no raw value ever lands in the DB/CSS).
 *
 * Also carries the board visibility (public/unlisted/private, board
 * roadmap view — Cloud-side gate). The visibility FIELD itself is
 * plan-gated: PlanPolicy::isVisibilityAllowed($plan, $visibility) — Free
 * can only set 'public'. Fail-safe: an unknown/missing plan value is only
 * allowed ['public'] by PlanPolicy, any other attempt is rejected instead
 * of silently allowed.
 *
 * The logo is NOT a URL field any more (P-28b): it is an uploaded image
 * (BoardAssetAction, kind `logo`). The legacy `logo_url` link is only kept for
 * display: an unchanged or absent value stays, an empty value clears it, and a
 * new link is refused with a field error (no server-side fetch of external
 * URLs). `cover_pattern` (P-28c) is validated against BoardCoverPattern::ALL.
 *
 * Staged field-level gating on top of the
 * existing color/logo validation. `board name` + ONE accent color
 * (`primary_color`) are ungated on every plan; the four STAGED fields
 * (`secondary_color`, `logo_url`, `intro`, `hide_badge`) are each checked
 * against $this->planPolicy->isBrandingFieldAllowed($plan, $field) — Free may set
 * none of them; the plan matrix decides per field for the paid plans. Mirrors the
 * visibility gate's fail-loud discipline: an attempt to set a disallowed
 * field to a non-empty/truthy value is REJECTED (422, same field-errors
 * shape), never silently dropped. Clearing a field back to empty/false is
 * always allowed regardless of plan (matches the pre-existing color/logo
 * "empty clears to default" behaviour).
 *
 * Legal-operator gate (board-operator-compliance issue 02,
 * migrations/0058_add_legal_operator_identity.sql): mirrors
 * BoardCreateAction's gate exactly — an attempt to set a public-facing
 * visibility (anything other than 'private') while accounts.legal_operator_name
 * is empty is rejected with the same 422 `legal_operator_required` shape,
 * BEFORE any write. Explicit reject, never a silent downgrade.
 *
 * `translation_enabled` (idea-comment-translation, per-board opt-in
 * follow-up) rides along on this same GET/POST pair for convenience but is
 * NOT a branding field and NOT staged/plan-gated — every plan may toggle
 * it, since disabling it only ever reduces machine-translation API usage.
 * It only takes effect on top of the installation-wide
 * Config::translationConfigured() (see IdeaTranslateAction/
 * CommentTranslateAction, which check both).
 *
 * `anonymous_voting_enabled` (ADR 0004, "vote without an account") rides
 * along the same way: not a branding field, not staged/plan-gated, every
 * plan may toggle it. VoteAction is the only consumer that reads it.
 *
 * `status_reason_required` (migration 0115, "a reason is required when an idea's
 * status is set", default off) rides along the same way: not a branding field,
 * not plan-gated, editable by the same account admins. IdeaStatusAction is the
 * only consumer that enforces it.
 *
 * GET exposes
 * `allowed_branding_fields` (mirrors `allowed_visibilities`) so the SPA form
 * can disable/hide fields per plan, same pattern as the visibility
 * `<select>`.
 *
 * Downgrade rule (owner decision Q19, 2026-10-25): a staged field or the
 * visibility that the CURRENT plan no longer includes is "kept, not active".
 * Its stored value is never force-cleared and stays editable-by-saving:
 *   - the field is absent from the POST, or sent with its unchanged value → kept;
 *   - sent empty/false → cleared (removing data is always allowed);
 *   - sent with a DIFFERENT non-empty value → 422 `plan_locked_field`.
 * A save of the other fields therefore always goes through. The admin GET
 * returns the stored value (as `visibility` always did) plus `entitlements`,
 * so the SPA can show it as kept. The PUBLIC read path (BoardHomeAction)
 * re-checks the CURRENT plan for `intro`, `show_badge`, `secondary_color`
 * and `logo_url`, so a kept value is never publicly active. Visibility is
 * the exception to "inactive": a kept 'unlisted'/'private' stays as the owner
 * set it (never widened to 'public' by a downgrade — fail-secure).
 * Re-upgrading makes the kept values active again immediately, without a write.
 */
final readonly class BoardBrandingAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
        private BoardComplianceEnforcer $complianceEnforcer,
        private ?DuplicateConfirmer $duplicateConfirmer = null,
        private ?AccountMemberRepository $accountMembers = null,
        private bool $billingEnabled = false,
        // Installation kill switch (Config::$voteReasonsEnabled): while off, the
        // per-board toggle cannot be switched on; switching it off stays possible.
        private bool $installationAllowsVoteReasons = true,
    ) {}

    // -------------------------------------------------------------------------
    // GET /admin/boards/{slug}/branding
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function getBranding(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $plan = $this->planFor($accountId, $request);
        $view = $this->describe($board, $plan);
        // Optional duplicate confirmation of an extension (issue 11):
        // 'available' | 'upgrade_required', null when none is installed
        // or it is switched off — drives an info line / plan hint only.
        $view['duplicate_confirmation'] = $this->duplicateConfirmationAvailability($accountId);
        // P-38: plan name and per-feature lock state for this account's
        // members (this route is account-admin only, so never public).
        $view['entitlements'] = $this->entitlementsFor($accountId, $request, $plan);

        $response->getBody()->write((string) json_encode($view));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * The branding view of a board: the fields the branding editor reads, also
     * returned by the Agent API. Stored values are sanitized on read; the plan
     * only decides the allow-lists, never the stored values (Q19).
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function describe(array $board, ResolvedPlan $plan): array
    {
        $slug      = is_string($board['slug'] ?? null) ? $board['slug'] : '';
        $primary   = is_string($board['primary_color'] ?? null) ? $board['primary_color'] : '';
        $secondary = is_string($board['secondary_color'] ?? null) ? $board['secondary_color'] : '';
        $logo      = is_string($board['logo_url'] ?? null) ? $board['logo_url'] : '';
        $intro     = is_string($board['intro'] ?? null) ? $board['intro'] : '';

        // Validate stored values — invalid → null (default theme).
        $sanitizedPrimary   = $primary !== '' ? BrandingValidator::primaryColor($primary) : null;
        $sanitizedSecondary = $secondary !== '' ? BrandingValidator::secondaryColor($secondary) : null;
        // Legacy link, display only (an uploaded logo wins over it on every page).
        $sanitizedLogo      = $logo !== '' ? BrandingValidator::logoUrl($logo) : null;
        $sanitizedIntro     = $intro !== '' ? BrandingValidator::introText($intro) : null;
        $sanitizedPattern   = BoardCoverPattern::normalize($board['cover_pattern'] ?? null);
        // Dogfood v0.1.5 (items 07-09): format-only read; the theme engine corrects contrast on render.
        $sanitizedAccent      = $this->storedColor($board, 'brand_accent_color');
        $sanitizedPrimaryDark = $this->storedColor($board, 'brand_primary_color_dark');
        $sanitizedAccentDark  = $this->storedColor($board, 'brand_accent_color_dark');

        return [
            'board_slug'              => $slug,
            'board_name'              => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            // Lets the admin console show a frozen-board banner (read-only writes).
            'frozen_at'               => $board['frozen_at'] ?? null,
            // Compliance-freeze banner (board-operator-compliance follow-up,
            // migrations/0063_add_boards_compliance_freeze.sql).
            'compliance_frozen_at'    => $board['compliance_frozen_at'] ?? null,
            'primary_color'           => $sanitizedPrimary,
            'secondary_color'         => $sanitizedSecondary,
            'logo_url'                => $sanitizedLogo,
            // Gated values (dogfood v0.1.5); shown even when the plan locks them (Q19).
            'accent_color'            => $sanitizedAccent,
            'primary_color_dark'      => $sanitizedPrimaryDark,
            'accent_color_dark'       => $sanitizedAccentDark,
            'logo_plate'              => BoardLogoPlate::normalize($board['brand_logo_plate'] ?? null),
            'logo_ring_hidden'        => (bool) ($board['brand_logo_ring_hidden'] ?? false),
            // Selected cover pattern (null = default); shown even when the plan locks it (Q19).
            'cover_pattern'           => $sanitizedPattern,
            // Display mode (P-26): system | light | dark; not plan-gated.
            'theme_mode'              => BoardThemeMode::normalize($board['theme_mode'] ?? null),
            // Uploaded logo rendition id (wins over logo_url in every surface); null when none.
            'logo_public_id'          => is_string($board['logo_public_id'] ?? null) ? $board['logo_public_id'] : null,
            'intro'                   => $sanitizedIntro,
            'hide_badge'              => (bool) ($board['hide_badge'] ?? false),
            'translation_enabled'     => (bool) ($board['translation_enabled'] ?? false),
            'anonymous_voting_enabled' => (bool) ($board['anonymous_voting_enabled'] ?? false),
            'vote_reasons_enabled'    => (bool) ($board['vote_reasons_enabled'] ?? false),
            'status_reason_required'  => (bool) ($board['status_reason_required'] ?? false),
            'visibility'              => is_string($board['visibility'] ?? null) ? $board['visibility'] : 'public',
            'allowed_visibilities'    => $this->planPolicy->allowedVisibilities($plan),
            'allowed_branding_fields' => $this->planPolicy->allowedBrandingFields($plan),
        ];
    }

    /** @param array<string, mixed> $args */
    public function postBranding(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return $this->frozenBoardWrite($request, $response, $accountId, $board);
        }

        $parsed   = $request->getParsedBody();
        $plan     = $this->planFor($accountId, $request);
        $prepared = $this->prepare($board, $accountId, $parsed, $plan);
        if ($prepared['errors'] !== []) {
            return $this->errorResponse($response, $prepared['errors'], $prepared['locked']);
        }
        $this->commit($board, $accountId, $prepared['write']);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Validates a branding save without writing anything. Returns the field and
     * plan-lock errors, or on success the values commit() writes (`write`).
     * The Agent API runs this for the merged PATCH body before any write.
     *
     * @param array<string, mixed> $board
     * @return array{errors: array<string, string>, locked: list<string>, write: array<string, mixed>}
     */
    public function prepare(array $board, int $accountId, mixed $parsed, ResolvedPlan $plan): array
    {
        $rawPrimary   = is_array($parsed) ? (string) ($parsed['primary_color'] ?? '') : '';
        $rawSecondary = is_array($parsed) ? (string) ($parsed['secondary_color'] ?? '') : '';
        $rawLogo      = is_array($parsed) ? (string) ($parsed['logo_url'] ?? '') : '';
        $hasLogo      = is_array($parsed) && array_key_exists('logo_url', $parsed);
        $rawPattern   = is_array($parsed) ? ($parsed['cover_pattern'] ?? null) : null;
        $hasPattern   = is_array($parsed) && array_key_exists('cover_pattern', $parsed);
        $rawThemeMode = is_array($parsed) ? ($parsed['theme_mode'] ?? null) : null;
        $hasThemeMode = is_array($parsed) && array_key_exists('theme_mode', $parsed);
        $rawIntro     = is_array($parsed) ? (string) ($parsed['intro'] ?? '') : '';
        $rawHideBadge = is_array($parsed) ? ($parsed['hide_badge'] ?? false) : false;
        $hideBadge    = in_array($rawHideBadge, [true, '1', 1], true);

        $hasTranslationEnabled = is_array($parsed) && array_key_exists('translation_enabled', $parsed);
        $rawTranslationEnabled = $hasTranslationEnabled ? $parsed['translation_enabled'] : false;
        $translationEnabled    = in_array($rawTranslationEnabled, [true, '1', 1], true);

        $hasAnonymousVotingEnabled = is_array($parsed) && array_key_exists('anonymous_voting_enabled', $parsed);
        $rawAnonymousVotingEnabled = $hasAnonymousVotingEnabled ? $parsed['anonymous_voting_enabled'] : false;
        $anonymousVotingEnabled    = in_array($rawAnonymousVotingEnabled, [true, '1', 1], true);

        // P-03 down-vote reason follow-up: same rider pattern (not a branding field, not plan-gated).
        $hasVoteReasonsEnabled = is_array($parsed) && array_key_exists('vote_reasons_enabled', $parsed);
        $rawVoteReasonsEnabled = $hasVoteReasonsEnabled ? $parsed['vote_reasons_enabled'] : false;
        $voteReasonsEnabled    = in_array($rawVoteReasonsEnabled, [true, '1', 1], true);

        $storedVoteReasons = (bool) ($board['vote_reasons_enabled'] ?? false);

        // Status decisions need a reason on this board (migration 0115): same rider pattern.
        $hasStatusReasonRequired = is_array($parsed) && array_key_exists('status_reason_required', $parsed);
        $rawStatusReasonRequired = $hasStatusReasonRequired ? $parsed['status_reason_required'] : false;
        $statusReasonRequired    = in_array($rawStatusReasonRequired, [true, '1', 1], true);
        $hasVisibility = is_array($parsed) && array_key_exists('visibility', $parsed);
        $rawVisibility = $hasVisibility ? (string) $parsed['visibility'] : '';


        // Q19 kept values: the stored raw values, and whether each staged field
        // was sent at all. An absent locked field keeps its stored value.
        $storedSecondary = $this->storedString($board, 'secondary_color');
        $storedLogo      = $this->storedString($board, 'logo_url');
        $storedPattern   = BoardCoverPattern::normalize($board['cover_pattern'] ?? null);
        $storedIntro     = $this->storedString($board, 'intro');
        $storedHideBadge = (bool) ($board['hide_badge'] ?? false);
        $storedVisibility = is_string($board['visibility'] ?? null) ? $board['visibility'] : 'public';
        $hasSecondary = is_array($parsed) && array_key_exists('secondary_color', $parsed);
        $hasIntro     = is_array($parsed) && array_key_exists('intro', $parsed);
        $hasHideBadge = is_array($parsed) && array_key_exists('hide_badge', $parsed);

        // Dogfood v0.1.5 (items 07-09): gated colours, logo plate and ring. An absent field keeps its stored value.
        $hasAccent        = is_array($parsed) && array_key_exists('accent_color', $parsed);
        $rawAccent        = $hasAccent ? (string) $parsed['accent_color'] : '';
        $hasPrimaryDark   = is_array($parsed) && array_key_exists('primary_color_dark', $parsed);
        $rawPrimaryDark   = $hasPrimaryDark ? (string) $parsed['primary_color_dark'] : '';
        $hasAccentDark    = is_array($parsed) && array_key_exists('accent_color_dark', $parsed);
        $rawAccentDark    = $hasAccentDark ? (string) $parsed['accent_color_dark'] : '';
        $hasLogoPlate     = is_array($parsed) && array_key_exists('logo_plate', $parsed);
        $rawLogoPlate     = $hasLogoPlate && is_string($parsed['logo_plate']) ? $parsed['logo_plate'] : '';
        $hasRingHidden    = is_array($parsed) && array_key_exists('logo_ring_hidden', $parsed);
        $ringHidden       = $hasRingHidden && in_array($parsed['logo_ring_hidden'], [true, '1', 1], true);

        // Staged field-level gating — collect every rejected field into ONE
        // 422 response, same UX as reporting several validation errors together.
        // Contrast rejection is reported as a field error ONLY when the color is
        // otherwise well-formed — a malformed/XSS-payload "color" must keep
        // failing silently to null (never echo attacker-controlled input back
        // in an error message; see test_xss_payload_rejected_on_every_branding_field).
        $fieldErrors = [];
        $lockedErrors = [];
        if ($rawPrimary !== '' && BrandingValidator::color($rawPrimary) !== null && BrandingValidator::primaryColor($rawPrimary) === null) {
            $fieldErrors['primary_color'] = 'This color does not have enough contrast against white button text (WCAG AA). Please choose a darker shade.';
        }

        $secondaryAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'secondary_color');
        // A locked field's unchanged value (compared with what the admin GET shows) is kept.
        $secondaryKept = $this->isKeptValue($rawSecondary, $hasSecondary, $storedSecondary, BrandingValidator::secondaryColor(...));
        if ($rawSecondary !== '' && !$secondaryAllowed && !$secondaryKept) {
            $fieldErrors['secondary_color'] = 'A second accent color is not available on your current plan. Please upgrade.';
            $lockedErrors[] = 'secondary_color';
        } elseif ($rawSecondary !== '' && $secondaryAllowed && BrandingValidator::color($rawSecondary) !== null && BrandingValidator::secondaryColor($rawSecondary) === null) {
            $fieldErrors['secondary_color'] = 'This color does not have enough contrast against white button text (WCAG AA). Please choose a darker shade.';
        }

        // Legacy link: unchanged, absent or cleared is fine. A NEW link is never
        // stored (no URL input; the logo is uploaded as a file).
        $logoAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'logo_url');
        if ($hasLogo && $rawLogo !== '' && $rawLogo !== $storedLogo) {
            if (!$logoAllowed) {
                $fieldErrors['logo_url'] = 'A custom logo is not available on your current plan. Please upgrade.';
                $lockedErrors[] = 'logo_url';
            } else {
                $fieldErrors['logo_url'] = 'Upload the logo as an image file instead of a link.';
            }
        }

        // Cover pattern (P-28c): paid plans select, a downgrade keeps the stored value (Q19).
        $patternAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'cover_pattern');
        $patternWrite   = $storedPattern;
        if ($hasPattern) {
            $patternRequested = is_string($rawPattern) ? $rawPattern : '';
            if ($rawPattern === null || $patternRequested === '') {
                $patternWrite = null;
            } elseif (!BoardCoverPattern::isKnown($patternRequested)) {
                $fieldErrors['cover_pattern'] = 'Unknown cover pattern.';
            } elseif ($patternRequested !== $storedPattern) {
                if ($patternAllowed) {
                    $patternWrite = $patternRequested;
                } else {
                    $fieldErrors['cover_pattern'] = 'Cover patterns are not available on your current plan. Please upgrade.';
                    $lockedErrors[] = 'cover_pattern';
                }
            }
        }

        // Display mode (P-26, dark mode): available on every plan (owner answer 45a); no gate.
        $themeModeWrite = BoardThemeMode::normalize($board['theme_mode'] ?? null);
        if ($hasThemeMode) {
            if (is_string($rawThemeMode) && BoardThemeMode::isKnown($rawThemeMode)) {
                $themeModeWrite = $rawThemeMode;
            } else {
                $fieldErrors['theme_mode'] = 'Unknown theme mode.';
            }
        }

        $introAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'intro');
        $introKept    = $this->isKeptValue($rawIntro, $hasIntro, $storedIntro, BrandingValidator::introText(...));
        if ($rawIntro !== '' && !$introAllowed && !$introKept) {
            $fieldErrors['intro'] = 'An intro text is not available on your current plan. Please upgrade.';
            $lockedErrors[] = 'intro';
        }

        // Locked badge: sending "hide" while it is not stored is a change; keeping
        // or clearing an already stored "hide" is not.
        $badgeAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'hide_badge');
        if ($hasHideBadge && $hideBadge && !$badgeAllowed && !$storedHideBadge) {
            $fieldErrors['hide_badge'] = 'Hiding the badge is not available on your current plan. Please upgrade.';
            $lockedErrors[] = 'hide_badge';
        }

        // Dogfood v0.1.5 (items 07-09). Colours: a locked value is kept when it is absent or
        // unchanged, cleared when empty, and refused (plan_locked_field) when it would change.
        $accentAllowed     = $this->planPolicy->isBrandingFieldAllowed($plan, 'accent_color');
        $primaryDarkAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'primary_color_dark');
        $accentDarkAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'accent_color_dark');
        $storedAccent      = $this->storedString($board, 'brand_accent_color');
        $storedPrimaryDark = $this->storedString($board, 'brand_primary_color_dark');
        $storedAccentDark  = $this->storedString($board, 'brand_accent_color_dark');
        $accentKept        = $this->colorKept($hasAccent, $rawAccent, $storedAccent);
        $primaryDarkKept   = $this->colorKept($hasPrimaryDark, $rawPrimaryDark, $storedPrimaryDark);
        $accentDarkKept    = $this->colorKept($hasAccentDark, $rawAccentDark, $storedAccentDark);
        $colorFields = [
            'accent_color'        => [$rawAccent, $accentAllowed, $accentKept],
            'primary_color_dark'  => [$rawPrimaryDark, $primaryDarkAllowed, $primaryDarkKept],
            'accent_color_dark'   => [$rawAccentDark, $accentDarkAllowed, $accentDarkKept],
        ];
        foreach ($colorFields as $field => [$raw, $allowed, $kept]) {
            if ($raw === '') {
                continue;
            }
            if (!$allowed && !$kept) {
                $fieldErrors[$field] = 'This colour is not available on your current plan. Please upgrade.';
                $lockedErrors[] = $field;
            } elseif ($allowed && BrandingValidator::color($raw) === null) {
                $fieldErrors[$field] = 'Use a six-digit hex colour, for example #1FA890.';
            }
        }

        // Logo plate and ring (one gated field, `logo_plate`). Auto and empty clear the plate
        // and are always allowed; choosing a plate or hiding the ring while locked is refused.
        $logoPlateAllowed = $this->planPolicy->isBrandingFieldAllowed($plan, 'logo_plate');
        $storedLogoPlate  = BoardLogoPlate::normalize($board['brand_logo_plate'] ?? null);
        $storedRingHidden = (bool) ($board['brand_logo_ring_hidden'] ?? false);
        $logoPlateWrite   = $storedLogoPlate;
        if ($hasLogoPlate) {
            $requestedPlate = $rawLogoPlate === BoardLogoPlate::AUTO ? '' : $rawLogoPlate;
            if ($requestedPlate !== '' && !BoardLogoPlate::isKnown($requestedPlate)) {
                $fieldErrors['logo_plate'] = 'Unknown logo plate.';
            } elseif (!$logoPlateAllowed && $requestedPlate !== '' && $requestedPlate !== $storedLogoPlate) {
                $fieldErrors['logo_plate'] = 'A logo plate is not available on your current plan. Please upgrade.';
                $lockedErrors[] = 'logo_plate';
            } else {
                $logoPlateWrite = $requestedPlate !== '' ? $requestedPlate : null;
            }
        }
        if ($hasRingHidden && $ringHidden && !$storedRingHidden && !$logoPlateAllowed) {
            $fieldErrors['logo_plate'] = 'Hiding the logo ring is not available on your current plan. Please upgrade.';
            if (!in_array('logo_plate', $lockedErrors, true)) {
                $lockedErrors[] = 'logo_plate';
            }
        }

        // Kill switch: switching reasons on is refused while the installation has them
        // off. Keeping them on (SPA re-sends the stored value) or switching them off is not.
        if ($hasVoteReasonsEnabled && $voteReasonsEnabled && !$storedVoteReasons && !$this->installationAllowsVoteReasons) {
            $fieldErrors['vote_reasons_enabled'] = 'Reasons for votes are not available on this installation.';
        }

        $visibilityAllowed = $hasVisibility && in_array($rawVisibility, PlanPolicy::ALL_VISIBILITIES, true)
            && $this->planPolicy->isVisibilityAllowed($plan, $rawVisibility);
        if ($hasVisibility && !in_array($rawVisibility, PlanPolicy::ALL_VISIBILITIES, true)) {
            $fieldErrors['visibility'] = 'Invalid visibility.';
        } elseif ($hasVisibility && !$visibilityAllowed && $rawVisibility !== $storedVisibility) {
            $fieldErrors['visibility'] = 'This visibility is not available on your current plan. Please upgrade.';
            $lockedErrors[] = 'visibility';
        } elseif ($hasVisibility && $rawVisibility !== 'private' && !$this->accountRepo->hasCompleteLegalOperatorIdentity($accountId)) {
            $fieldErrors['visibility'] = 'Set a legal operator name in your account settings before making a board public.';
        }
        // Deliberately NOT a block-at-write gate on operator-acknowledgment
        // status (unlike the legal-operator-name check right above, which
        // predates this feature): the board-operator-compliance follow-up
        // (Frage 1 = a+c) chose automatic freeze-after over reject-at-write
        // for this specific gap — see complianceEnforcer->enforceForAccount()
        // below, which freezes the board right after this write if it's
        // still non-compliant. A hard reject here would also incorrectly
        // block self-host: NullBoardOperatorAcknowledgmentGate::status()
        // always returns null (self-host never records acknowledgment), so
        // this account/gate pair is never distinguishable from "genuinely
        // never acknowledged" at write time.

        if ($fieldErrors !== []) {
            return ['errors' => $fieldErrors, 'locked' => $lockedErrors, 'write' => []];
        }

        // Locked + kept fields are written back as stored (never re-sanitized,
        // so a stored value that no longer passes today's validator survives
        // as kept); a locked field that was cleared is written empty.
        $secondaryWrite = $secondaryAllowed
            ? ($rawSecondary !== '' ? BrandingValidator::secondaryColor($rawSecondary) : null)
            : ($secondaryKept ? $storedSecondary : null);
        // Legacy link: absent or unchanged keeps the stored value, empty clears it.
        $logoWrite = $hasLogo && $rawLogo === '' ? null : $storedLogo;
        $introWrite = $introAllowed
            ? ($rawIntro !== '' ? BrandingValidator::introText($rawIntro) : null)
            : ($introKept ? $storedIntro : null);
        $hideBadgeWrite = $badgeAllowed || $hasHideBadge ? $hideBadge : $storedHideBadge;

        $this->boardRepo->updateBranding(
            (int) $board['id'],
            $accountId,
            $rawPrimary !== '' ? BrandingValidator::primaryColor($rawPrimary) : null,
            $secondaryWrite !== '' ? $secondaryWrite : null,
            $logoWrite !== '' ? $logoWrite : null,
            $introWrite !== '' ? $introWrite : null,
            $hideBadgeWrite,
        );

        $this->boardRepo->updateBrandAccentAndPlate(
            (int) $board['id'],
            $accountId,
            $this->colorWrite($hasAccent, $rawAccent, $accentAllowed, $accentKept, $this->storedColor($board, 'brand_accent_color')),
            $this->colorWrite($hasPrimaryDark, $rawPrimaryDark, $primaryDarkAllowed, $primaryDarkKept, $this->storedColor($board, 'brand_primary_color_dark')),
            $this->colorWrite($hasAccentDark, $rawAccentDark, $accentDarkAllowed, $accentDarkKept, $this->storedColor($board, 'brand_accent_color_dark')),
            $logoPlateWrite,
            $hasRingHidden ? $ringHidden : $storedRingHidden,
        );

        if ($hasPattern) {
            $this->boardRepo->updateCoverPattern((int) $board['id'], $accountId, $patternWrite);
        }

        if ($hasThemeMode) {
            $this->boardRepo->updateThemeMode((int) $board['id'], $accountId, $themeModeWrite);
        }

        if ($hasVisibility) {
            $this->boardRepo->updateVisibility((int) $board['id'], $accountId, $rawVisibility);
            if ($rawVisibility !== 'private') {
                $this->complianceEnforcer->enforceForAccount($accountId);
            }
        }

        // Switching translation on needs the plan feature; a locked plan keeps the
        // stored value (Q19), switching it off is always allowed.
        if ($hasTranslationEnabled && (!$translationEnabled || TranslationGate::planAllows($this->planPolicy, $plan))) {
            $this->boardRepo->updateTranslationEnabled((int) $board['id'], $accountId, $translationEnabled);
        }

        if ($hasAnonymousVotingEnabled) {
            $this->boardRepo->updateAnonymousVotingEnabled((int) $board['id'], $accountId, $anonymousVotingEnabled);
        }

        if ($hasVoteReasonsEnabled) {
            $this->boardRepo->updateVoteReasonsEnabled((int) $board['id'], $accountId, $voteReasonsEnabled);
        }

        if ($hasStatusReasonRequired) {
            $this->boardRepo->updateStatusReasonRequired((int) $board['id'], $accountId, $statusReasonRequired);
        }

        $write = [
            'primary'      => $rawPrimary !== '' ? BrandingValidator::primaryColor($rawPrimary) : null,
            'secondary'    => $secondaryWrite !== '' ? $secondaryWrite : null,
            'logo'         => $logoWrite !== '' ? $logoWrite : null,
            'intro'        => $introWrite !== '' ? $introWrite : null,
            'hide_badge'   => $hideBadgeWrite,
            'accent'       => $this->colorWrite($hasAccent, $rawAccent, $accentAllowed, $accentKept, $this->storedColor($board, 'brand_accent_color')),
            'primary_dark' => $this->colorWrite($hasPrimaryDark, $rawPrimaryDark, $primaryDarkAllowed, $primaryDarkKept, $this->storedColor($board, 'brand_primary_color_dark')),
            'accent_dark'  => $this->colorWrite($hasAccentDark, $rawAccentDark, $accentDarkAllowed, $accentDarkKept, $this->storedColor($board, 'brand_accent_color_dark')),
            'logo_plate'   => $logoPlateWrite,
            'ring_hidden'  => $hasRingHidden ? $ringHidden : $storedRingHidden,
        ];
        if ($hasPattern) {
            $write['cover_pattern'] = $patternWrite;
        }
        if ($hasThemeMode) {
            $write['theme_mode'] = $themeModeWrite;
        }
        if ($hasVisibility) {
            $write['visibility'] = $rawVisibility;
        }
        // Switching translation on needs the plan feature; a locked plan keeps the
        // stored value (Q19), switching it off is always allowed.
        if ($hasTranslationEnabled && (!$translationEnabled || TranslationGate::planAllows($this->planPolicy, $plan))) {
            $write['translation_enabled'] = $translationEnabled;
        }
        if ($hasAnonymousVotingEnabled) {
            $write['anonymous_voting_enabled'] = $anonymousVotingEnabled;
        }
        if ($hasVoteReasonsEnabled) {
            $write['vote_reasons_enabled'] = $voteReasonsEnabled;
        }
        if ($hasStatusReasonRequired) {
            $write['status_reason_required'] = $statusReasonRequired;
        }

        return ['errors' => [], 'locked' => [], 'write' => $write];
    }

    /**
     * Writes a validated branding save (prepare()'s `write`) and audits it. The
     * session form and the Agent API both end here, with the same calls.
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $write
     */
    public function commit(array $board, int $accountId, array $write): void
    {
        $boardId = (int) $board['id'];

        $this->boardRepo->updateBranding($boardId, $accountId, $write['primary'], $write['secondary'], $write['logo'], $write['intro'], $write['hide_badge']);
        $this->boardRepo->updateBrandAccentAndPlate($boardId, $accountId, $write['accent'], $write['primary_dark'], $write['accent_dark'], $write['logo_plate'], $write['ring_hidden']);

        if (array_key_exists('cover_pattern', $write)) {
            $this->boardRepo->updateCoverPattern($boardId, $accountId, $write['cover_pattern']);
        }
        if (array_key_exists('theme_mode', $write)) {
            $this->boardRepo->updateThemeMode($boardId, $accountId, $write['theme_mode']);
        }
        if (array_key_exists('visibility', $write)) {
            $this->boardRepo->updateVisibility($boardId, $accountId, $write['visibility']);
            if ($write['visibility'] !== 'private') {
                $this->complianceEnforcer->enforceForAccount($accountId);
            }
        }
        if (array_key_exists('translation_enabled', $write)) {
            $this->boardRepo->updateTranslationEnabled($boardId, $accountId, $write['translation_enabled']);
        }
        if (array_key_exists('anonymous_voting_enabled', $write)) {
            $this->boardRepo->updateAnonymousVotingEnabled($boardId, $accountId, $write['anonymous_voting_enabled']);
        }
        if (array_key_exists('vote_reasons_enabled', $write)) {
            $this->boardRepo->updateVoteReasonsEnabled($boardId, $accountId, $write['vote_reasons_enabled']);
        }
        if (array_key_exists('status_reason_required', $write)) {
            $this->boardRepo->updateStatusReasonRequired($boardId, $accountId, $write['status_reason_required']);
        }

        $this->audit->log('board.branding_updated', ['board_id' => $boardId]);
    }

    /**
     * Frozen board (owner rule 2026-10-22, Q1=b; either freeze reason): the
     * only accepted write is a request whose sole field is visibility='private'.
     * Switching to private never widens exposure, so it is allowed on every
     * plan (Free only offers 'public') and needs no legal-operator check.
     * It writes visibility ONLY — the full-form path above would clear the
     * branding columns that this request does not send. Everything else,
     * including an empty body, is 423 board_frozen and writes nothing.
     *
     * @param array<string, mixed> $board
     */
    private function frozenBoardWrite(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $accountId,
        array $board,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? array_diff_key($parsed, ['_csrf' => true]) : [];
        if ($fields !== ['visibility' => 'private']) {
            return FrozenBoardGuard::reject($response);
        }

        $this->boardRepo->updateVisibility((int) $board['id'], $accountId, 'private');
        $this->audit->log('board.branding_updated', ['board_id' => (int) $board['id']]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function planFor(int $accountId, ServerRequestInterface $request): ResolvedPlan
    {
        return $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));
    }

    /**
     * 422 with the per-field messages. `plan_locked_field` is the stable key when
     * every rejected field is a plan-locked one (Q19); any other failure keeps
     * `validation_error`. `locked_fields` names the plan-locked ones.
     *
     * @param array<string, string> $fields
     * @param list<string> $lockedFields
     */
    private function errorResponse(ResponseInterface $response, array $fields, array $lockedFields): ResponseInterface
    {
        $onlyLocked = $lockedFields !== [] && count($lockedFields) === count($fields);
        $error = [
            'key'     => $onlyLocked ? 'plan_locked_field' : 'validation_error',
            'message' => 'Validation failed.',
            'fields'  => $fields,
        ];
        if ($lockedFields !== []) {
            $error['locked_fields'] = $lockedFields;
        }
        $response->getBody()->write((string) json_encode(['error' => $error]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Q19: a plan-locked staged field keeps its stored value when the POST leaves
     * it out or sends exactly what the admin GET shows for it. An empty value
     * (clear) is not "kept". $sanitize is the same sanitizer the GET uses.
     *
     * @param callable(string): ?string $sanitize
     */
    private function isKeptValue(string $raw, bool $present, string $stored, callable $sanitize): bool
    {
        if ($stored === '') {
            return false;
        }
        if (!$present) {
            return true;
        }
        if ($raw === '') {
            return false;
        }

        return $raw === $sanitize($stored);
    }

    /** @param array<string, mixed> $board */
    private function storedString(array $board, string $key): string
    {
        return is_string($board[$key] ?? null) ? $board[$key] : '';
    }

    /**
     * Q19 for a colour: the stored value is compared case-insensitively, because the
     * server stores colours lower-cased while the colour picker sends upper-case.
     */
    private function colorKept(bool $present, string $raw, string $stored): bool
    {
        return $this->isKeptValue(strtolower($raw), $present, $stored, BrandingValidator::color(...));
    }

    /** @param array<string, mixed> $board */
    private function storedColor(array $board, string $key): ?string
    {
        $raw = $this->storedString($board, $key);

        return $raw !== '' ? BrandingValidator::color($raw) : null;
    }

    /**
     * The value one optional colour field is written as (Q19): absent keeps the stored
     * value, empty clears it, a value is written when the plan allows it or it is kept.
     */
    private function colorWrite(bool $present, string $raw, bool $allowed, bool $kept, ?string $stored): ?string
    {
        if (!$present) {
            return $stored;
        }
        if ($raw === '') {
            return null;
        }

        return $allowed || $kept ? BrandingValidator::color($raw) : null;
    }

    /** @return array<string, mixed> */
    private function entitlementsFor(int $accountId, ServerRequestInterface $request, ResolvedPlan $enforcedPlan): array
    {
        $account    = $this->accountRepo->findById($accountId);
        $storedPlan = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $user       = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId     = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $role       = $this->accountMembers?->roleFor($accountId, $userId);

        return Entitlements::forAccount($this->planPolicy, $storedPlan, $enforcedPlan, $role, $this->billingEnabled);
    }

    private function duplicateConfirmationAvailability(int $accountId): ?string
    {
        $availability = $this->duplicateConfirmer?->availability($accountId);

        return $availability === DuplicateConfirmationAvailability::Off ? null : $availability?->value;
    }
}
