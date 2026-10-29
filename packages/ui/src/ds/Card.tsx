import type { ElementType, HTMLAttributes, ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface CardProps extends HTMLAttributes<HTMLElement> {
  children: ReactNode
  /** Hover lift for cards that are opened or acted on as a whole. */
  interactive?: boolean
  padding?: 'none' | 'sm' | 'md' | 'lg'
  as?: ElementType
  testId?: string
}

/** Surface for one item (a board, an entry). Plain container; the controls inside carry the actions. */
export function Card({
  children,
  interactive = false,
  padding = 'md',
  as: Tag = 'div',
  className,
  testId,
  ...rest
}: CardProps) {
  return (
    <Tag
      {...rest}
      data-testid={testId}
      className={cx(
        'ds-card',
        `ds-card--pad-${padding}`,
        interactive && 'ds-card--interactive',
        className,
      )}
    >
      {children}
    </Tag>
  )
}
