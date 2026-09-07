/**
 * Celebration visual-effect math — framework-agnostic (no React, no DOM),
 * mirroring voteFx.ts's split between geometry and rendering. Deliberately a
 * SEPARATE piece vocabulary from voteFx: a celebration must never read as
 * "a bigger vote burst" — it has its own shapes and its own motion.
 *
 * Two distinct effects live here, used for two distinct moments that must
 * stay visually distinguishable from each other too (product requirement):
 * - `celebrationConfettiPieces` — rectangular pieces raining straight down
 *   with a rotating tumble, for the referral-reward moment (a concrete gift
 *   was just unlocked).
 * - `milestoneSparklePieces` — small diamonds popping outward from the
 *   centre and twinkling back down, for board-owner milestones (an ongoing
 *   habit is being reinforced, not a one-off reward).
 */

/** Deterministic 0..1 noise from an integer seed — same rationale as voteFx's own copy: no Math.random, so re-renders don't reshuffle a burst already mid-animation. */
function noise(seed: number): number {
  const x = Math.sin(seed * 127.1 + 311.7) * 43758.5453
  return x - Math.floor(x)
}

/** How long a spawned confetti-fall wrapper stays in the DOM before teardown, ms — past the 1000ms fall plus the longest 400ms start delay. */
export const CONFETTI_FX_LIFETIME_MS = 1500

/** How long a spawned sparkle-pop wrapper stays in the DOM before teardown, ms — past the 700ms pop plus the longest 220ms start delay. */
export const SPARKLE_FX_LIFETIME_MS = 1000

export type ConfettiTone = 'gold' | 'green' | 'ink' | 'white'

const CONFETTI_TONES: readonly ConfettiTone[] = ['gold', 'green', 'ink', 'white']

export interface ConfettiPiece {
  /** Horizontal start position, % of the container width. */
  left: number
  /** Fixed end rotation, deg — the piece tumbles from 0 to this over the fall. */
  rot: number
  /** Horizontal drift during the fall, px. */
  sway: number
  /** Rendered edge length, px. */
  size: number
  /** Start offset, ms. */
  delay: number
  tone: ConfettiTone
}

/**
 * A rain of small rectangles falling straight down a fixed-height banner,
 * each tumbling at its own rate and drifting a little sideways — a
 * "confetti fell past" moment rather than a burst thrown from one point
 * (that shape is voteFx's, reserved for the vote button).
 */
export function celebrationConfettiPieces(): ConfettiPiece[] {
  const count = 26
  return Array.from({ length: count }, (_, i) => {
    const a = noise(i + 1)
    const b = noise(i + 53)
    const c = noise(i + 97)
    const d = noise(i + 149)
    return {
      left: Math.round(((i + 0.5) / count) * 100 + (a - 0.5) * 8),
      rot: Math.round(b * 520),
      sway: Math.round((c - 0.5) * 44),
      size: Math.round(5 + d * 5),
      delay: Math.round(a * 400),
      tone: CONFETTI_TONES[i % CONFETTI_TONES.length]!,
    }
  })
}

export interface SparklePiece {
  /** Travel direction, deg (0 = right, clockwise). */
  angle: number
  /** Travel distance to the piece's resting point, px. */
  dist: number
  /** Rendered edge length, px. */
  size: number
  /** Start offset, ms. */
  delay: number
}

/**
 * A ring of small diamonds popping outward from the centre and settling —
 * a quick twinkle rather than a fall, so a milestone never reads as a
 * smaller/cheaper version of the reward confetti rain.
 */
export function milestoneSparklePieces(): SparklePiece[] {
  const count = 14
  return Array.from({ length: count }, (_, i) => {
    const jitter = noise(i + 3)
    const spread = noise(i + 61)
    const t = (i + 0.5) / count
    const angle = t * 360 + (jitter - 0.5) * 20
    return {
      angle: Math.round(angle),
      dist: Math.round(30 + spread * 26),
      size: Math.round(4 + noise(i + 113) * 4),
      delay: Math.round(jitter * 220),
    }
  })
}
