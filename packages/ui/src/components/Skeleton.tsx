import { cx } from '../lib/cx'

interface SkeletonProps {
  className?: string
}

/** One shimmering placeholder block. Size it with width/height classes. */
export function Skeleton({ className }: SkeletonProps) {
  return <span aria-hidden="true" className={cx('block vp-skeleton', className)} />
}

/** "list" = generic table/list line; "ballot" = the height of an idea row with its vote widget; "card" = a grid of stat tiles. */
export type SkeletonRowVariant = 'list' | 'ballot' | 'card' | 'form'

interface SkeletonRowsProps {
  rows?: number
  variant?: SkeletonRowVariant
  className?: string
}

/** A stack of list-row placeholders — the shape of the sheet that is loading. */
export function SkeletonRows({ rows = 4, variant = 'list', className }: SkeletonRowsProps) {
  if (variant === 'card') {
    return (
      <div aria-hidden="true" className={cx('grid gap-4 sm:grid-cols-2 lg:grid-cols-3', className)}>
        {Array.from({ length: rows }, (_, i) => (
          <div key={i} className="vp-card p-4 flex flex-col gap-3">
            <Skeleton className="h-3 w-1/3" />
            <Skeleton className="h-7 w-1/2" />
            <Skeleton className="h-3 w-2/3" />
          </div>
        ))}
      </div>
    )
  }
  if (variant === 'form') {
    return (
      <div aria-hidden="true" className={cx('flex flex-col gap-5', className)}>
        {Array.from({ length: rows }, (_, i) => (
          <div key={i} className="flex flex-col gap-2">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-10 w-full rounded-vp-md" />
          </div>
        ))}
      </div>
    )
  }
  const ballot = variant === 'ballot'
  return (
    <div aria-hidden="true" className={cx('flex flex-col divide-y divide-vp-rule', className)}>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className={cx('flex gap-4', ballot ? 'items-start py-3' : 'items-center py-3.5')}>
          <Skeleton className={cx('shrink-0', ballot ? 'h-[6.25rem] w-10 rounded-vp-md' : 'h-9 w-9 rounded-vp-md')} />
          <div className={cx('flex-1 flex flex-col gap-2', ballot && 'pt-1')}>
            <Skeleton className="h-3.5 w-2/3" />
            <Skeleton className="h-3 w-1/3" />
            {ballot && <Skeleton className="h-3 w-1/4" />}
          </div>
          <Skeleton className={cx('hidden sm:block', ballot ? 'h-1.5 w-36 self-center' : 'h-3 w-20')} />
        </div>
      ))}
    </div>
  )
}

interface LoadingStateProps {
  /** Visible-to-AT label ("Loading…"); rendered visually hidden. */
  label: string
  rows?: number
  variant?: SkeletonRowVariant
  className?: string
}

/**
 * The one loading state. Announces politely, marks the region busy, and draws
 * skeleton rows instead of a spinner so the layout does not jump when data
 * arrives.
 */
export function LoadingState({ label, rows = 4, variant = 'list', className }: LoadingStateProps) {
  return (
    <div role="status" aria-live="polite" aria-busy="true" className={cx('py-2', className)}>
      <span className="sr-only">{label}</span>
      <SkeletonRows rows={rows} variant={variant} />
    </div>
  )
}
