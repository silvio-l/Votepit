/**
 * Vote-cast visual effect math — framework-agnostic (no React, no DOM), so it
 * can be imported both by VoteWidget.tsx (the real React board) and by the
 * landing page's ExampleBoard.astro (a vanilla-JS island with no React
 * runtime). This is the SINGLE source of truth for what a vote-cast effect
 * looks like: both call sites render the exact same particle counts,
 * distances, sizes and delays instead of hand-copied, independently-drifting
 * formulas.
 */

/** Net-vote thresholds that get a bigger confetti burst instead of the small per-upvote particle burst. */
export const VOTE_MILESTONES = [10, 50, 100] as const

/**
 * How long a spawned effect wrapper stays in the DOM before it is torn down,
 * in ms — comfortably past the longest animation (850ms) plus the longest
 * per-piece start delay. Both call sites use this to remove the wrapper
 * again, which is what drops the `.vp-vote-fx` marker the vote column's
 * z-index lift keys off (see tokens.css `.vp-vote-stack`).
 */
export const VOTE_FX_LIFETIME_MS = 1100

/**
 * Deterministic 0..1 noise from an integer seed. Deliberately NOT Math.random:
 * the two call sites must be able to render byte-identical geometry, and a
 * React render must not reshuffle the burst it is already mid-animation on.
 */
function noise(seed: number): number {
  const x = Math.sin(seed * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}

/**
 * Which of the three upvote tones a piece takes. The burst spawns on top of
 * an already solid-green button and then travels onto a near-white sheet, so
 * no single colour reads well for the whole flight: `light` (white, with a
 * green-tinted edge shadow) carries the first, most-visible instant on the
 * button, `strong`/`base` carry the rest of the flight against the page.
 */
export type BurstTone = 'strong' | 'base' | 'light'

const TONES: readonly BurstTone[] = ['strong', 'light', 'base']

export interface BurstPiece {
  /** Horizontal travel, px (positive = right). */
  dx: number
  /** Travel to the arc's APEX, px — negative, i.e. upward. */
  dy: number
  /** Extra downward drift after the apex: the gravity half of the arc, px. */
  fall: number
  /** Rendered diameter, px. */
  size: number
  /** Start offset, ms — staggered so the burst sprays instead of moving as one rigid ring. */
  delay: number
  tone: BurstTone
}

/**
 * The upvote burst: a fan of round pieces thrown UPWARD out of the box, each
 * arcing over its own apex and falling back a little as it fades — the mirror
 * image of the downvote's fall, so the two directions read as opposites at a
 * glance.
 *
 * Everything a viewer would otherwise perceive as mechanical is varied per
 * piece: the fan angle is jittered off its even spacing, the distance, the
 * diameter and the start delay all differ. An evenly-spaced ring of
 * identically-sized dots leaving in perfect lockstep reads as a loading
 * spinner, not as a celebration — that was the actual "not premium" problem,
 * not the piece shape.
 *
 * `big` (first vote ever / a milestone) gets a noticeably larger, denser,
 * longer-reaching burst than a routine upvote.
 */
export function upvoteBurstPieces(big: boolean): BurstPiece[] {
  const count = big ? 24 : 14
  const reach = big ? 118 : 74
  return Array.from({ length: count }, (_, i) => {
    const jitter = noise(i + 1)
    const spread = noise(i + 41)
    const gravity = noise(i + 91)
    const scale = noise(i + 137)
    // Evenly seeded across a 160° upward fan, then jittered off that grid.
    // Screen coordinates: negative y is up, so the fan lives at -170°..-10°.
    const t = (i + 0.5) / count
    const angle = ((-170 + t * 160 + (jitter - 0.5) * 18) * Math.PI) / 180
    const dist = reach * (0.62 + spread * 0.45)
    return {
      dx: Math.round(Math.cos(angle) * dist),
      dy: Math.round(Math.sin(angle) * dist),
      fall: Math.round(14 + gravity * (big ? 34 : 24)),
      size: Math.round((big ? 5 : 4) + scale * (big ? 5 : 4)),
      delay: Math.round(jitter * (big ? 90 : 60)),
      tone: TONES[i % TONES.length]!,
    }
  })
}

export interface DownDropPiece {
  dx: number
  dy: number
  /** Fixed rotation, deg — aligns the chevron with its own travel direction. */
  rot: number
  /** Rendered edge length, px. */
  size: number
  /** Start offset, ms. */
  delay: number
}

/**
 * The downvote drop: a short cascade of downward chevrons that sink and fade,
 * weighted toward falling (dy biased well past dx) instead of radiating
 * outward evenly like the upvote fan. Each chevron's rotation is derived from
 * its own (dx, dy) travel direction and held constant for the whole animation,
 * so it always visibly points the way it is moving.
 *
 * Two things that look like polish but are load-bearing:
 * - The count is deliberately small and the delays are staggered from the
 *   middle outward. Ten pieces leaving the same origin at the same instant
 *   overlap into ONE white blob for the first ~150ms, which is what read as
 *   "a smudge / an arrow pointing the wrong way" rather than as arrows.
 * - The mark itself is drawn as an open, stroked chevron (see both call
 *   sites), not as a filled triangle silhouette. At 13-17px a filled wedge
 *   plus up to ±28° of rotation is genuinely ambiguous about which end is the
 *   tip; an open "V" is not. A `clip-path` silhouette also silently discards
 *   any `filter: drop-shadow()` on the same element (clipping is applied
 *   AFTER filtering), which is why the old arrowheads had no contrast edge at
 *   all once they left the red button.
 */
export function downDropPieces(): DownDropPiece[] {
  const count = 4
  return Array.from({ length: count }, (_, i) => {
    const spread = count > 1 ? (i / (count - 1)) * 2 - 1 : 0
    const wobble = noise(i + 17)
    // A NARROW lane, not a fan: the chevrons rain down a near-vertical
    // column at staggered depths and start times. A wide spray of the same
    // pieces reads as a tangle of overlapping marks pointing everywhere,
    // which is the opposite of the one thing this effect has to say.
    const dx = Math.round(spread * 16)
    const dy = Math.round(38 + wobble * 24)
    // The chevron's rest pose (0deg) already points straight down (+y), so it
    // needs the angle of (dx, dy) offset by -90deg (atan2's own straight-down
    // angle) to end up aligned with its travel direction.
    const rot = Math.round((Math.atan2(dy, dx) * 180) / Math.PI - 90)
    return {
      dx,
      dy,
      rot,
      size: Math.round(15 + noise(i + 63) * 5),
      // A wide stagger relative to the 520ms fall, on purpose: at any instant
      // you should be able to count the chevrons. Bunch them closer and they
      // travel as one overlapping clump, which is what stopped reading as
      // arrows at all.
      delay: Math.round(i * 85 + wobble * 15),
    }
  })
}

/** Whether an upvote should trigger the bigger confetti burst rather than the routine one. */
export function isBigVoteMoment(prevScore: number, score: number): boolean {
  const firstVoteEver = prevScore <= 0 && score > 0
  const crossedMilestone = VOTE_MILESTONES.some((m) => prevScore < m && score >= m)
  return firstVoteEver || crossedMilestone
}
