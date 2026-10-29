<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\Entitlements;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\ResolvedPlan;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\BoardProBrandingView;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\BoardProBrandingValidator;

/**
 * GET  /admin/boards/{slug}/branding/pro — Pro branding settings (AuthZ: accountAdmin).
 * POST /admin/boards/{slug}/branding/pro — saves them (AuthZ: accountAdmin, CSRF
 * globally enforced).
 *
 * Three gated groups, each a PlanPolicy feature (Q13 matrix, Pro only):
 *   - typography_presets:    typography_preset, shape_style, density
 *   - custom_wording:        wording (per locale, plaintext, length-limited)
 *   - header_links_sections: header_links, roadmap_hidden
 *
 * Downgrade rule (Q19, same as BoardBrandingAction): a locked group keeps its
 * stored values. An absent key, or one that equals the stored value, is kept;
 * an empty value (clear) is always allowed; a DIFFERENT value → 422
 * `plan_locked_field`. The public read path (BoardProBrandingView) applies the
 * stored values only while the current plan includes the feature.
 *
 * Every value is validated BEFORE saving (BoardProBrandingValidator); an invalid
 * value rejects the whole request with 422 and nothing is written.
 */
final readonly class BoardProBrandingAction
{
    /** Keys of each group, in the order the editor shows them. */
    private const GROUP_KEYS = [
        'typography_presets'    => ['typography_preset', 'shape_style', 'density'],
        'custom_wording'        => ['wording'],
        'header_links_sections' => ['header_links', 'roadmap_hidden'],
    ];

    private const LOCKED_MESSAGE = 'This option is not available on your current plan. Please upgrade.';
    private const INVALID_MESSAGE = 'Invalid value.';

    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private AccountMemberRepository $accountMembers,
        private AuditLogger $audit,
        private bool $billingEnabled = false,
    ) {}

    // -------------------------------------------------------------------------
    // GET /admin/boards/{slug}/branding/pro
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function getProBranding(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }

        $plan = $this->planFor($accountId, $request);
        $view = $this->describe($board, $plan);
        $view['entitlements'] = $this->entitlementsFor($accountId, $request, $plan);

        $response->getBody()->write((string) json_encode($view));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * The Pro branding view of a board: the stored values and what the public page
     * applies right now. Also returned by the Agent API.
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function describe(array $board, ResolvedPlan $plan): array
    {
        $slug    = is_string($board['slug'] ?? null) ? $board['slug'] : '';
        $stored  = $this->storedValues($board);
        $applied = BoardProBrandingView::applied($board, $this->planPolicy, $plan);

        return [
            'board_slug'        => $slug,
            'board_name'        => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            'frozen_at'         => $board['frozen_at'] ?? null,
            'typography_preset' => $stored['typography_preset'],
            'shape_style'       => $stored['shape_style'],
            'density'           => $stored['density'],
            'wording'           => $stored['wording'],
            'header_links'      => $stored['header_links'],
            'roadmap_hidden'    => $stored['roadmap_hidden'],
            // What the public page applies right now (kept values are shown above, not here).
            'applied'           => [
                'typography' => $applied['typography'],
                'shape'      => $applied['shape'],
                'density'    => $applied['density'],
                'wording'    => $applied['wording'] !== [],
                'header_links' => $applied['header_links'] !== [],
                'roadmap_hidden' => $applied['roadmap_hidden'],
            ],
        ];
    }

    /** @param array<string, mixed> $args */
    public function postProBranding(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
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
     * Validates a Pro branding save without writing. Returns the field and plan-lock
     * errors, or on success the values commit() writes (`write`).
     *
     * @param array<string, mixed> $board
     * @return array{errors: array<string, string>, locked: list<string>, write: array<string, mixed>}
     */
    public function prepare(array $board, int $accountId, mixed $parsed, ResolvedPlan $plan): array
    {
        $body   = is_array($parsed) ? $parsed : [];
        $stored = $this->storedValues($board);

        /** @var array<string, string> $fieldErrors */
        $fieldErrors  = [];
        $lockedFields = [];

        // ---- typography_presets -------------------------------------------------
        $typography = $stored['typography_preset'];
        $shape      = $stored['shape_style'];
        $density    = $stored['density'];
        $presetsAllowed = $this->planPolicy->featureAllowed($plan, 'typography_presets');
        $presetsSent    = array_intersect_key($body, array_flip(self::GROUP_KEYS['typography_presets'])) !== [];
        if ($presetsSent) {
            $sent = [
                'typography_preset' => $this->presetInput($body, 'typography_preset', BoardProBrandingValidator::typography(...)),
                'shape_style'       => $this->presetInput($body, 'shape_style', BoardProBrandingValidator::shape(...)),
                'density'           => $this->presetInput($body, 'density', BoardProBrandingValidator::density(...)),
            ];
            foreach ($sent as $key => $value) {
                if ($value === false) {
                    $fieldErrors[$key] = self::INVALID_MESSAGE;
                } elseif ($value !== null && !$presetsAllowed && !$this->isClear($body[$key]) && $value !== $stored[$key]) {
                    $fieldErrors[$key] = self::LOCKED_MESSAGE;
                    $lockedFields[]    = $key;
                }
            }
            $typography = is_string($sent['typography_preset']) ? $sent['typography_preset'] : $typography;
            $shape      = is_string($sent['shape_style']) ? $sent['shape_style'] : $shape;
            $density    = is_string($sent['density']) ? $sent['density'] : $density;
        }

        // ---- custom_wording -----------------------------------------------------
        $wordingAllowed = $this->planPolicy->featureAllowed($plan, 'custom_wording');
        $wording        = $stored['wording'];
        if (array_key_exists('wording', $body)) {
            $cleaned = BoardProBrandingValidator::wording($body['wording']);
            if ($cleaned === null) {
                $fieldErrors['wording'] = 'Please check the texts: length, unsupported characters or unknown fields.';
            } elseif (!$wordingAllowed && $cleaned !== [] && self::canonical($cleaned) !== self::canonical($stored['wording'])) {
                $fieldErrors['wording'] = self::LOCKED_MESSAGE;
                $lockedFields[]         = 'wording';
            } else {
                $wording = $cleaned;
            }
        }

        // ---- header_links_sections ------------------------------------------------
        $linksAllowed = $this->planPolicy->featureAllowed($plan, 'header_links_sections');
        $links        = $stored['header_links'];
        $hidden       = $stored['roadmap_hidden'];
        if (array_key_exists('header_links', $body)) {
            $cleaned = BoardProBrandingValidator::headerLinks($body['header_links']);
            if ($cleaned === null) {
                $fieldErrors['header_links'] = 'Please check the links: https only, at most 5, labels up to 30 characters.';
            } elseif (!$linksAllowed && $cleaned !== [] && self::canonical($cleaned) !== self::canonical($stored['header_links'])) {
                $fieldErrors['header_links'] = self::LOCKED_MESSAGE;
                $lockedFields[]              = 'header_links';
            } else {
                $links = $cleaned;
            }
        }
        if (array_key_exists('roadmap_hidden', $body)) {
            $incoming = in_array($body['roadmap_hidden'], [true, '1', 1, 'true'], true);
            if (!$linksAllowed && $incoming && !$stored['roadmap_hidden']) {
                $fieldErrors['roadmap_hidden'] = self::LOCKED_MESSAGE;
                $lockedFields[]                = 'roadmap_hidden';
            } else {
                $hidden = $incoming;
            }
        }

        if ($fieldErrors !== []) {
            return ['errors' => $fieldErrors, 'locked' => $lockedFields, 'write' => []];
        }

        $write = [
            'typography_preset'  => $typography === 'default' ? null : $typography,
            'shape_style'        => $shape === 'rounded' ? null : $shape,
            'density'            => $density === 'comfortable' ? null : $density,
            'wording'            => $wording === [] ? null : $this->encodeJson($wording),
            'header_links'       => $links === [] ? null : $this->encodeJson($links),
            'roadmap_hidden'     => $hidden,
        ];

        return ['errors' => [], 'locked' => [], 'write' => $write];
    }

    /**
     * Writes a validated Pro branding save (prepare()'s `write`) and audits it.
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $write
     */
    public function commit(array $board, int $accountId, array $write): void
    {
        $this->boardRepo->updateProBranding(
            (int) $board['id'],
            $accountId,
            $write['typography_preset'],
            $write['shape_style'],
            $write['density'],
            $write['wording'],
            $write['header_links'],
            $write['roadmap_hidden'],
        );

        $this->audit->log('board.pro_branding_updated', ['board_id' => (int) $board['id']]);
    }

    // -------------------------------------------------------------------------

    /**
     * The stored values as the editor shows them (re-validated, defaults filled in).
     *
     * @param array<string, mixed> $board
     * @return array{typography_preset: string, shape_style: string, density: string, wording: array<string, array<string, string>>, header_links: list<array{url: string, label: string}>, roadmap_hidden: bool}
     */
    private function storedValues(array $board): array
    {
        return [
            'typography_preset' => BoardProBrandingValidator::typography($board['typography_preset'] ?? null) ?? 'default',
            'shape_style'       => BoardProBrandingValidator::shape($board['shape_style'] ?? null) ?? 'rounded',
            'density'           => BoardProBrandingValidator::density($board['density'] ?? null) ?? 'comfortable',
            'wording'           => BoardProBrandingValidator::decodeWording(is_string($board['wording_json'] ?? null) ? $board['wording_json'] : null),
            'header_links'      => BoardProBrandingValidator::decodeHeaderLinks(is_string($board['header_links_json'] ?? null) ? $board['header_links_json'] : null),
            'roadmap_hidden'    => (bool) ($board['roadmap_hidden'] ?? false),
        ];
    }

    /**
     * One preset key of the body: the normalized value, null when the key is absent
     * (kept), or false when the value is not on the allow-list (422).
     *
     * @param array<array-key, mixed> $body
     * @param callable(mixed): ?string $validate
     */
    private function presetInput(array $body, string $key, callable $validate): string|false|null
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }
        $value = $validate($body[$key]);

        return $value ?? false;
    }

    private function planFor(int $accountId, ServerRequestInterface $request): ResolvedPlan
    {
        return $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));
    }

    /** @return array<string, mixed> */
    private function entitlementsFor(int $accountId, ServerRequestInterface $request, ResolvedPlan $enforcedPlan): array
    {
        $account    = $this->accountRepo->findById($accountId);
        $storedPlan = is_array($account) ? (string) ($account['plan'] ?? '') : '';
        $user       = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId     = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $role       = $this->accountMembers->roleFor($accountId, $userId);

        return Entitlements::forAccount($this->planPolicy, $storedPlan, $enforcedPlan, $role, $this->billingEnabled);
    }

    /**
     * 422 with per-field messages. `plan_locked_field` when every rejected field is
     * a plan-locked one, otherwise `validation_error` (same shape as BoardBrandingAction).
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

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
        ]));

        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** An empty value clears the setting, which a plan-locked group always allows. */
    private function isClear(mixed $raw): bool
    {
        return $raw === null || (is_string($raw) && trim($raw) === '');
    }

    /** Order-independent comparison key for the kept-vs-changed check. */
    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = json_decode(self::canonical($item), true);
            }
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
