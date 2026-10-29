import type { ComponentPropsWithoutRef, ElementType, ReactNode } from 'react'
import { cx } from '../lib/cx'

type BackLinkProps<T extends ElementType> = {
  /** Element to render as — a router `<Link>` for in-app routes, `<a>` (default) otherwise. */
  as?: T
  children: ReactNode
  className?: string
} & Omit<ComponentPropsWithoutRef<T>, 'as' | 'children' | 'className'>

/**
 * The one "back up" link a page carries above its title (PageHeader `back`):
 * a small chevron and a quiet label, ink on hover. Pass `as={Link}` with
 * `to` for router routes so the desk does not reload.
 *
 * @deprecated core/app uses `BackLink` from `@votepit/ui/ds`; kept for cloud/app.
 */
export function BackLink<T extends ElementType = 'a'>({
  as,
  children,
  className,
  ...props
}: BackLinkProps<T>) {
  const Tag = (as ?? 'a') as ElementType
  return (
    <Tag
      className={cx(
        'inline-flex items-center gap-1 text-vp-sm font-medium text-vp-text-secondary no-underline max-sm:min-h-11',
        'hover:text-vp-title hover:underline underline-offset-2 transition-colors duration-ds-fast rounded-vp-sm',
        className,
      )}
      {...props}
    >
      <svg
        viewBox="0 0 16 16"
        width="14"
        height="14"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.75"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
        className="shrink-0 -ml-0.5"
      >
        <path d="M10 3.5 5.5 8l4.5 4.5" />
      </svg>
      {children}
    </Tag>
  )
}
