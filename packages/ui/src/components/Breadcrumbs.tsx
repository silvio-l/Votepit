import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface BreadcrumbItem {
  label: ReactNode
  /** Omit on the last item — it is the current page and renders as text. */
  href?: string
}

interface BreadcrumbsProps {
  items: BreadcrumbItem[]
  /** Accessible name of the landmark. */
  ariaLabel?: string
  className?: string
}

function Chevron() {
  return (
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
      className="text-vp-text-tertiary shrink-0"
    >
      <path d="M6 3l5 5-5 5" />
    </svg>
  )
}

/**
 * Trail of where the reader is inside a section (Boards › Feedback › API
 * tokens). 13px, muted links with chevrons between, the current page in ink.
 * Sits in `PageHeader`'s back slot; replaces the single back link wherever
 * the page is more than one level deep.
 */
export function Breadcrumbs({ items, ariaLabel = 'Breadcrumb', className }: BreadcrumbsProps) {
  return (
    <nav aria-label={ariaLabel} className={cx('text-vp-sm', className)}>
      <ol className="flex flex-wrap items-center gap-x-1.5 m-0 p-0 list-none">
        {items.map((item, i) => {
          const last = i === items.length - 1
          return (
            <li key={item.href ?? `current-${i}`} className="flex items-center gap-x-1.5 min-w-0">
              {i > 0 && <Chevron />}
              {item.href !== undefined && !last ? (
                <a
                  href={item.href}
                  className="text-vp-text-secondary no-underline rounded-vp-xs px-1 -mx-1 hover:text-vp-ink hover:bg-vp-ink-soft transition-colors duration-150"
                >
                  {item.label}
                </a>
              ) : (
                <span aria-current="page" className="truncate font-medium text-vp-ink">
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
