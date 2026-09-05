/**
 * Local, per-browser state for the account-admin "how's it going" prompt
 * (StarFeedbackPrompt). Deliberately client-only (no backend endpoint): this
 * is a soft nudge, not feedback data collection — a neutral/negative rating
 * still points the admin at the real support form (SupportPage), which IS
 * persisted server-side. Never shown to anonymous voters (mounted only in
 * AdminShell, gated on `canModerate`).
 */

const STORAGE_KEY = 'vp_star_feedback_prompt_v1'
const FIRST_PROMPT_AFTER_MS = 3 * 24 * 60 * 60 * 1000 // 3 days after first admin visit
const SNOOZE_MS = 14 * 24 * 60 * 60 * 1000 // "later" (the × dismiss) asks again in 2 weeks

interface State {
  firstSeenAt: number
  dismissedUntil: number | null
  done: boolean
}

function readState(): State {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw) as State
  } catch {
    // localStorage unavailable (private mode, blocked) — fall through to a
    // fresh, unpersisted state; the prompt simply won't remember across
    // reloads for this visitor, which is an acceptable degradation here.
  }
  const fresh: State = { firstSeenAt: Date.now(), dismissedUntil: null, done: false }
  writeState(fresh)
  return fresh
}

function writeState(state: State): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch {
    // best-effort only
  }
}

export function shouldShowStarFeedbackPrompt(): boolean {
  const state = readState()
  if (state.done) return false
  if (state.dismissedUntil !== null && Date.now() < state.dismissedUntil) return false
  return Date.now() - state.firstSeenAt >= FIRST_PROMPT_AFTER_MS
}

export function markStarFeedbackPromptSnoozed(): void {
  writeState({ ...readState(), dismissedUntil: Date.now() + SNOOZE_MS })
}

export function markStarFeedbackPromptDone(): void {
  writeState({ ...readState(), done: true })
}
