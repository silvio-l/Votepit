/**
 * "next steps" panel of the wizard's ready step (Q22 guided tour). Each
 * card deep-links into one settings section of the new board, with one plain
 * sentence of benefit. Locked sections keep their card, badged "available from Lite" /
 * "available from Pro"; the section itself explains the upgrade path.
 *
 * Cards call onOpen with their target instead of navigating directly, so the
 * parent can complete onboarding first (the wizard must not reappear).
 */

import { Badge } from '@votepit/ui/ds'
import { ArrowRight } from 'lucide-react'
import { requiredPlanFor } from '../lib/entitlements'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'
import { featureLocked, NEXT_STEPS, type NextStepId, nextStepTarget } from '../lib/onboarding'
import { getViewerEntitlements } from '../lib/viewer'

const COPY: Record<NextStepId, { title: string; body: string }> = {
  appearance: { title: 'nextAppearanceTitle', body: 'nextAppearanceBody' },
  images: { title: 'nextImagesTitle', body: 'nextImagesBody' },
  branding: { title: 'nextBrandingTitle', body: 'nextBrandingBody' },
  members: { title: 'nextMembersTitle', body: 'nextMembersBody' },
  share: { title: 'nextShareTitle', body: 'nextShareBody' },
  moderation: { title: 'nextModerationTitle', body: 'nextModerationBody' },
}

interface SetupWizardNextStepsProps {
  boardSlug: string
  onOpen: (target: string) => void
}

export function SetupWizardNextSteps({ boardSlug, onOpen }: SetupWizardNextStepsProps) {
  const t = useT('setupWizard')
  const billing = getFeatures().billing === true
  const entitlements = getViewerEntitlements()

  return (
    <section
      aria-labelledby="setup-wizard-next-heading"
      data-testid="setup-wizard-next"
      className="mt-8 border-t border-vp-border-subtle pt-6"
    >
      <h3 id="setup-wizard-next-heading" className="m-0 text-vp-md font-semibold text-vp-ink">
        {t('nextTitle')}
      </h3>
      <p className="mt-1 mb-4 text-vp-sm text-vp-text-secondary">{t('nextBody')}</p>
      <ul className="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2">
        {NEXT_STEPS.map((step) => {
          const locked = step.gate !== null && featureLocked(entitlements, step.gate, billing)
          const tier = step.gate !== null ? requiredPlanFor(entitlements, step.gate) : null
          return (
            <li key={step.id}>
              <button
                type="button"
                onClick={() => onOpen(nextStepTarget(boardSlug, step))}
                data-testid={`setup-wizard-next-${step.id}`}
                className="flex h-full min-h-11 w-full flex-col items-start gap-1 rounded-vp-md border border-vp-border-subtle bg-vp-surface p-4 text-left transition-colors hover:border-vp-border-strong motion-reduce:transition-none"
              >
                <span className="flex w-full flex-wrap items-center justify-between gap-2">
                  <span className="inline-flex items-center gap-1.5 font-semibold text-vp-ink">
                    {t(COPY[step.id].title)}
                    <ArrowRight size={12} aria-hidden="true" />
                  </span>
                  {locked && (
                    <Badge tone="accent" testId={`setup-wizard-next-${step.id}-badge`}>
                      {tier === 'pro' ? t('badgeFromPro') : t('badgeFromLite')}
                    </Badge>
                  )}
                </span>
                <span className="text-vp-sm text-vp-text-secondary">{t(COPY[step.id].body)}</span>
              </button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
