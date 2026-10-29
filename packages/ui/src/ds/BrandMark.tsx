import { cx } from '../lib/cx'

// Same geometry as the legacy VotepitLogo (components/Header.tsx). The legacy
// component depends on Tailwind utility classes, so the design layer keeps its
// own copy of the path data; keep both in sync when the logo changes.
const TOP =
  'M 165.0 0.0 L 165.0 -44.0 Q 165.0 -72.0 141.6 -87.3 L 23.4 -164.7 Q 0.0 -180.0 -23.4 -164.7 L -141.6 -87.3 Q -165.0 -72.0 -165.0 -44.0 L -165.0 0.0 Z'
const BOT =
  'M -165.0 0.0 L -165.0 44.0 Q -165.0 72.0 -141.6 87.3 L -23.4 164.7 Q 0.0 180.0 23.4 164.7 L 141.6 87.3 Q 165.0 72.0 165.0 44.0 L 165.0 0.0 Z'
const MID =
  'M -15.9 -112.0 Q 0.0 -122.4 15.9 -112.0 L 96.3 -59.4 Q 112.2 -49.0 112.2 -29.9 L 112.2 29.9 Q 112.2 49.0 96.3 59.4 L 15.9 112.0 Q 0.0 122.4 -15.9 112.0 L -96.3 59.4 Q -112.2 49.0 -112.2 29.9 L -112.2 -29.9 Q -112.2 -49.0 -96.3 -59.4 Z'
const DARK =
  'M -11.7 -82.3 Q 0.0 -90.0 11.7 -82.3 L 70.8 -43.7 Q 82.5 -36.0 82.5 -22.0 L 82.5 22.0 Q 82.5 36.0 70.8 43.7 L 11.7 82.3 Q 0.0 90.0 -11.7 82.3 L -70.8 43.7 Q -82.5 36.0 -82.5 22.0 L -82.5 -22.0 Q -82.5 -36.0 -70.8 -43.7 Z'

export interface BrandMarkProps {
  /** Renders the wordmark for a dark (inverse) ground. */
  onInk?: boolean
  /** Appends "│ CLOUD" or "│ COMMUNITY", as the legacy header does. */
  edition?: 'cloud' | 'community'
  className?: string
}

/** Geometric mark plus two-tone "Votepit" wordmark. Decorative as a whole: the surrounding link names it. */
export function BrandMark({ onInk = false, edition, className }: BrandMarkProps) {
  return (
    <span className={cx('ds-brand', onInk && 'ds-brand--on-ink', className)}>
      <svg viewBox="-185 -205 370 410" width="22" height="24" aria-hidden="true" focusable="false">
        <path d={TOP} style={{ fill: 'var(--vpds-brand-logo-up)' }} />
        <path d={BOT} style={{ fill: 'var(--vpds-brand-logo-down)' }} />
        <path d={MID} style={{ fill: 'var(--vpds-brand-logo-mid)' }} />
        <path d={DARK} style={{ fill: 'var(--vpds-brand-logo-dark)' }} />
      </svg>
      <span className="ds-brand__wordmark">
        <span className="ds-brand__vote">Vote</span>
        <span className="ds-brand__pit">pit</span>
      </span>
      {edition && (
        <>
          <span className="ds-brand__divider" aria-hidden="true">
            │
          </span>
          <span className="ds-brand__edition">{edition === 'cloud' ? 'CLOUD' : 'COMMUNITY'}</span>
        </>
      )}
    </span>
  )
}
