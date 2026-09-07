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
    public const ALL_BRANDING_FIELDS = ['secondary_color', 'logo_url', 'intro', 'hide_badge'];

    /** Plan value written into `accounts.plan` for a newly created account. */
    public function initialPlan(): string;

    public function isKnownPlan(string $plan): bool;

    /** Maximum number of boards an account on $plan may have (PHP_INT_MAX = unlimited). */
    public function boardLimit(string $plan): int;

    /** Maximum number of account_members rows (owner + admins + moderators) on $plan (PHP_INT_MAX = unlimited). */
    public function memberLimit(string $plan): int;

    /** Whether $plan may issue Agent API tokens. */
    public function agentApiAllowed(string $plan): bool;

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
