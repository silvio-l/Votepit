<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Plan-derived limits and permissions for an account.
 *
 * Votepit Community Edition ships exactly one implementation that is used
 * by default: UnrestrictedPlanPolicy — every account may do everything, the
 * `accounts.plan` column carries no meaning. A private extension (see
 * Votepit\Extension\AppExtension::planPolicy()) can replace it with a
 * tiered policy; TablePlanPolicy is the generic, table-driven building block
 * for that. Every gate in the HTTP layer (board count, team size, Agent API
 * tokens, board visibility, staged branding fields) consults this interface
 * and never hardcodes a limit of its own.
 *
 * Contract for implementations: limits are inclusive maxima. boardLimit()/
 * memberLimit() return PHP_INT_MAX for "unlimited" and 0 for "deny"; the
 * visibility/branding methods return the allowed subset of ALL_VISIBILITIES/
 * ALL_BRANDING_FIELDS.
 */
interface PlanPolicy
{
    /** All board visibility values the schema/UI ever accepts, regardless of plan. */
    public const ALL_VISIBILITIES = ['public', 'unlisted', 'private'];

    /**
     * All staged branding field names the schema/UI ever accepts, regardless
     * of plan. Excludes `primary_color`/`name`, which are never gated.
     */
    public const ALL_BRANDING_FIELDS = [
        'secondary_color',
        'logo_url',
        'intro',
        'hide_badge',
        // P-28 / P-31 (owner matrix Q13=a): Lite and higher. Gate names only;
        // the values live in board_assets (cover, favicon, share image) and
        // boards.back_link_* / footer_note.
        'cover_upload',
        'favicon_og',
        'back_link_footer',
        // P-28c (owner 2026-10-25): the selectable cover pattern, Lite and higher.
        'cover_pattern',
    ];

    /**
     * Every feature key the entitlements payload (Entitlements::forAccount())
     * reports to the SPA. One key per gate the UI can show as locked: the
     * staged branding fields, visibility values, existing tier flags, the
     * team-size limit and the Q13 branding matrix (features that are not built
     * yet are still listed here so the SPA can render their lock state).
     * Keys are snake_case and stable — the SPA maps them in lib/entitlements.ts.
     */
    public const FEATURES = [
        // Every plan (Q13 matrix, ALL).
        'board_header', 'live_preview', 'accent_scale', 'submit_tags',
        // Staged branding fields (ALL_BRANDING_FIELDS, `hide_badge` = PRO in Cloud).
        'secondary_color', 'logo_url', 'intro', 'hide_badge',
        // Board visibility beyond 'public'.
        'visibility_unlisted', 'visibility_private',
        // Existing tier flags.
        'roadmap_gantt', 'widget', 'chat_notifications', 'issue_tracker',
        'anonymous_submission', 'agent_api', 'team_members',
        // Q13 matrix, LITE and up.
        'cover_upload', 'favicon_og', 'back_link_footer', 'cover_pattern',
        // Q13 matrix, PRO only.
        'typography_presets', 'custom_wording', 'header_links_sections',
        'widget_theming', 'branded_mails', 'ai_tag_suggestions',
    ];

    /** Plan value written into `accounts.plan` for a newly created account. */
    public function initialPlan(): string;

    public function isKnownPlan(string $plan): bool;

    /** Maximum number of boards an account on $plan may have (PHP_INT_MAX = unlimited). */
    public function boardLimit(string $plan): int;

    /** Maximum number of account_members rows (owner + admins + moderators) on $plan (PHP_INT_MAX = unlimited). */
    public function memberLimit(string $plan): int;

    /** Whether $plan may issue Agent API tokens. */
    public function agentApiAllowed(string $plan): bool;

    /**
     * Whether $plan may use the Roadmap Gantt view and set idea
     * start_date/target_date. The actual PATCH route/UI only exist at all
     * once the separate `roadmap-gantt` AppExtension package is installed
     * (ADR 0003) — this flag is only ever consulted at that point, and on
     * Community's default UnrestrictedPlanPolicy it is then unrestricted
     * like everything else. Votepit Cloud's CloudPlanPolicy uses it for
     * its real purpose: gating a plan tier on shared multi-tenant infra
     * where the extension is always present.
     */
    public function roadmapGanttAllowed(string $plan): bool;

    /**
     * Whether $plan may configure per-board Slack/Discord chat notifications
     * (incoming-webhook URLs). Consulted both when an admin saves a URL and
     * again at every dispatch, so a downgrade silently stops delivery
     * without deleting the stored configuration.
     */
    public function chatNotificationsAllowed(string $plan): bool;

    /**
     * Whether $plan may embed a board on an external website via the widget
     * (ADR 0005). A downgrade only disables the widget route; the stored
     * widget settings (origins, secret) are kept.
     */
    public function widgetAllowed(string $plan): bool;

    /**
     * Whether $plan may connect Linear/GitHub and create issues from ideas
     * (issue-tracker links). Consulted when connecting, when creating an
     * issue and on every inbound status-sync webhook, so a downgrade stops
     * the integration without deleting the stored connection or links.
     */
    public function issueTrackerAllowed(string $plan): bool;

    /**
     * Whether boards of $plan may accept ideas from guests without an
     * account (ADR 0006 §9). Consulted when the owner switches it on AND on
     * every guest submission, so a downgrade stops guest submissions (401,
     * as if switched off) while the stored board flag stays.
     */
    public function anonymousSubmissionAllowed(string $plan): bool;

    /** @return list<string> subset of ALL_VISIBILITIES */
    public function allowedVisibilities(string $plan): array;

    public function isVisibilityAllowed(string $plan, string $visibility): bool;

    /**
     * The safest visibility to fall back to when a board is created without
     * an explicit choice — the most restrictive of ALL_VISIBILITIES
     * ('private' > 'unlisted' > 'public') that $plan actually allows.
     * Fail-secure by construction: a caller can never end up with a
     * publicly-listed board just because the request omitted the field.
     */
    public function defaultVisibility(string $plan): string;

    /**
     * Whether $plan includes $feature (one of FEATURES). Unknown features are
     * never allowed. Read-only: the gates themselves keep calling their
     * dedicated methods, this only feeds the entitlements payload and the
     * gates of features that have no dedicated method yet.
     */
    public function featureAllowed(string $plan, string $feature): bool;

    /**
     * The lowest configured plan that includes $feature, or null when no
     * plan (or every plan, as in Community) needs an upgrade for it. Used as
     * `required_plan` in the entitlements payload of locked features.
     */
    public function lowestPlanAllowing(string $feature): ?string;

    /** @return list<string> subset of ALL_BRANDING_FIELDS */
    public function allowedBrandingFields(string $plan): array;

    public function isBrandingFieldAllowed(string $plan, string $field): bool;

    /**
     * The most permissive known plan — used to give a platform operator
     * (`users.is_operator`, strictly above account owner/admin, see
     * AuthZMiddleware) full capability regardless of the account's actual
     * billing plan. Community Edition's UnrestrictedPlanPolicy has no tiers,
     * so every plan is already unrestricted and this is purely nominal.
     */
    public function topPlan(): string;
}
