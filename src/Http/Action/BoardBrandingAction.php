<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\EffectivePlan;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
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
 * Staged field-level gating on top of the
 * existing color/logo validation. `board name` + ONE accent color
 * (`primary_color`) are ungated on every plan; the four STAGED fields
 * (`secondary_color`, `logo_url`, `intro`, `hide_badge`) are each checked
 * against $this->planPolicy->isBrandingFieldAllowed($plan, $field) — Free may set
 * none of them, Lite gets the first three, Pro gets all four. Mirrors the
 * visibility gate's fail-loud discipline: an attempt to set a disallowed
 * field to a non-empty/truthy value is REJECTED (422, same field-errors
 * shape), never silently dropped. Clearing a field back to empty/false is
 * always allowed regardless of plan (matches the pre-existing color/logo
 * "empty clears to default" behaviour). GET exposes
 * `allowed_branding_fields` (mirrors `allowed_visibilities`) so the SPA form
 * can disable/hide fields per plan, same pattern as the visibility
 * `<select>`.
 *
 * Downgrade decision (Pro/Lite → Free after a staged field was already set):
 * the stored value is NOT force-cleared here (no precedent for that in this
 * codebase — visibility's downgrade handling is also write-time-only, never
 * retroactively rewritten). This admin GET therefore still returns the raw
 * stored value (like `visibility` does) so the owner can see what is
 * configured. The PUBLIC-facing read path (BoardHomeAction) is the one that
 * additionally re-checks the CURRENT plan before exposing `intro`/
 * `show_badge` to anonymous visitors — an over-plan value sitting in the DB
 * is inert until the account re-upgrades, never publicly active in the
 * meantime.
 */
