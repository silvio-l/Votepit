import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface StatCardProps {
  label: string
  value: ReactNode
  /** Small line under the value (for example "all boards"). */
  caption?: ReactNode
  icon?: ReactNode
  tone?: 'default' | 'accent' | 'warning' | 'danger'
  className?: string
  testId?: string
}

/** Figure with a label: one number per card, tabular numerals. Not interactive. */
export function StatCard({
  label,
  value,
  caption,
  icon,
  tone = 'default',
  className,
  testId,
}: StatCardProps) {
  return (
    <div className={cx('ds-stat', `ds-stat--${tone}`, className)} data-testid={testId}>
      <div className="ds-stat__label">
        {icon && (
          <span className="ds-stat__icon" aria-hidden="true">
            {icon}
          </span>
        )}
        <span>{label}</span>
      </div>
      <div className="ds-stat__value">{value}</div>
      {caption && <div className="ds-stat__caption">{caption}</div>}
    </div>
  )
}
