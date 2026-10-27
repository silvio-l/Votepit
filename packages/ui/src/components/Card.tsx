import type { ElementType, HTMLAttributes, ReactNode } from 'react'
import { cx } from '../lib/cx'

interface CardProps extends HTMLAttributes<HTMLElement> {
  /** Lifts on hover / settles on press — for cards that are one big link or button. */
  interactive?: boolean
  /** Inner padding preset. */
  padding?: 'none' | 'sm' | 'md' | 'lg'
  /** Element to render as (a `<a>` or `<button>` for interactive cards). */
  as?: ElementType
  children: ReactNode
}

const paddingClasses = {
  none: '',
  sm: 'p-3',
  md: 'p-4 sm:p-5',
  lg: 'p-6 sm:p-8',
} as const

/**
 * A white plane with a hairline, square — for dashboard tiles, option
 * pickers and any block that groups a single thing. Interactive cards lift
 * 2px and take an ink edge on hover, like the landing's buttons. Use
 * `Section` for headed planes with a body and a foot; `Card` for the rest.
 */
export function Card({
  interactive = false,
  padding = 'md',
  as: Tag = 'div',
  className,
  children,
  ...props
}: CardProps) {
  return (
    <Tag
      className={cx(
        'vp-card block no-underline text-inherit',
        interactive && 'vp-card--interactive cursor-pointer',
        paddingClasses[padding],
        className,
      )}
      {...props}
    >
      {children}
    </Tag>
  )
}
