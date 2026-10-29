/**
 * PlanUpgradeLink — small "Upgrade to Pro" link for a plan-gated form field
 * (e.g. a disabled branding field with a `planUpgradeHint`). Generic and
 * reusable across every current/future staged field
 * (PlanPolicy::ALL_BRANDING_FIELDS) — not tied to any specific field.
 *
 * Defensive by construction: renders nothing when this installation has no
 * billing surface at all (Community/self-host — `features.billing` is only
 * ever set to `true` by the Cloud extension, see CloudExtension.php). A
 * caller therefore never needs its own feature check.
 *
 * Only the account owner may change the plan (/admin/billing is owner-only).
 * Every other role gets a plain note instead of a link that would lead to
 * "No access". The role comes from the bootstrap viewer (lib/viewer.ts).
 *
 * P-38: the link names the tier that unlocks the feature ("available from Lite" /
 * "available from Pro"). The tier comes from an explicit `requiredPlan` (the admin
 * branding form, which has its own fresh entitlements) or, for a `feature`, from
 * the viewer's entitlements. Without either, the generic `label` is shown.
 */

import { ExternalLink } from 'lucide-react'
import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import { type FeatureKey, type RequiredPlan, requiredPlanFor } from '../lib/entitlements'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'
import { getViewerEntitlements, getViewerRole } from '../lib/viewer'

interface PlanUpgradeLinkProps {
  /** Localized link text, used when no tier is known, e.g. t('planUpgradeLinkLabel'). */
  label: string
  /** Locked feature: its required tier is read from the viewer's entitlements. */
  feature?: FeatureKey
  /** Explicit required tier (wins over `feature`), e.g. from the branding GET. */
  requiredPlan?: RequiredPlan | null
}

export function PlanUpgradeLink({ label, feature, requiredPlan }: PlanUpgradeLinkProps) {
  const t = useT('common')
  if (getFeatures().billing !== true) return null

  const tier =
    requiredPlan !== undefined
      ? requiredPlan
      : feature !== undefined
        ? requiredPlanFor(getViewerEntitlements(), feature)
        : null
  const text =
    tier === 'pro'
      ? t('planAvailableFromPro')
      : tier === 'lite'
        ? t('planAvailableFromLite')
        : tier === 'starter'
          ? t('planAvailableFromStarter')
          : label

  if (getViewerRole() !== 'owner') {
    return <span className="text-vp-text-secondary">{t('planChangeOwnerOnly')}</span>
  }

  return (
    <Link
      to={accountPath('/admin/billing')}
      className="inline-flex items-center gap-1 font-medium text-vp-accent hover:underline"
    >
      {text}
      <ExternalLink size={12} aria-hidden="true" />
    </Link>
  )
}