final readonly class BoardBrandingAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
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

        $primary   = is_string($board['primary_color'] ?? null) ? $board['primary_color'] : '';
        $secondary = is_string($board['secondary_color'] ?? null) ? $board['secondary_color'] : '';
        $logo      = is_string($board['logo_url'] ?? null) ? $board['logo_url'] : '';
        $intro     = is_string($board['intro'] ?? null) ? $board['intro'] : '';

        // Validate stored values — invalid → null (default theme).
        $sanitizedPrimary   = $primary !== '' ? BrandingValidator::primaryColor($primary) : null;
        $sanitizedSecondary = $secondary !== '' ? BrandingValidator::secondaryColor($secondary) : null;
        $sanitizedLogo      = $logo !== '' ? BrandingValidator::logoUrl($logo) : null;
        $sanitizedIntro     = $intro !== '' ? BrandingValidator::introText($intro) : null;

        $plan = $this->planFor($accountId, $request);

        $response->getBody()->write((string) json_encode([
            'board_slug'              => $slug,
            'board_name'              => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            // Lets the admin console show a frozen-board banner (read-only writes).
            'frozen_at'               => $board['frozen_at'] ?? null,
            'primary_color'           => $sanitizedPrimary,
            'secondary_color'         => $sanitizedSecondary,
            'logo_url'                => $sanitizedLogo,
            'intro'                   => $sanitizedIntro,
            'hide_badge'              => (bool) ($board['hide_badge'] ?? false),
            'visibility'              => is_string($board['visibility'] ?? null) ? $board['visibility'] : 'public',
            'allowed_visibilities'    => $this->planPolicy->allowedVisibilities($plan),
            'allowed_branding_fields' => $this->planPolicy->allowedBrandingFields($plan),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /admin/boards/{slug}/branding
    // -------------------------------------------------------------------------

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

        $parsed       = $request->getParsedBody();
        $rawPrimary   = is_array($parsed) ? (string) ($parsed['primary_color'] ?? '') : '';
        $rawSecondary = is_array($parsed) ? (string) ($parsed['secondary_color'] ?? '') : '';
        $rawLogo      = is_array($parsed) ? (string) ($parsed['logo_url'] ?? '') : '';
        $rawIntro     = is_array($parsed) ? (string) ($parsed['intro'] ?? '') : '';
        $rawHideBadge = is_array($parsed) ? ($parsed['hide_badge'] ?? false) : false;
        $hideBadge    = in_array($rawHideBadge, [true, '1', 1], true);

        $hasVisibility = is_array($parsed) && array_key_exists('visibility', $parsed);
        $rawVisibility = $hasVisibility ? (string) $parsed['visibility'] : '';

        $plan = $this->planFor($accountId, $request);

        // Staged field-level gating — collect every rejected field into ONE
        // 422 response, same UX as reporting several validation errors together.
        // Contrast rejection is reported as a field error ONLY when the color is
        // otherwise well-formed — a malformed/XSS-payload "color" must keep
        // failing silently to null (never echo attacker-controlled input back
        // in an error message; see test_xss_payload_rejected_on_every_branding_field).
        $fieldErrors = [];
        if ($rawPrimary !== '' && BrandingValidator::color($rawPrimary) !== null && BrandingValidator::primaryColor($rawPrimary) === null) {
            $fieldErrors['primary_color'] = 'This color does not have enough contrast against white button text (WCAG AA). Please choose a darker shade.';
        }
        if ($rawSecondary !== '' && !$this->planPolicy->isBrandingFieldAllowed($plan, 'secondary_color')) {
            $fieldErrors['secondary_color'] = 'A second accent color is not available on your current plan. Please upgrade.';
        } elseif ($rawSecondary !== '' && BrandingValidator::color($rawSecondary) !== null && BrandingValidator::secondaryColor($rawSecondary) === null) {
            $fieldErrors['secondary_color'] = 'This color does not have enough contrast against white button text (WCAG AA). Please choose a darker shade.';
        }
        if ($rawLogo !== '' && !$this->planPolicy->isBrandingFieldAllowed($plan, 'logo_url')) {
            $fieldErrors['logo_url'] = 'A custom logo is not available on your current plan. Please upgrade.';
        }
        if ($rawIntro !== '' && !$this->planPolicy->isBrandingFieldAllowed($plan, 'intro')) {
            $fieldErrors['intro'] = 'An intro text is not available on your current plan. Please upgrade.';
        }
        if ($hideBadge && !$this->planPolicy->isBrandingFieldAllowed($plan, 'hide_badge')) {
            $fieldErrors['hide_badge'] = 'Hiding the badge is not available on your current plan. Please upgrade.';
        }
        if ($hasVisibility && !in_array($rawVisibility, PlanPolicy::ALL_VISIBILITIES, true)) {
            $fieldErrors['visibility'] = 'Invalid visibility.';
        } elseif ($hasVisibility && !$this->planPolicy->isVisibilityAllowed($plan, $rawVisibility)) {
            $fieldErrors['visibility'] = 'This visibility is not available on your current plan. Please upgrade.';
        }

        if ($fieldErrors !== []) {
            return $this->errorResponse($response, $fieldErrors);
        }

        $this->boardRepo->updateBranding(
            (int) $board['id'],
            $accountId,
            $rawPrimary !== '' ? BrandingValidator::primaryColor($rawPrimary) : null,
            $rawSecondary !== '' ? BrandingValidator::secondaryColor($rawSecondary) : null,
            $rawLogo !== '' ? BrandingValidator::logoUrl($rawLogo) : null,
            $rawIntro !== '' ? BrandingValidator::introText($rawIntro) : null,
            $hideBadge,
        );

        if ($hasVisibility) {
            $this->boardRepo->updateVisibility((int) $board['id'], $accountId, $rawVisibility);
        }

        $this->audit->log('board.branding_updated', ['board_id' => (int) $board['id']]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function planFor(int $accountId, ServerRequestInterface $request): string
    {
        $account = $this->accountRepo->findById($accountId);
        $plan    = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return EffectivePlan::resolve($plan, is_array($user) ? $user : null, $this->planPolicy);
    }

    /** @param array<string, string> $fields */
    private function errorResponse(ResponseInterface $response, array $fields): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => [
                'key'     => 'validation_error',
                'message' => 'Validation failed.',
                'fields'  => $fields,
            ],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }
}
