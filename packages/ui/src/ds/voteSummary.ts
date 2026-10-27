/**
 * Vote arithmetic shared by landing and board (design concept §5 "Abstimmung").
 *
 * Up and down votes stay separate numbers; net and approval are derived from
 * them and never replace them. Approval is the share of up votes among the
 * up/down votes only — no invented abstentions, no participation rate, and no
 * "consensus" label derived from a threshold.
 */
export interface VoteSummary {
  up: number
  down: number
  /** up + down */
  total: number
  /** up − down */
  net: number
  /** Unrounded share of up votes in percent; `null` when nobody has voted. */
  approval: number | null
  /**
   * Whole-percent display value; `null` when nobody has voted. Owner decision L-03:
   * never 100 while down votes exist and never 0 while up votes exist (see displayApproval).
   */
  approvalRounded: number | null
  /** Distinct voting identities (defaults to total). */
  voters: number
  /**
   * Low participation (P-02, owner Q1 = 1a): at least one vote and either fewer than
   * FEW_VOTES_THRESHOLD votes or fewer than FEW_VOTERS_THRESHOLD distinct voters. Zero votes
   * are false: the empty state already says so. Under the vote identity model (one row per
   * account or guest browser per idea) voters equal total, so the voter branch can only fire
   * when a caller passes a smaller voter count.
   */
  fewVotes: boolean
}

/** Owner decision 2026-10-22: below this many votes the approval carries a "few votes yet" hint. */
export const FEW_VOTES_THRESHOLD = 10
/** P-02 owner decision Q1 = 1a (2026-10-22): below this many distinct voters the hint applies too. */
export const FEW_VOTERS_THRESHOLD = 5

/**
 * Whole-percent approval for display (owner decision L-03, 2026-10-22). Plain rounding
 * would show 100 % with a down vote present (199:1) or 0 % with an up vote present (1:300).
 * Those two are clamped to 99 and 1, so the display never claims unanimity or a total
 * rejection that the counts do not support. Zero votes stay `null`.
 */
function displayApproval(up: number, down: number, approval: number | null): number | null {
  if (approval === null) return null
  let rounded = Math.round(approval)
  if (down > 0 && rounded >= 100) rounded = 99
  if (up > 0 && rounded <= 0) rounded = 1
  return rounded
}

/**
 * Throws a RangeError for counts that are not non-negative integers, or for a voter count
 * outside 0..total (a data error, not a zero state). `voters` defaults to total.
 */
export function summarizeVotes(up: number, down: number, voters?: number): VoteSummary {
  for (const [label, count] of [
    ['up', up],
    ['down', down],
  ] as const) {
    if (!Number.isInteger(count) || count < 0) {
      throw new RangeError(`${label} votes must be a non-negative integer, got ${count}`)
    }
  }
  const total = up + down
  const voterCount = voters ?? total
  if (!Number.isInteger(voterCount) || voterCount < 0 || voterCount > total) {
    throw new RangeError(`voters must be an integer between 0 and ${total}, got ${voterCount}`)
  }
  const approval = total === 0 ? null : (up / total) * 100
  return {
    up,
    down,
    total,
    net: up - down,
    approval,
    approvalRounded: displayApproval(up, down, approval),
    voters: voterCount,
    fewVotes: total > 0 && (total < FEW_VOTES_THRESHOLD || voterCount < FEW_VOTERS_THRESHOLD),
  }
}

const MINUS = '−'

/** Signed net value with a typographic minus: "+18", "−6", "0". */
export function formatNet(net: number, locale = 'de'): string {
  const digits = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Math.abs(net))
  if (net > 0) return `+${digits}`
  if (net < 0) return `${MINUS}${digits}`
  return digits
}

/** "80 %" in German (narrow no-break space per DIN 5008 usage), "80%" in English; `null` stays `null`. */
export function formatApproval(rounded: number | null, locale = 'de'): string | null {
  if (rounded === null) return null
  return locale.startsWith('de') ? `${rounded} %` : `${rounded}%`
}

/** Width split for the two-segment meter; empty when there are no votes (never a fake 0 %). */
export function meterSegments(summary: VoteSummary): { up: number; down: number } | null {
  if (summary.total === 0) return null
  return { up: summary.up / summary.total, down: summary.down / summary.total }
}
