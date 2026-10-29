import type { ComponentPropsWithoutRef, ElementType, ReactNode } from 'react'
import { cx } from '../lib/cx'

export type BackLinkProps<T extends ElementType> = {
  /** Element to render as: a router link for in-app routes, an anchor otherwise. */
  as?: T
  /** The link label, localized by the app. */
  children: ReactNode
  /** Stable test id (data-testid). */
  testId: string
  className?: string
} & Omit<ComponentPropsWithoutRef<T>, 'as' | 'children' | 'className' | 'data-testid'>

/**
 * The one "back up" link a page carries above its title: a small chevron and a
 * quiet label, ink on hover. Pass `as={Link}` with `to` for router routes so the
 * app does not reload.
 */
export function BackLink<T extends ElementType = 'a'>({
  as,
  children,
  testId,
  className,
  ...props
}: BackLinkProps<T>) {
  const Tag = (as ?? 'a') as ElementType
  return (
    <Tag className={cx('ds-back-link', className)} data-testid={testId} {...props}>
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
        className="ds-back-link__icon"
      >
        <path d="M10 3.5 5.5 8l4.5 4.5" />
      </svg>
      {children}
    </Tag>
  )
}
