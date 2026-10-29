import { useCallback, useRef, useState } from 'react'
import { saveVoteReason, type VoteReasonChoice } from '../lib/api/ideas'
import {
  normalizeNoteDraft,
  type VoteReasonDraftError,
  voteReasonDraftError,
} from '../lib/voteReason'

type VoteReasonStatus = 'idle' | 'saving' | 'saved' | 'error'

export interface VoteReasonFollowUpState {
  /** Idea the follow-up is open for, or null when closed. */
  ideaId: number | null
  reason: VoteReasonChoice | null
  note: string
  status: VoteReasonStatus
  /** Client-side or server-side problem, mapped to a dictionary key by the caller. */
  error: VoteReasonDraftError | 'saveFailed' | null
}

const CLOSED: VoteReasonFollowUpState = {
  ideaId: null,
  reason: null,
  note: '',
  status: 'idle',
  error: null,
}

/**
 * State of the optional "why against?" follow-up (P-03). Opened after a
 * successful vote against; skipping closes it and changes nothing else.
 * Saving sends only the reason and note for the caller's own down vote; the
 * server refuses otherwise (409), so a stale panel cannot attach a reason
 * to a different vote.
 */
export function useVoteReasonFollowUp(boardSlug: string) {
  const [state, setState] = useState<VoteReasonFollowUpState>(CLOSED)
  // Save reads the latest draft through a ref, so a click never uses stale text.
  const stateRef = useRef(state)
  stateRef.current = state

  const open = useCallback((ideaId: number) => {
    setState({ ...CLOSED, ideaId })
  }, [])

  const setReason = useCallback((reason: VoteReasonChoice) => {
    setState((s) => ({
      ...s,
      reason,
      error: null,
      status: s.status === 'saved' ? 'idle' : s.status,
    }))
  }, [])

  const setNote = useCallback((note: string) => {
    // Free text always comes with a category: typing selects "other" when none is chosen.
    setState((s) => ({
      ...s,
      note,
      error: null,
      reason: s.reason ?? (note !== '' ? 'other' : null),
    }))
  }, [])

  /** Skip (or dismiss): closes the panel, the vote stays as it is. */
  const skip = useCallback(() => {
    setState(CLOSED)
  }, [])

  const save = useCallback(async () => {
    const current = stateRef.current
    if (current.ideaId === null || current.status === 'saving') return
    const draftError = voteReasonDraftError(current.reason, current.note)
    if (draftError !== null || current.reason === null) {
      setState((s) => ({ ...s, error: draftError ?? 'reasonRequired', status: 'error' }))
      return
    }
    setState((s) => ({ ...s, status: 'saving', error: null }))
    try {
      await saveVoteReason(
        boardSlug,
        current.ideaId,
        current.reason,
        normalizeNoteDraft(current.note),
      )
      setState((s) => ({ ...s, status: 'saved', error: null }))
    } catch {
      setState((s) => ({ ...s, status: 'error', error: 'saveFailed' }))
    }
  }, [boardSlug])

  return { state, open, setReason, setNote, skip, save }
}
