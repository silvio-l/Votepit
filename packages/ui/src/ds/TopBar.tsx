import type { HTMLAttributes, ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface TopBarProps extends HTMLAttributes<HTMLElement> {
  /** Wordmark or logo link (left). */
  brand: ReactNode
  /** Primary nav links, each a `.ds-topbar__link` (optional). */
  nav?: ReactNode
  /** Trailing controls: language switch, then login button or account menu. */
  actions?: ReactNode
  /** Optional call to action, rendered last, e.g. a `variant="accent"` Button. */
  cta?: ReactNode
  /** Stable data-testid on the header element. */
  testId: string
  /** Accessible name of the nav landmark. */
  navLabel?: string
}

/**
 * The shared top bar (forest surface, white wordmark, mint active rule).
 * Markup and classes match the framework-neutral contract documented at the top
 * of the top-bar section in components.css — the landing renders the same tree.
 * A board page may restyle it by setting the --vpds-c-topbar-* custom properties
 * on an ancestor (app lib/branding.ts); the landing never does.
 */
export function TopBar({
  brand,
  nav,
  actions,
  cta,
  testId,
  navLabel,
  className,
  ...rest
}: TopBarProps) {
  return (
    <header className={cx('ds-topbar', className)} data-testid={testId} {...rest}>
      <div className="ds-topbar__inner">
        {brand}
        {nav && (
          <nav className="ds-topbar__nav" aria-label={navLabel}>
            {nav}
          </nav>
        )}
        {(actions || cta) && (
          <div className="ds-topbar__actions">
            {actions}
            {cta}
          </div>
        )}
      </div>
    </header>
  )
}
