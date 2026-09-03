import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'ink' | 'accent'

interface BadgeProps {
  tone?: BadgeTone
  children: ReactNode
  /** Leading colour dot (state indicator). */
  dot?: boolean
  /** "pill" for rounded-full counters and status words. */
  shape?: 'square' | 'pill'
  size?: 'sm' | 'md'
  className?: string
}

const toneClasses: Record<BadgeTone, string> = {
  neutral: 'bg-vp-surface-frost text-vp-text-secondary border-vp-border-subtle',
  info: 'bg-vp-info-soft text-vp-info-strong border-vp-info/25',
  success: 'bg-vp-vote-up-soft text-vp-vote-up-strong border-vp-vote-up/25',
  warning: 'bg-vp-warn-soft text-vp-warn-strong border-vp-warn/25',
  danger: 'bg-vp-vote-down-soft text-vp-vote-down-strong border-vp-vote-down/25',
  ink: 'bg-vp-ink text-vp-on-ink border-vp-ink',
  accent: 'bg-vp-accent-soft text-vp-accent-strong border-vp-accent/30',
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
export function Badge({
  tone = 'neutral',
  children,
  dot = false,
  shape = 'square',
  size = 'sm',
  className,
}: BadgeProps) {
  return (
    <span
      className={cx(
        'inline-flex items-center gap-1.5 border font-medium leading-none whitespace-nowrap',
        size === 'sm' ? 'h-5 px-1.5 text-vp-2xs' : 'h-6 px-2 text-vp-xs',
        shape === 'pill' ? 'rounded-vp-full' : 'rounded-vp-sm',
        toneClasses[tone],
        className,
      )}
    >
      {dot && <span aria-hidden="true" className={cx('size-1.5 rounded-full', dotClasses[tone])} />}
      {children}
    </span>
  )
}
