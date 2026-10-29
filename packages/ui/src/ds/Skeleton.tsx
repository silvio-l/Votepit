import { cx } from '../lib/cx'

export interface SkeletonProps {
  /** text: title and lines; card: a media area above the title and lines. */
  variant?: 'text' | 'card'
  lines?: number
  /** Announced once for the whole block. */
  label: string
  className?: string
  testId?: string
}

/** Static loading placeholder. No shimmer, so it adds no motion. */
export function Skeleton({
  variant = 'text',
  lines = 3,
  label,
  className,
  testId = 'skeleton',
}: SkeletonProps) {
  return (
    <div
      className={cx('ds-skeleton', className)}
      role="status"
      aria-busy="true"
      data-testid={testId}
    >
      <span className="ds-visually-hidden">{label}</span>
      {variant === 'card' && <span className="ds-skeleton__bar ds-skeleton__bar--media" />}
      <span className="ds-skeleton__bar ds-skeleton__bar--title" />
      {Array.from({ length: lines }, (_, index) => (
        <span
          // Fixed placeholder rows never reorder, so the index is a stable key.
          // biome-ignore lint/suspicious/noArrayIndexKey: static placeholder rows
          key={index}
          className={cx('ds-skeleton__bar', index > 0 && 'ds-skeleton__bar--short')}
        />
      ))}
    </div>
  )
}
