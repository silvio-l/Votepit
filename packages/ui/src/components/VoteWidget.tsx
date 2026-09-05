import type { CSSProperties } from 'react'
import { useEffect, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import type { BurstTone } from '../lib/voteFx'
import {
  VOTE_FX_LIFETIME_MS,
  downDropPieces,
  isBigVoteMoment,
  upvoteBurstPieces,
} from '../lib/voteFx'

type ParticleStyle = CSSProperties & {
  '--vp-dx': string
  '--vp-dy': string
  '--vp-fall'?: string
  '--vp-rot'?: string
}

/**
 * Fill for one upvote piece. Three flat tones, no outline and no shadow: the
 * burst starts on a solid green button and ends far out on a near-white
 * sheet, and the lightest tone has to read on both, so it is a mint mixed
 * from the vote colour itself rather than plain white (invisible on the
 * sheet for most of a piece's flight) or a white dot wearing a shadow ring
 * (reads as a hollow bubble, and the design language has no glows in it).
 */
const BURST_TONE_STYLE: Record<BurstTone, string> = {
  strong: 'var(--color-vp-vote-up-strong)',
  base: 'var(--color-vp-vote-up)',
  light: 'color-mix(in srgb, var(--color-vp-vote-up) 42%, white)',
}

/**
 * The upvote burst: flat round pieces thrown UPWARD out of the box, each
 * arcing over its own apex and falling back a little as it fades — no blur,
 * no glow halo, just moving dots. The upward throw is deliberately the
 * mirror of the downvote's fall, so the two directions read as opposites at
 * a glance.
 *
 * `big` (first vote ever / a 10-50-100 net-vote milestone) reaches further
 * and carries more pieces than a routine upvote. Sizes, distances, fan
 * angles and start delays all vary per piece; that variance is the whole
 * point (see voteFx), so no `size-*` utility here — the diameter is per
 * piece. The negative margins pull each piece back by half its own size so
 * it is genuinely centred on the box instead of hanging off its centre
 * point. Geometry comes from the shared voteFx module, so the landing
 * page's ExampleBoard.astro renders the exact same recipe.
 */
function VoteBurst({ seedKey, big = false }: { seedKey: number; big?: boolean }) {
  if (seedKey === 0) return null
  const pieces = upvoteBurstPieces(big)
  return (
    <span key={seedKey} aria-hidden="true" className="vp-vote-fx pointer-events-none absolute inset-0">
      {pieces.map((piece, i) => (
        <span
          key={i}
          className={cx(
            'absolute left-1/2 top-1/2 block rounded-full',
            big ? 'animate-vp-confetti-piece' : 'animate-vp-particle-burst',
          )}
          style={
            {
              backgroundColor: BURST_TONE_STYLE[piece.tone],
              width: `${piece.size}px`,
              height: `${piece.size}px`,
              marginLeft: `${-piece.size / 2}px`,
              marginTop: `${-piece.size / 2}px`,
              animationDelay: `${piece.delay}ms`,
              '--vp-dx': `${piece.dx}px`,
              '--vp-dy': `${piece.dy}px`,
              '--vp-fall': `${piece.fall}px`,
            } as ParticleStyle
          }
        />
      ))}
    </span>
  )
}

/** A flat, non-punitive ink-stamp ring that settles and fades on a downvote. */
function VoteInkSettle({ seedKey }: { seedKey: number }) {
  if (seedKey === 0) return null
  return (
    <span
      key={seedKey}
      aria-hidden="true"
      className="vp-vote-fx pointer-events-none absolute inset-0 flex items-center justify-center"
    >
      <span
        className="animate-vp-ink-settle block size-full rounded-vp-sm"
        style={{ border: '2px solid var(--color-vp-vote-down)' }}
      />
    </span>
  )
}

/**
 * The downvote drop: a short cascade of downward chevrons that sink and
 * fade — pairs with the ink-stamp ring so a downvote reads as more than
 * "the opposite of the upvote burst". Each chevron's rotation comes from
 * its own (dx, dy) travel direction and is held constant for the whole
 * animation (see vp-down-drop's keyframes — rotation isn't animated), so it
 * always visibly points the way it's moving.
 *
 * Drawn as an OPEN, stroked "V", not a filled triangle: at this size a
 * filled wedge plus rotation is genuinely ambiguous about which end is the
 * tip, and a `clip-path` silhouette additionally throws away any
 * drop-shadow on the same element (clipping happens after filtering), which
 * left the old arrowheads with no contrast edge at all. Two stacked strokes
 * give it an outline that survives both grounds it crosses: the wide dark
 * red one carries the chevron over the light page it falls onto, the narrow
 * white core carries it over the solid red box it starts on. Still no
 * glow/blur.
 */
function VoteDownDrop({ seedKey }: { seedKey: number }) {
  if (seedKey === 0) return null
  const pieces = downDropPieces()
  return (
    <span key={seedKey} aria-hidden="true" className="vp-vote-fx pointer-events-none absolute inset-0">
      {pieces.map((piece, i) => (
        <svg
          key={i}
          viewBox="0 0 16 16"
          width={piece.size}
          height={piece.size}
          fill="none"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          className="absolute left-1/2 top-1/2 block animate-vp-down-drop"
          style={
            {
              marginLeft: `${-piece.size / 2}px`,
              marginTop: `${-piece.size / 2}px`,
              animationDelay: `${piece.delay}ms`,
              '--vp-dx': `${piece.dx}px`,
              '--vp-dy': `${piece.dy}px`,
              '--vp-rot': `${piece.rot}deg`,
            } as ParticleStyle
          }
        >
          <path d="M3.6 5.6L8 10.9l4.4-5.3" stroke="var(--color-vp-vote-down-strong)" strokeWidth="4" />
          <path d="M3.6 5.6L8 10.9l4.4-5.3" stroke="var(--color-vp-on-ink)" strokeWidth="1.7" />
        </svg>
      ))}
    </span>
  )
}

export type VoteTone = 'leading' | 'neutral'
export type UserVote = 'up' | 'down' | null

// ── Ballot marks ─────────────────────────────────────────────────────────────
function CheckMark({ size }: { size: number }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M3 8.5l3.2 3.2L13 5" />
    </svg>
  )
}

