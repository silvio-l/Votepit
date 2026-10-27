/**
 * One wizard step on the page's ruled sheet: the progress rail on top, the
 * step's own content in the body, its actions in the sheet foot. Shared by
 * every SetupWizard step so the layout and the reduced-motion rule live in
 * one place.
 */

import { Section, Steps } from '@votepit/ui/ds'
import type { ReactNode } from 'react'

export type WizardStep = 'welcome' | 'board' | 'look' | 'ready'

const WIZARD_STEPS: WizardStep[] = ['welcome', 'board', 'look', 'ready']

export function StepShell({
  step,
  labels,
  ariaLabel,
  footer,
  children,
}: {
  step: WizardStep
  labels: string[]
  ariaLabel: string
  footer: ReactNode
  children: ReactNode
}) {
  const activeIndex = WIZARD_STEPS.indexOf(step)
  return (
    <div data-testid={`setup-wizard-step-${step}`}>
      <Section
        testId="setup-wizard-step-section"
        key={step}
        emphasis="ruled"
        footer={footer}
        className="animate-vp-rise motion-reduce:animate-none"
        bodyClassName="sm:p-8"
      >
        <div data-testid="setup-wizard-steps">
          <Steps
            items={labels.map((label) => ({ label }))}
            current={activeIndex}
            ariaLabel={ariaLabel}
            className="mb-8"
          />
        </div>
        {children}
      </Section>
    </div>
  )
}
