import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface EmptyStateProps {
  title: string
  description?: ReactNode
  action?: ReactNode
  /** "compact" for empty table bodies and small sheets. */
  size?: 'default' | 'compact'
  /**
   * Heading level of the title. Defaults to 2 (the empty state stands for the
   * whole sheet); pass 3 when it sits inside a `Section` that already owns the
   * sheet's h2, so the document outline stays ordered.
   */
  headingLevel?: 2 | 3
  /** Replace the default ballot glyph with a custom 20px icon. */
  icon?: ReactNode
  className?: string
}

/**
 * The default empty-state art: a small stack of unmarked ballot lines — the
 * sheet is printed, nothing has been counted yet.
 */
function BallotStack({ compact }: { compact: boolean }) {
  const s = compact ? 36 : 56
  return (
    <svg
      viewBox="0 0 56 56"
      width={s}
      height={s}
      fill="none"
      aria-hidden="true"
      className="text-vp-text-muted"
    >
      <rect x="10" y="6" width="36" height="44" rx="4" fill="var(--color-vp-surface)" stroke="currentColor" strokeWidth="1.5" strokeDasharray="3 3" />
      <rect x="16" y="15" width="6" height="6" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M26 18h14" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
      <rect x="16" y="25" width="6" height="6" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M26 28h10" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
      <rect x="16" y="35" width="6" height="6" rx="1.5" stroke="currentColor" strokeWidth="1.5" />
      <path d="M26 38h12" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
    </svg>
  )
}

/**
 * The one empty state. An unmarked ballot sheet is the glyph — nothing has
 * been counted yet. role="status" so it is announced but never interrupts.
 */
export function EmptyState({
  title,
  description,
  action,
  size = 'default',
  headingLevel = 2,
  icon,
  className,
}: EmptyStateProps) {
  const compact = size === 'compact'
  const Heading = headingLevel === 3 ? 'h3' : 'h2'
  return (
    <div
      role="status"
      className={cx(
        'flex flex-col items-center text-center animate-vp-fade-in',
        compact ? 'gap-1.5 px-4 py-8' : 'gap-3 px-6 py-14',
        className,
      )}
    >
      <span
        aria-hidden="true"
        className={cx(
          'flex items-center justify-center rounded-vp-xl bg-vp-surface-frost ring-1 ring-vp-border-subtle',
          compact ? 'size-12' : 'size-20',
        )}
      >
        {icon ?? <BallotStack compact={compact} />}
      </span>
      <Heading
        className={cx(
          'font-semibold text-vp-ink tracking-[-0.01em]',
          compact ? 'text-vp-base' : 'text-vp-lg mt-1',
        )}
      >
        {title}
      </Heading>
      {description && (
        <div
          className={cx(
            'text-vp-text-secondary max-w-md text-pretty',
            compact ? 'text-vp-sm' : 'text-vp-base',
          )}
        >
          {description}
        </div>
      )}
      {action && <div className="mt-2 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  )
}
