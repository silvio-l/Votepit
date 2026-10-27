import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export type BadgeTone = 'neutral' | 'info' | 'success' | 'warning' | 'danger' | 'ink' | 'accent'

export interface BadgeProps {
  tone?: BadgeTone
  size?: 'sm' | 'md'
  /** Leading colour dot, for a state indicator next to the label. */
  dot?: boolean
  className?: string
  /** Optional stable data-testid (kebab-case, `<screen>-<element>`). */
  testId?: string
  children: ReactNode
}

/** Small label for a state word (role, plan, scope). Text carries the meaning; colour is a hint. */
export function Badge({
  tone = 'neutral',
  size = 'sm',
  dot = false,
  className,
  testId,
  children,
}: BadgeProps) {
  return (
    <span
      className={cx('ds-badge', `ds-badge--${tone}`, size === 'md' && 'ds-badge--md', className)}
      data-tone={tone}
      data-testid={testId}
    >
      {dot && <span aria-hidden="true" className="ds-badge__dot" />}
      {children}
    </span>
  )
}
