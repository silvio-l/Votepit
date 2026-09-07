import type { CSSProperties, ReactNode } from 'react'
import { useEffect, useState } from 'react'
import type { ConfettiTone } from '../lib/confettiFx'
import {
  CONFETTI_FX_LIFETIME_MS,
  SPARKLE_FX_LIFETIME_MS,
  celebrationConfettiPieces,
  milestoneSparklePieces,
} from '../lib/confettiFx'
import { cx } from '../lib/cx'
import type { AlertTone } from './Alert'
import { Alert } from './Alert'

/**
 * Which overlay a Celebration plays — kept to exactly two, one per moment
 * type in the app, and deliberately shaped/coloured differently from each
 * other (see confettiFx.ts): `confetti` for the referral-reward moment (a
 * concrete gift), `sparkle` for board-owner milestones (an ongoing habit).
 */
export type CelebrationEffect = 'confetti' | 'sparkle'

interface CelebrationProps {
  effect: CelebrationEffect
  tone?: AlertTone
  /** Leading emoji, prepended to the title — the "hype moment" the copy alone doesn't carry. */
  emoji?: string
  title?: string
  children?: ReactNode
  action?: ReactNode
  className?: string
}

type ConfettiStyle = CSSProperties & { '--vp-sway': string; '--vp-rot': string }
type SparkleStyle = CSSProperties & { '--vp-sx': string; '--vp-sy': string }

const CONFETTI_TONE_STYLE: Record<ConfettiTone, string> = {
  gold: 'var(--color-vp-warn-strong)',
  green: 'var(--color-vp-vote-up-strong)',
  ink: 'var(--color-vp-ink)',
  white: 'white',
}

function ConfettiOverlay() {
  const [pieces] = useState(celebrationConfettiPieces)
  return (
    <span aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
      {pieces.map((piece, i) => (
        <span
          key={i}
          className="animate-vp-confetti-fall absolute top-0 block"
          style={
            {
              left: `${piece.left}%`,
              width: `${piece.size}px`,
              height: `${Math.round(piece.size * 0.42)}px`,
              backgroundColor: CONFETTI_TONE_STYLE[piece.tone],
              animationDelay: `${piece.delay}ms`,
              '--vp-sway': `${piece.sway}px`,
              '--vp-rot': `${piece.rot}deg`,
            } as ConfettiStyle
          }
        />
      ))}
    </span>
  )
}

function SparkleOverlay() {
  const [pieces] = useState(milestoneSparklePieces)
  return (
    <span
      aria-hidden="true"
      className="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden"
    >
      {pieces.map((piece, i) => {
        const rad = (piece.angle * Math.PI) / 180
        const sx = Math.round(Math.cos(rad) * piece.dist)
        const sy = Math.round(Math.sin(rad) * piece.dist)
        return (
          <span
            key={i}
            className="animate-vp-sparkle-pop absolute block bg-vp-info-strong"
            style={
              {
                width: `${piece.size}px`,
                height: `${piece.size}px`,
                animationDelay: `${piece.delay}ms`,
                '--vp-sx': `${sx}px`,
                '--vp-sy': `${sy}px`,
              } as SparkleStyle
            }
          />
        )
      })}
    </span>
  )
}

/**
 * A one-shot celebratory banner: wraps Alert (the existing tone-banner
 * primitive) and layers a short, auto-tearing-down particle overlay on top —
 * confetti rain or a sparkle pop, per `effect` (see confettiFx.ts for why
 * those two stay visually distinct). The overlay removes itself from the DOM
 * once its animation is done; the banner text stays, same as any Alert.
 *
 * Callers control WHETHER a Celebration renders at all (e.g. via
 * celebrations.ts's one-shot/increase tracking) — this component only owns
 * the "how it plays" part.
 */
export function Celebration({
  effect,
  tone = 'success',
  emoji,
  title,
  children,
  action,
  className,
}: CelebrationProps) {
  const [showFx, setShowFx] = useState(true)

  useEffect(() => {
    const lifetime = effect === 'confetti' ? CONFETTI_FX_LIFETIME_MS : SPARKLE_FX_LIFETIME_MS
    const timer = setTimeout(() => setShowFx(false), lifetime)
    return () => clearTimeout(timer)
  }, [effect])

  const decoratedTitle = emoji && title ? `${emoji} ${title}` : (title ?? emoji)

  return (
    <div className={cx('relative overflow-hidden rounded-vp-md', className)}>
      <Alert tone={tone} title={decoratedTitle} action={action}>
        {children}
      </Alert>
      {showFx && (effect === 'confetti' ? <ConfettiOverlay /> : <SparkleOverlay />)}
    </div>
  )
}
