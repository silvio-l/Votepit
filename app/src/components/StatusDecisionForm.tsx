import { Button, Select, TextArea, TextField } from '@votepit/ui/ds'
import { useState } from 'react'
import type { StatusDecisionInput } from '../lib/api/ideas'
import { useT } from '../lib/i18n/context'

/** Mirrors the server limits (Domain\StatusDecision): 1000 / 280 characters. */
const REASON_MAX = 1000
const NEXT_STEP_MAX = 280

interface StatusDecisionFormProps {
  /** Label of the status the moderator is about to set. */
  statusLabel: string
  /** True for the "declined" category: shows a gentle, non-blocking prompt for a reason. */
  declined: boolean
  /**
   * Board setting `status_reason_required`: save stays disabled while the
   * reason is blank. The server enforces the same rule (422
   * status_reason_required); this only mirrors it in the UI.
   */
  reasonRequired?: boolean
  /** Prefix of every test id; the idea page keeps the default `idea-decision`. */
  testIdPrefix?: string
  pending: boolean
  onSubmit: (decision: StatusDecisionInput) => void
  onCancel: () => void
}

/**
 * Moderator form that opens after a status is picked (P-01). Reason, next
 * step and responsible role are optional by default; with `reasonRequired`
 * (a board setting) the reason must be filled before the form can be saved. The soft prompt for
 * declined ideas is a DS `hint`, so it stays associated with the reason field.
 */
export function StatusDecisionForm({
  statusLabel,
  declined,
  reasonRequired = false,
  testIdPrefix = 'idea-decision',
  pending,
  onSubmit,
  onCancel,
}: StatusDecisionFormProps) {
  const t = useT('statusDecision')
  const [reason, setReason] = useState('')
  const [nextStep, setNextStep] = useState('')
  const [role, setRole] = useState<'' | 'team' | 'moderation'>('')

  const reasonMissing = reasonRequired && reason.trim() === ''
  const showSoftPrompt = declined && reason.trim() === ''
  let reasonHint: string | undefined
  if (reasonMissing) reasonHint = t('reasonRequiredHint')
  else if (showSoftPrompt) reasonHint = t('softPrompt')

  return (
    <form
      data-testid={`${testIdPrefix}-form`}
      className="mt-3 flex flex-col gap-3 rounded-vp-md border border-vp-rule bg-vp-surface p-3 sm:p-4"
      onSubmit={(e) => {
        e.preventDefault()
        if (pending || reasonMissing) return
        onSubmit({ reason, nextStep, responsibleRole: role })
      }}
    >
      <p className="vp-eyebrow">
        {t('formTitle')} · {statusLabel}
      </p>

      <TextArea
        testId={`${testIdPrefix}-reason`}
        label={t('reasonLabel')}
        placeholder={t('reasonPlaceholder')}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        rows={3}
        maxLength={REASON_MAX}
        required={reasonRequired}
        hint={reasonHint}
      />

      <TextField
        testId={`${testIdPrefix}-next-step`}
        label={t('nextStepLabel')}
        placeholder={t('nextStepPlaceholder')}
        value={nextStep}
        onChange={(e) => setNextStep(e.target.value)}
        maxLength={NEXT_STEP_MAX}
      />

      <Select
        testId={`${testIdPrefix}-role`}
        label={t('roleLabel')}
        value={role}
        onChange={(v) => setRole(v === 'team' || v === 'moderation' ? v : '')}
      >
        <option value="">{t('roleNone')}</option>
        <option value="team">{t('roleTeam')}</option>
        <option value="moderation">{t('roleModeration')}</option>
      </Select>

      <div className="flex flex-wrap items-center gap-2 max-sm:[&_button]:min-h-11">
        <Button
          type="submit"
          variant="primary"
          disabled={pending || reasonMissing}
          testId={`${testIdPrefix}-save`}
        >
          {t('saveDecision')}
        </Button>
        <Button
          type="button"
          variant="text"
          disabled={pending}
          testId={`${testIdPrefix}-cancel`}
          onClick={onCancel}
        >
          {t('cancelDecision')}
        </Button>
      </div>
    </form>
  )
}
