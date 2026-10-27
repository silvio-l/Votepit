import { Button, ButtonLink, InlineNotice } from '@votepit/ui/ds'
import { X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { trackEvent } from '../lib/analytics'
import { getEdition } from '../lib/edition'
import { useT } from '../lib/i18n/context'
import {
  markStarFeedbackPromptDone,
  markStarFeedbackPromptSnoozed,
  shouldShowStarFeedbackPrompt,
} from '../lib/starFeedbackPrompt'

type Step = 'hidden' | 'rate' | 'star'

/**
 * Soft, dismissible "how's it going" nudge for account admins/owners — never
 * for anonymous voters (mounted only in AdminShell, gated on `canModerate`).
 * A positive rating additionally offers a GitHub-star ask, but only on a
 * self-hosted (community-edition) instance: cloud customers have no reason
 * to know the repo exists. Client-only and best-effort (localStorage) — this
 * is a nudge, not a feedback channel; a neutral/negative rating is left as
 * a soft signal only, the existing support form is the real channel.
 */
export function StarFeedbackPrompt() {
  const t = useT('common')
  const [step, setStep] = useState<Step>('hidden')

  useEffect(() => {
    if (shouldShowStarFeedbackPrompt()) setStep('rate')
  }, [])

  if (step === 'hidden') return null

  const dismiss = () => {
    markStarFeedbackPromptSnoozed()
    setStep('hidden')
  }

  const rate = (value: 'good' | 'ok' | 'bad') => {
    trackEvent('feedback', 'star_prompt_rating', value)
    if (value === 'good' && getEdition() === 'community') {
      setStep('star')
      return
    }
    markStarFeedbackPromptDone()
    setStep('hidden')
  }

  const finishStar = () => {
    markStarFeedbackPromptDone()
    setStep('hidden')
  }

  return (
    <InlineNotice
      tone="info"
      title={step === 'rate' ? t('starFeedback.ratingTitle') : t('starFeedback.starTitle')}
      className="mb-6"
      action={
        <button
          type="button"
          aria-label={t('action.close')}
          data-testid="star-feedback-close"
          onClick={dismiss}
          className="rounded-vp-sm p-1 text-vp-text-secondary hover:bg-vp-ink-softer"
        >
          <X size={16} />
        </button>
      }
    >
      {step === 'rate' ? (
        <div className="flex flex-wrap items-center gap-2 pt-1">
          <Button
            onClick={() => rate('good')}
            variant="secondary"
            size="default"
            testId="star-feedback-good"
          >
            🙂 {t('starFeedback.good')}
          </Button>
          <Button
            onClick={() => rate('ok')}
            variant="secondary"
            size="default"
            testId="star-feedback-ok"
          >
            😐 {t('starFeedback.ok')}
          </Button>
          <Button
            onClick={() => rate('bad')}
            variant="secondary"
            size="default"
            testId="star-feedback-bad"
          >
            🙁 {t('starFeedback.bad')}
          </Button>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-3 pt-1">
          <ButtonLink
            href="https://github.com/silvio-l/Votepit"
            target="_blank"
            rel="noreferrer"
            onClick={finishStar}
            variant="primary"
            testId="star-feedback-star-cta"
          >
            ⭐ {t('starFeedback.starCta')}
          </ButtonLink>
          <button
            type="button"
            onClick={finishStar}
            data-testid="star-feedback-no-thanks"
            className="text-vp-sm text-vp-text-secondary underline"
          >
            {t('starFeedback.noThanks')}
          </button>
        </div>
      )}
    </InlineNotice>
  )
}
