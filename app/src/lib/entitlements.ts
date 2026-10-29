/**
 * Typed view of the server's `entitlements` object (P-38). The server decides
 * every answer (PlanPolicy::featureAllowed via core/src/Domain/Entitlements.php);
 * the SPA only reads it. Never derive a feature answer from the plan name here.
 *
 * Found on bootstrap memberships (the viewer's own accounts, see viewer.ts)
 * and on the admin branding GET. Public payloads carry none, so callers must
 * treat a missing entry as "unknown", not as "allowed".
 */

/** Feature keys, identical to core's PlanPolicy::FEATURES. */
export const FEATURE_KEYS = [
  'board_header',
  'live_preview',
  'accent_scale',
  'submit_tags',
  'secondary_color',
  'logo_url',
  'accent_color',
  'primary_color_dark',
  'accent_color_dark',
  'logo_plate',
  'translation',
  'cover_pattern',
  'intro',
  'hide_badge',
  'visibility_unlisted',
  'visibility_private',
  'roadmap_gantt',
  'widget',
  'chat_notifications',
  'issue_tracker',
  'anonymous_submission',
  'agent_api',
  'team_members',
  'cover_upload',
  'favicon_og',
  'back_link_footer',
  'typography_presets',
  'custom_wording',
  'header_links_sections',
  'widget_theming',
  'branded_mails',
  'ai_tag_suggestions',
] as const

export type FeatureKey = (typeof FEATURE_KEYS)[number]

/** The plan a locked feature needs. Only paid tiers are ever named as the target. */
export type RequiredPlan = 'starter' | 'lite' | 'pro'

export interface FeatureEntitlement {
  allowed: boolean
  /** Lowest plan that includes the feature while it is locked; null when allowed. */
  required_plan: RequiredPlan | null
}

export interface Entitlements {
  /** Stored plan of the account (free | starter | lite | pro | self-host). Members only. */
  current_plan: string
  /** Account owner AND the installation has billing. */
  can_change_plan: boolean
  features: Record<FeatureKey, FeatureEntitlement>
}

/** Answer for one feature, or null when no entitlements are known. */
export function featureEntitlement(
  entitlements: Entitlements | null | undefined,
  feature: FeatureKey,
): FeatureEntitlement | null {
  return entitlements?.features?.[feature] ?? null
}

/** The plan a locked feature needs, or null when it is allowed or unknown. */
export function requiredPlanFor(
  entitlements: Entitlements | null | undefined,
  feature: FeatureKey,
): RequiredPlan | null {
  const entry = featureEntitlement(entitlements, feature)
  return entry !== null && !entry.allowed ? entry.required_plan : null
}
