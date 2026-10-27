import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface ErrorStateProps {
  title: string
  description?: ReactNode
  /** Primary recovery action (retry button, back link). */
  action?: ReactNode
  /** "alert" interrupts AT for failures and denials; "status" for calmer outcomes. */
  role?: 'alert' | 'status'
  /** "denied" shows a lock; "missing" a dashed sheet; "failure" a cross. */
  kind?: 'failure' | 'denied' | 'missing'
  className?: string
  testId?: string
}

/** Full-width failure state (load error, access denied, invalid link): centred, with a way forward. */
export function ErrorState({
  title,
  description,
  action,
  role = 'alert',
  kind = 'failure',
  className,
  testId,
}: ErrorStateProps) {
  return (
    <div
      role={role}
      data-testid={testId}
      className={cx('ds-error-state', kind !== 'failure' && 'ds-error-state--calm', className)}
    >
      <span aria-hidden="true" className="ds-error-state__glyph">
        {kind === 'denied' ? (
          <svg
            viewBox="0 0 24 24"
            width="22"
            height="22"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <rect x="5" y="11" width="14" height="10" />
            <path d="M8 11V8a4 4 0 0 1 8 0v3" />
          </svg>
        ) : kind === 'missing' ? (
          <svg
            viewBox="0 0 24 24"
            width="22"
            height="22"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <rect x="5" y="3" width="14" height="18" strokeDasharray="3 2.5" />
            <path d="M9 12h6" />
          </svg>
        ) : (
          <svg
            viewBox="0 0 16 16"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.75"
            strokeLinecap="round"
          >
            <path d="M4.5 4.5l7 7M11.5 4.5l-7 7" />
          </svg>
        )}
      </span>
      <h2 className="ds-error-state__title">{title}</h2>
      {description && <div className="ds-error-state__description">{description}</div>}
      {action && <div className="ds-error-state__action">{action}</div>}
    </div>
  )
}
