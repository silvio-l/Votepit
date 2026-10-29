import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface BreadcrumbItem {
  label: ReactNode
  /** Omit on the last item: it is the current page and renders as text. */
  href?: string
}

export interface BreadcrumbsProps {
  items: BreadcrumbItem[]
  /** Accessible name of the landmark. */
  ariaLabel: string
  className?: string
  /** Stable data-testid on the nav landmark (kebab-case, `<screen>-<element>`). */
  testId: string
}

/** Trail of where the reader is inside a section; the current page is plain text. */
export function Breadcrumbs({ items, ariaLabel, className, testId }: BreadcrumbsProps) {
  return (
    <nav aria-label={ariaLabel} className={cx('ds-breadcrumbs', className)} data-testid={testId}>
      <ol className="ds-breadcrumbs__list">
        {items.map((item, i) => {
          const last = i === items.length - 1
          return (
            // biome-ignore lint/suspicious/noArrayIndexKey: static trail, items never reorder
            <li key={i} className="ds-breadcrumbs__item">
              {i > 0 && (
                <svg
                  viewBox="0 0 16 16"
                  width="12"
                  height="12"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.75"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  aria-hidden="true"
                  className="ds-breadcrumbs__chevron"
                >
                  <path d="M6 3l5 5-5 5" />
                </svg>
              )}
              {item.href !== undefined && !last ? (
                <a href={item.href} className="ds-breadcrumbs__link ds-focusable">
                  {item.label}
                </a>
              ) : (
                <span aria-current="page" className="ds-breadcrumbs__current">
                  {item.label}
                </span>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
