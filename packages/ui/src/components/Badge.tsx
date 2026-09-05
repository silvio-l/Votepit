import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'ink' | 'accent'

interface BadgeProps {
  tone?: BadgeTone
  children: ReactNode
  /** Leading colour dot (state indicator). */
  dot?: boolean
  size?: 'sm' | 'md'
  className?: string
}

/* The landing's `.vp-tag`: a solid rectangular fill, no outline. Soft tints
   carry their text-safe "-strong" colour; the ink tone is the tag itself. */
const toneClasses: Record<BadgeTone, string> = {
  neutral: 'bg-vp-ink-soft text-vp-text-secondary',
  info: 'bg-vp-info-soft text-vp-info-strong',
  success: 'bg-vp-vote-up-soft text-vp-vote-up-strong',
  warning: 'bg-vp-warn-soft text-vp-warn-strong',
  danger: 'bg-vp-vote-down-soft text-vp-vote-down-strong',
  ink: 'bg-vp-ink text-vp-on-ink',
  accent: 'bg-vp-accent-soft text-vp-accent-strong',
}

const dotClasses: Record<BadgeTone, string> = {
  neutral: 'bg-vp-status-open',
  info: 'bg-vp-info',
  success: 'bg-vp-vote-up',
  warning: 'bg-vp-warn',
  danger: 'bg-vp-vote-down',
  ink: 'bg-vp-on-ink',
  accent: 'bg-vp-accent',
}

/** Small label for a state word (frozen, active, revoked, role…). */
export function Badge({ tone = 'neutral', children, dot = false, size = 'sm', className }: BadgeProps) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1.5 font-semibold leading-none whitespace-nowrap rounded-vp-sm',
        size === 'sm' ? 'h-5 px-1.5 text-vp-2xs' : 'h-6 px-2 text-vp-xs',
        toneClasses[tone],
        className,
      )}
    >
      {dot && (
        <span aria-hidden="true" className={cx('size-1.5 rounded-vp-full', dotClasses[tone])} />
      )}
      {children}
    </span>
  )
}
