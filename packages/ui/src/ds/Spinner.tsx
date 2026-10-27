import { cx } from '../lib/cx'

export interface SpinnerProps {
  className?: string
}

/**
 * Inline loading indicator. Decorative: the control or region it belongs to carries the state
 * (aria-busy on a button, a live status message on a region).
 */
export function Spinner({ className }: SpinnerProps) {
  return <span aria-hidden="true" className={cx('ds-spinner', className)} />
}