function CrossMark({ size }: { size: number }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="2.25"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <path d="M4 4l8 8M12 4l-8 8" />
    </svg>
  )
}

// ── VoteWidget ───────────────────────────────────────────────────────────────
interface VoteWidgetProps {
  tone?: VoteTone
  score: number
  userVote?: UserVote
  onVoteUp?: () => void
  onVoteDown?: () => void
  disabled?: boolean
  upAriaLabel?: string
  downAriaLabel?: string
  /**
   * Visible short labels under the two marks ("For" / "Against"). The
   * accessible names above must contain these words (WCAG 2.5.3).
   */
  upLabel?: string
  downLabel?: string
}

/**
 * The ballot: a FOR box above the tally, an AGAINST box below it — the same
 * square boxes the landing's ExampleBoard draws (hairline at rest, vote
 * colour on hover, solid vote fill when marked, like the hero's controls).
 * Marking a box stamps it with its mark (check / cross) so a real down-vote
 * never reads as "un-like". Buttons carry aria-pressed; the tally is tabular
 * mono and pops once when it changes. An upvote flings a sizeable burst of
 * flat particles outward (an even bigger one on the idea's first vote or a
 * 10/50/100 net-vote milestone) and a downvote pairs a flat ink-stamp ring
 * with a second burst of particles sinking downward — motion only, never a
 * glow or a blur halo.
 */
