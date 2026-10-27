/**
 * Optional submission/voting windows (broaden-use-cases issue 04).
 *
 * The SERVER computes every phase state with its own clock; these helpers
 * only turn that state into display text and button locks. Nothing here
 * reads the browser clock to decide whether something is allowed — a
 * stale tab at worst shows an outdated hint, and the server still answers
 * 423 with a dedicated error key.
 */

import type { BoardPhasesInfo, PhaseWindow } from './api'

export type PhaseName = 'submissions' | 'voting'

/** The four 423 keys a closed window can produce. */
const PHASE_ERROR_KEYS = [
  'submissions_closed',
  'submissions_not_open_yet',
  'voting_closed',
  'voting_not_open_yet',
] as const

export type PhaseErrorKey = (typeof PHASE_ERROR_KEYS)[number]

export function isPhaseErrorKey(key: unknown): key is PhaseErrorKey {
  return typeof key === 'string' && (PHASE_ERROR_KEYS as readonly string[]).includes(key)
}

/** True when the board has at least one bound — otherwise nothing is shown. */
function hasPhaseWindow(window: PhaseWindow | undefined): boolean {
  return window !== undefined && (window.opens_at !== null || window.closes_at !== null)
}

/** Whether the server says the phase does not accept actions right now. */
export function phaseBlocked(phases: BoardPhasesInfo | undefined, phase: PhaseName): boolean {
  const state = phases?.[phase].state
  return state !== undefined && state !== 'open'
}

/**
 * Message key (in the `common` dictionary) + date for one phase, or null
 * when the phase has no bound at all (default boards render nothing).
 */
export function phaseMessage(
  window: PhaseWindow | undefined,
  phase: PhaseName,
): { key: PhaseMessageKey; date: string | null } | null {
  if (window === undefined || !hasPhaseWindow(window)) return null
  const prefix = phase === 'submissions' ? 'phase.submissions' : 'phase.voting'
  switch (window.state) {
    case 'upcoming':
      return window.opens_at !== null
        ? { key: `${prefix}UpcomingOn`, date: window.opens_at }
        : { key: `${prefix}NotOpenYet`, date: null }
    case 'closed':
      return window.closes_at !== null
        ? { key: `${prefix}ClosedOn`, date: window.closes_at }
        : { key: `${prefix}Closed`, date: null }
    default:
      return window.closes_at !== null
        ? { key: `${prefix}OpenUntil`, date: window.closes_at }
        : { key: `${prefix}Open`, date: null }
  }
}

/** Short reason (no date) for a locked button or a 423 answer. */
export function phaseReasonKey(key: PhaseErrorKey): PhaseMessageKey {
  switch (key) {
    case 'submissions_closed':
      return 'phase.submissionsClosed'
    case 'submissions_not_open_yet':
      return 'phase.submissionsNotOpenYet'
    case 'voting_closed':
      return 'phase.votingClosed'
    default:
      return 'phase.votingNotOpenYet'
  }
}

/** Reason key for a blocked phase, derived from the server state. */
export function blockedReasonKey(
  phases: BoardPhasesInfo | undefined,
  phase: PhaseName,
): PhaseMessageKey | null {
  if (!phaseBlocked(phases, phase)) return null
  const upcoming = phases?.[phase].state === 'upcoming'
  if (phase === 'submissions') {
    return upcoming ? 'phase.submissionsNotOpenYet' : 'phase.submissionsClosed'
  }
  return upcoming ? 'phase.votingNotOpenYet' : 'phase.votingClosed'
}

export type PhaseMessageKey =
  | 'phase.submissionsOpen'
  | 'phase.submissionsOpenUntil'
  | 'phase.submissionsUpcomingOn'
  | 'phase.submissionsNotOpenYet'
  | 'phase.submissionsClosed'
  | 'phase.submissionsClosedOn'
  | 'phase.votingOpen'
  | 'phase.votingOpenUntil'
  | 'phase.votingUpcomingOn'
  | 'phase.votingNotOpenYet'
  | 'phase.votingClosed'
  | 'phase.votingClosedOn'

// ── Admin form: <input type="datetime-local"> ↔ ISO with offset ──────────────

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

/**
 * ISO instant (from the server, UTC) → the value of a datetime-local input,
 * in the browser's own time zone (which the form names explicitly).
 */
export function toLocalInput(iso: string | null): string {
  if (iso === null) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * datetime-local value (browser time zone) → ISO 8601 with an explicit
 * offset, the only format the server accepts. Empty → null (clears the
 * bound); an unparsable value is passed through so the server rejects it
 * with a field error instead of the SPA silently dropping it.
 */
export function fromLocalInput(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(trimmed)
  if (m === null) return trimmed
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]))
  if (Number.isNaN(d.getTime())) return trimmed
  const offset = -d.getTimezoneOffset()
  const sign = offset >= 0 ? '+' : '-'
  const abs = Math.abs(offset)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:00${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`
}

/** The browser's IANA time zone name, shown next to the inputs. */
export function browserTimeZone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
}
