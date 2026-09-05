/**
 * Per-browser "already celebrated this" bookkeeping for the Celebration
 * component (see @votepit/ui) — purely a UX nicety, not business data: the
 * server has no concept of "has this browser seen the confetti yet", it
 * only ever reports the current qualified-referral count / reward / idea
 * totals. Same defensive try/catch convention as ActivationChecklist's and
 * referral.ts's localStorage/sessionStorage use — storage can throw
 * (private browsing, quota) and must never break the page it decorates.
 */

const SEEN_KEY = 'vp_celebrations_seen'
const COUNT_PREFIX = 'vp_celebration_count:'

function readSeen(): Set<string> {
  try {
    const raw = localStorage.getItem(SEEN_KEY)
    return new Set(raw ? (JSON.parse(raw) as string[]) : [])
  } catch {
    return new Set()
  }
}

function writeSeen(seen: Set<string>): void {
  try {
    localStorage.setItem(SEEN_KEY, JSON.stringify([...seen]))
  } catch {
    // Storage unavailable — the one-shot celebration just replays later.
  }
}

/** Whether the one-shot celebration for `key` (e.g. a specific milestone) has already played in this browser. */
export function hasCelebrated(key: string): boolean {
  return readSeen().has(key)
}

/** Marks `key`'s one-shot celebration as played, so it never fires again in this browser. */
export function markCelebrated(key: string): void {
  const seen = readSeen()
  if (seen.has(key)) return
  seen.add(key)
  writeSeen(seen)
}

/**
 * Tracks the last-seen value for `key` and reports whether `count` is a NEW
 * increase since then — for repeatable progress events (e.g. "a referral
 * just qualified") rather than a one-shot celebration. The very first
 * observation is never reported as an increase (otherwise a first-ever visit
 * would celebrate progress that already existed before this browser saw the
 * page) — it only records the starting point.
 */
export function isNewCountIncrease(key: string, count: number): boolean {
  const storageKey = `${COUNT_PREFIX}${key}`
  let last: number | null = null
  try {
    const raw = localStorage.getItem(storageKey)
    last = raw === null ? null : Number(raw)
  } catch {
    return false
  }
  try {
    localStorage.setItem(storageKey, String(count))
  } catch {
    // Storage unavailable — treated as "no increase" below.
  }
  return last !== null && count > last
}

/**
 * Tracks the last-seen value for `key` and reports whether `count` just
 * crossed `threshold` (last-seen was below it, current is at/above it) —
 * same crossing shape as voteFx's isBigVoteMoment, for numeric board
 * milestones (e.g. "10 ideas"). The first observation never crosses
 * (mirrors isNewCountIncrease): a first-ever visit to a board that already
 * has 10+ ideas shouldn't retroactively celebrate a milestone that happened
 * before this browser ever looked.
 */
export function crossedThreshold(key: string, threshold: number, count: number): boolean {
  const storageKey = `${COUNT_PREFIX}${key}`
  let last: number | null = null
  try {
    const raw = localStorage.getItem(storageKey)
    last = raw === null ? null : Number(raw)
  } catch {
    return false
  }
  try {
    localStorage.setItem(storageKey, String(count))
  } catch {
    // Storage unavailable — treated as "did not cross" below.
  }
  return last !== null && last < threshold && count >= threshold
}
