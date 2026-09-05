import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface ErrorStateProps {
  title: string
  description?: ReactNode
  /** Primary recovery action (retry button, back link…). */
  action?: ReactNode
  /**
   * "alert" (default) interrupts AT for failures and access denials; use
   * "status" for calmer outcomes such as not-found.
   */
  role?: 'alert' | 'status'
  /** "denied" swaps the cross for a lock; "missing" for a dashed sheet. */
  kind?: 'failure' | 'denied' | 'missing'
  className?: string
}

function Glyph({ kind }: { kind: NonNullable<ErrorStateProps['kind']> }) {
  if (kind === 'denied') {
    return (
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <rect x="5" y="11" width="14" height="10" />
        <path d="M8 11V8a4 4 0 0 1 8 0v3" />
      </svg>
    )
  }
  if (kind === 'missing') {
    return (
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <rect x="5" y="3" width="14" height="18" strokeDasharray="3 2.5" />
        <path d="M9 12h6" />
      </svg>
    )
  }
  return (
    <svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" aria-hidden="true">
      <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />
    </svg>
  )
}

/**
 * The one full-width failure state (load error, access denied, invalid link).
 * Centered, plain-spoken, always with a way forward.
 */
export function ErrorState({
  title,
  description,
  action,
  role = 'alert',
  kind = 'failure',
  className,
}: ErrorStateProps) {
  const calm = kind !== 'failure'
  return (
    <div
      role={role}
      className={cx(
        'vp-sheet flex flex-col items-center text-center gap-3 px-6 py-14 animate-vp-fade-in',
        className,
      )}
    >
      <span
        aria-hidden="true"
        className={cx(
          'flex items-center justify-center size-14 rounded-vp-xl',
          calm ? 'bg-vp-bg text-vp-text-muted' : 'bg-vp-vote-down-soft text-vp-vote-down-strong',
        )}
      >
        <Glyph kind={kind} />
      </span>
      <h2 className="font-archivo font-bold text-vp-xl text-vp-ink tracking-[-0.02em] mt-1">
        {title}
      </h2>
      {description && (
        <div className="text-vp-base text-vp-text-secondary max-w-md text-pretty">{description}</div>
      )}
      {action && <div className="mt-2 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  )
}
