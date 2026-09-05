import { cx } from '../lib/cx'

interface ConsensusBarProps {
  /** Share of FOR votes in percent, or `null` when no vote has been cast yet. */
  percent: number | null
  /** Label shown at >=50% consensus. */
  label?: string
  /** Label shown below 50% consensus (contested). */
  labelLow?: string
  /** Label shown when there are no votes yet (`percent === null`). */
  labelEmpty?: string
  /** "compact" hides the label row — for dense list rows. */
  size?: 'default' | 'compact'
  className?: string
}

/**
 * The division bar: share of FOR votes in green from the left, AGAINST in
 * coral from the right — both sides are always drawn, because a 100% bar with
 * nothing against it is a different reading than a contested one. With no
 * votes at all the track stays a neutral hairline and reads "—", so a fresh
 * idea never looks unanimously rejected. The green fill grows in on mount.
 */
export function ConsensusBar({
  percent,
  label: labelHigh = 'Consensus',
  labelLow = 'Controversial',
  labelEmpty = 'No votes yet',
  size = 'default',
  className,
}: ConsensusBarProps) {
  const isEmpty = percent === null
  const clamped = isEmpty ? 0 : Math.max(0, Math.min(100, Math.round(percent)))
  const isStrong = clamped >= 50
  const label = isEmpty ? labelEmpty : isStrong ? labelHigh : labelLow
  const value = isEmpty ? '—' : `${clamped}%`

  return (
    <div className={cx('flex flex-col gap-1.5 w-full', className)}>
      {size === 'default' && (
        // The landing's reading: figure and word sit on one baseline, right
        // next to each other, not spread across the column. The figure is ink
        // (the bar already carries the green) and only turns coral when the
        // idea is contested, so colour marks the exception, not the rule.
        <div className="flex items-baseline gap-1.5 leading-none">
          <span
            className={cx(
              'text-vp-sm font-mono-num font-bold',
              isEmpty ? 'text-vp-text-muted' : isStrong ? 'text-vp-ink' : 'text-vp-vote-down-strong',
            )}
          >
            {value}
          </span>
          <span className="text-vp-xs text-vp-text-secondary truncate">{label}</span>
        </div>
      )}
      {/* Same geometry as the landing's ExampleBoard: a 6px bar with 2px
          corners, coral track, green fill scaled in from the left. */}
      <div
        className={cx(
          'flex w-full h-1.5 rounded-[2px] overflow-hidden',
          isEmpty ? 'bg-vp-surface-sunken' : 'bg-vp-vote-down',
        )}
        aria-hidden="true"
      >
        {!isEmpty && (
          <span
            className="block h-full w-full origin-left bg-vp-consensus-strong transition-transform duration-500 ease-vp-out"
            style={{ transform: `scaleX(${clamped / 100})` }}
          />
        )}
      </div>
      {size === 'compact' && <span className="sr-only">{isEmpty ? label : `${value} ${label}`}</span>}
    </div>
  )
}
