import { VoteReasonPanel } from '@votepit/ui/ds'
import { useCallback, useEffect, useMemo } from 'react'
import type { useVoteReasonFollowUp } from '../hooks/useVoteReasonFollowUp'
import { useT } from '../lib/i18n/context'
import { buildVoteReasonPanelLabels, VOTE_REASON_NOTE_MAX } from '../lib/voteReason'

/** Milliseconds the "saved" confirmation stays before the panel closes itself. */
const SAVED_VISIBLE_MS = 5000

type FollowUp = ReturnType<typeof useVoteReasonFollowUp>

/**
 * The optional "why against?" follow-up (P-03) wired to its state hook. It is
 * never a blocking dialog: the vote is already saved, and skipping leaves it as is.
 */
export function VoteReasonFollowUp({
  testId,
  followUp,
  returnFocusTestId,
}: {
  testId: string
  followUp: FollowUp
  /** test id of the For/Against button that opened the follow-up; focus returns there on close. */
  returnFocusTestId: string
}) {
  const t = useT('voteReasons')
  const { state, setReason, setNote, skip, save } = followUp

  // Escape and "Skip" close the panel and move focus back to the vote button.
  const close = useCallback(() => {
    skip()
    document.querySelector<HTMLElement>(`[data-testid="${returnFocusTestId}"]`)?.focus()
  }, [skip, returnFocusTestId])

  const labels = useMemo(() => buildVoteReasonPanelLabels(t), [t])

  useEffect(() => {
    if (state.status !== 'saved') return
    const timer = window.setTimeout(skip, SAVED_VISIBLE_MS)
    return () => window.clearTimeout(timer)
  }, [state.status, skip])

  const errorText = state.error === null ? null : t(state.error)

  return (
    <VoteReasonPanel
      testId={testId}
      reason={state.reason}
      note={state.note}
      maxLength={VOTE_REASON_NOTE_MAX}
      status={state.status}
      error={errorText}
      labels={labels}
      onReasonChange={setReason}
      onNoteChange={setNote}
      onSave={() => void save()}
      onSkip={close}
    />
  )
}