export function VoteWidget({
  tone = 'neutral',
  score,
  userVote,
  onVoteUp,
  onVoteDown,
  disabled = false,
  upAriaLabel = 'Upvote',
  downAriaLabel = 'Downvote',
  upLabel,
  downLabel,
}: VoteWidgetProps) {
  const [pop, setPop] = useState(0)
  const [burstKey, setBurstKey] = useState(0)
  const [confettiKey, setConfettiKey] = useState(0)
  const [inkKey, setInkKey] = useState(0)
  const prevScoreRef = useRef(score)
  const prevUserVoteRef = useRef(userVote)
  const upBtnRef = useRef<HTMLButtonElement>(null)
  // Effects are torn down again once they have played out, not left mounted
  // at opacity 0: the `.vp-vote-fx` marker they carry is what lifts the whole
  // vote column over the neighbouring rows (tokens.css `.vp-vote-stack`), and
  // a column that never drops back to z-10 would just hand the tie to the
  // next row that votes.
  const upFxTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const downFxTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  useEffect(
    () => () => {
      if (upFxTimer.current) clearTimeout(upFxTimer.current)
      if (downFxTimer.current) clearTimeout(downFxTimer.current)
    },
    [],
  )

  // Replay the tally stamp / vote feedback only on actual changes (not on
  // mount) — both refs are read before either is updated, so a click that
  // changes score and userVote in the same render sees consistent "before"
  // values regardless of prop-update order.
  useEffect(() => {
    const prevScore = prevScoreRef.current
    const prevVote = prevUserVoteRef.current
    const votedUpNow = userVote === 'up' && prevVote !== 'up'
    const votedDownNow = userVote === 'down' && prevVote !== 'down'

    if (prevScore !== score) {
      setPop((k) => k + 1)
    }
    if (votedUpNow) {
      if (isBigVoteMoment(prevScore, score)) {
        setBurstKey(0)
        setConfettiKey((k) => k + 1)
      } else {
        setConfettiKey(0)
        setBurstKey((k) => k + 1)
      }
      if (upFxTimer.current) clearTimeout(upFxTimer.current)
      upFxTimer.current = setTimeout(() => {
        setBurstKey(0)
        setConfettiKey(0)
      }, VOTE_FX_LIFETIME_MS)
      // Bounce the box itself, not just the particles — a class toggle
      // (rather than React state) so a rapid re-vote can restart the
      // animation via a forced reflow instead of queuing a no-op class
      // change React would otherwise skip.
      const el = upBtnRef.current
      if (el) {
        el.classList.remove('animate-vp-vote-pop')
        void el.offsetWidth
        el.classList.add('animate-vp-vote-pop')
      }
    }
    if (votedDownNow) {
      setInkKey((k) => k + 1)
      if (downFxTimer.current) clearTimeout(downFxTimer.current)
      downFxTimer.current = setTimeout(() => setInkKey(0), VOTE_FX_LIFETIME_MS)
    }

    prevScoreRef.current = score
    prevUserVoteRef.current = userVote
  }, [score, userVote])

  const leading = tone === 'leading'
  const boxSize = leading ? 'size-11' : 'size-9'
  const markSize = leading ? 18 : 15

  const boxBase = cx(
    'relative flex items-center justify-center rounded-vp-sm border-[1.5px] cursor-pointer vp-press',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    boxSize,
  )
  // Marked boxes use literal white on the fixed vote colours: the fill is a
  // semantic (non-brandable) colour, so the brandable --vp-on-ink hook must
  // not be able to break its contrast.
  const upClass =
    userVote === 'up'
      ? 'bg-vp-vote-up border-vp-vote-up text-white'
      : leading
        ? 'bg-vp-surface border-vp-vote-up text-vp-vote-up-strong hover:bg-vp-vote-up-soft'
        : 'bg-vp-surface border-vp-rule text-vp-text-secondary hover:border-vp-vote-up hover:text-vp-vote-up-strong hover:bg-vp-vote-up-soft'
  const downClass =
    userVote === 'down'
      ? 'bg-vp-vote-down border-vp-vote-down text-white'
      : leading
        ? 'bg-vp-surface border-vp-vote-down text-vp-vote-down-strong hover:bg-vp-vote-down-soft'
        : 'bg-vp-surface border-vp-rule text-vp-text-secondary hover:border-vp-vote-down hover:text-vp-vote-down-strong hover:bg-vp-vote-down-soft'

  return (
    <div className={cx('relative flex flex-col items-center gap-1', leading ? 'w-14' : 'w-10')}>
      <button
        ref={upBtnRef}
        type="button"
        aria-label={upAriaLabel}
        aria-pressed={userVote === 'up'}
        onClick={disabled ? undefined : onVoteUp}
        disabled={disabled}
        className={cx(boxBase, upClass)}
      >
        <span key={userVote === 'up' ? 'on' : 'off'} className={cx('inline-flex', userVote === 'up' && 'animate-vp-stamp')}>
          <CheckMark size={markSize} />
        </span>
        <VoteBurst seedKey={burstKey} />
        <VoteBurst seedKey={confettiKey} big />
      </button>
      {upLabel && <span className="text-vp-2xs text-vp-text-secondary leading-none">{upLabel}</span>}

      {/* Tally */}
      <span
        key={pop}
        className={cx(
          'font-mono-num font-bold leading-none tabular-nums py-1 tracking-tight',
          leading ? 'text-vp-xl' : 'text-vp-md',
          userVote === 'up'
            ? 'text-vp-vote-up-strong'
            : userVote === 'down'
              ? 'text-vp-vote-down-strong'
              : 'text-vp-ink',
          pop > 0 && 'animate-vp-stamp',
        )}
      >
        {score}
      </span>

      <button
        type="button"
        aria-label={downAriaLabel}
        aria-pressed={userVote === 'down'}
        onClick={disabled ? undefined : onVoteDown}
        disabled={disabled}
        className={cx(boxBase, downClass)}
      >
        <span
          key={userVote === 'down' ? 'on' : 'off'}
          className={cx('inline-flex', userVote === 'down' && 'animate-vp-stamp')}
        >
          <CrossMark size={markSize} />
        </span>
        <VoteInkSettle seedKey={inkKey} />
        <VoteDownDrop seedKey={inkKey} />
      </button>
      {downLabel && (
        <span className="text-vp-2xs text-vp-text-secondary leading-none">{downLabel}</span>
      )}
    </div>
  )
}
