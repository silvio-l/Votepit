import { afterEach, describe, expect, it, vi } from 'vitest'
import type { BoardPhasesInfo, PhaseWindow } from './api'
import {
  blockedReasonKey,
  fromLocalInput,
  isPhaseErrorKey,
  phaseBlocked,
  phaseMessage,
  phaseReasonKey,
  toLocalInput,
} from './boardPhases'

const OPEN: PhaseWindow = { state: 'open', opens_at: null, closes_at: null }

function phases(submissions: PhaseWindow, voting: PhaseWindow = OPEN): BoardPhasesInfo {
  return { submissions, voting }
}

afterEach(() => {
  vi.useRealTimers()
})

describe('phaseMessage', () => {
  it('renders nothing for a phase without any bound (default boards)', () => {
    expect(phaseMessage(OPEN, 'voting')).toBeNull()
    expect(phaseMessage(undefined, 'voting')).toBeNull()
  })

  it('maps upcoming/open/closed to their message with the relevant date', () => {
    const opens = '2026-05-01T08:00:00Z'
    const closes = '2026-05-31T22:00:00Z'
    expect(
      phaseMessage({ state: 'upcoming', opens_at: opens, closes_at: closes }, 'voting'),
    ).toEqual({ key: 'phase.votingUpcomingOn', date: opens })
    expect(
      phaseMessage({ state: 'open', opens_at: opens, closes_at: closes }, 'submissions'),
    ).toEqual({ key: 'phase.submissionsOpenUntil', date: closes })
    expect(phaseMessage({ state: 'closed', opens_at: opens, closes_at: closes }, 'voting')).toEqual(
      { key: 'phase.votingClosedOn', date: closes },
    )
    expect(phaseMessage({ state: 'open', opens_at: opens, closes_at: null }, 'voting')).toEqual({
      key: 'phase.votingOpen',
      date: null,
    })
  })

  it('trusts the server state, never the browser clock', () => {
    // Browser clock far past the close date — the server still says upcoming.
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2030-01-01T00:00:00Z'))
    const window: PhaseWindow = {
      state: 'upcoming',
      opens_at: '2026-05-01T08:00:00Z',
      closes_at: '2026-05-31T22:00:00Z',
    }
    expect(phaseMessage(window, 'voting')?.key).toBe('phase.votingUpcomingOn')
    expect(phaseBlocked(phases(OPEN, window), 'voting')).toBe(true)
    expect(phaseBlocked(phases(OPEN, window), 'submissions')).toBe(false)
  })
})

describe('blocked state + reasons', () => {
  it('derives the reason from the server state', () => {
    const closed: PhaseWindow = {
      state: 'closed',
      opens_at: null,
      closes_at: '2026-01-01T00:00:00Z',
    }
    const upcoming: PhaseWindow = {
      state: 'upcoming',
      opens_at: '2027-01-01T00:00:00Z',
      closes_at: null,
    }
    expect(blockedReasonKey(phases(closed), 'submissions')).toBe('phase.submissionsClosed')
    expect(blockedReasonKey(phases(upcoming), 'submissions')).toBe('phase.submissionsNotOpenYet')
    expect(blockedReasonKey(phases(OPEN, closed), 'voting')).toBe('phase.votingClosed')
    expect(blockedReasonKey(phases(OPEN, upcoming), 'voting')).toBe('phase.votingNotOpenYet')
    expect(blockedReasonKey(phases(OPEN), 'voting')).toBeNull()
    expect(blockedReasonKey(undefined, 'voting')).toBeNull()
  })

  it('recognises exactly the four 423 window keys, never board_frozen', () => {
    expect(isPhaseErrorKey('submissions_closed')).toBe(true)
    expect(isPhaseErrorKey('voting_not_open_yet')).toBe(true)
    expect(isPhaseErrorKey('board_frozen')).toBe(false)
    expect(isPhaseErrorKey(undefined)).toBe(false)
    expect(phaseReasonKey('submissions_not_open_yet')).toBe('phase.submissionsNotOpenYet')
    expect(phaseReasonKey('voting_closed')).toBe('phase.votingClosed')
  })
})

describe('datetime-local conversion', () => {
  it('sends the local input with an explicit offset and reads it back unchanged', () => {
    const iso = fromLocalInput('2026-04-01T10:30')
    expect(iso).toMatch(/^2026-04-01T10:30:00[+-]\d{2}:\d{2}$/)
    expect(new Date(iso as string).getTime()).toBe(new Date(2026, 3, 1, 10, 30).getTime())
    expect(toLocalInput(new Date(2026, 3, 1, 10, 30).toISOString())).toBe('2026-04-01T10:30')
  })

  it('treats an empty input as "no bound" and passes garbage through for the server to reject', () => {
    expect(fromLocalInput('')).toBeNull()
    expect(fromLocalInput('  ')).toBeNull()
    expect(fromLocalInput('tomorrow')).toBe('tomorrow')
    expect(toLocalInput(null)).toBe('')
  })
})
