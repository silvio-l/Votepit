import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  /** Small caps label above the title (section name, board scope). */
  eyebrow?: ReactNode
  /** Back link / breadcrumbs rendered above the title (pass a router <Link> or <a>). */
  back?: ReactNode
  /** Right-aligned primary actions. Wrap under the title on narrow screens. */
  actions?: ReactNode
  /** Row under the head: tabs, filters, meta. Not padded — sits on the baseline rule. */
  children?: ReactNode
  /** "display" for the board masthead (Archivo), "default" for admin pages. */
  size?: 'default' | 'display'
  /** Small element next to the title (a badge). */
  titleEnd?: ReactNode
  className?: string
}

/**
 * Page head: optional back link, eyebrow, title, one-line description,
 * actions, and an optional control row. The title is the page's h1 — exactly
 * one per page.
 */
export function PageHeader({
  title,
  description,
  eyebrow,
  back,
  actions,
  children,
  size = 'default',
  titleEnd,
  className,
}: PageHeaderProps) {
  return (
    <div className={cx('mb-6 animate-vp-fade-in', className)}>
      {back && <div className="mb-3 text-vp-sm">{back}</div>}
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div className="min-w-0 flex-1">
          {eyebrow && <div className="vp-eyebrow mb-1.5">{eyebrow}</div>}
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h1
              className={cx(
                'text-vp-ink text-balance',
                size === 'display'
                  ? 'font-archivo font-bold text-vp-3xl md:text-vp-4xl tracking-[-0.025em]'
                  : 'font-semibold text-vp-2xl tracking-[-0.02em]',
              )}
            >
              {title}
            </h1>
            {titleEnd}
          </div>
          {description && (
            <p
              className={cx(
                'mt-1.5 text-vp-text-secondary max-w-prose text-pretty',
                size === 'display' ? 'text-vp-md md:text-vp-lg' : 'text-vp-md',
              )}
            >
              {description}
            </p>
          )}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {children && <div className="mt-5">{children}</div>}
    </div>
  )
}
