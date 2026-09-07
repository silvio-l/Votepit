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
 */

import { ExternalLink } from 'lucide-react'
import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import { getFeatures } from '../lib/features'

interface PlanUpgradeLinkProps {
  /** Localized link text, e.g. t('planUpgradeLinkLabel'). */
  label: string
}

export function PlanUpgradeLink({ label }: PlanUpgradeLinkProps) {
  if (getFeatures().billing !== true) return null

  return (
    <Link
      to={accountPath('/admin/billing')}
      className="inline-flex items-center gap-1 font-medium text-vp-accent hover:underline"
    >
      {label}
      <ExternalLink size={12} aria-hidden="true" />
    </Link>
  )
}
