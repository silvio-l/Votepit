import { cx } from '../lib/cx'
import { Skeleton } from './Skeleton'

export interface LoadingStateProps {
  /** Announced to assistive technology; not shown. */
  label: string
  /** Number of placeholder lines (list) or rows (ballot). */
  rows?: number
  /** list: text lines; card: a media tile above the lines; ballot: idea rows with a vote column. */
  variant?: 'list' | 'card' | 'ballot'
  className?: string
  testId?: string
}

/** The one loading state for a page: skeleton lines, announced once, no spinner. */
export function LoadingState({
  label,
  rows = 4,
  variant = 'list',
  className,
  testId = 'loading-state',
}: LoadingStateProps) {
  if (variant === 'ballot') {
    return (
      <div
        className={cx('ds-skeleton', className)}
        role="status"
        aria-busy="true"
        data-testid={testId}
      >
        <span className="ds-visually-hidden">{label}</span>
        {Array.from({ length: rows }, (_, index) => (
          // Fixed placeholder rows never reorder, so the index is a stable key.
          // biome-ignore lint/suspicious/noArrayIndexKey: static placeholder rows
          <div key={index} className="ds-skeleton__ballot-row">
            <span className="ds-skeleton__bar ds-skeleton__ballot-vote" />
            <div className="ds-skeleton__ballot-body">
              <span className="ds-skeleton__bar ds-skeleton__bar--title" />
              <span className="ds-skeleton__bar ds-skeleton__bar--short" />
            </div>
          </div>
        ))}
      </div>
    )
  }
  return (
    <Skeleton
      variant={variant === 'card' ? 'card' : 'text'}
      lines={rows}
      label={label}
      className={className}
      testId={testId}
    />
  )
}
