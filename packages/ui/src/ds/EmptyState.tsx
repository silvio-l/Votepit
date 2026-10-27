import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export type EmptyStateKind = 'no-ideas' | 'no-results' | 'no-access'

export interface EmptyStateProps {
  kind: EmptyStateKind
  /** The title, localized by the app. */
  title: string
  /** The cause, in plain words. */
  description?: ReactNode
  /** The fitting next step, e.g. a button or link. */
  action?: ReactNode
  /**
   * "card" (default) stands on its own. "plain" drops the card surface for use
   * inside a section body or a flush section, which already supplies the surface.
   */
  variant?: 'card' | 'plain'
  testId?: string
}

/** Empty list, empty search or missing access. States the cause and the next step; no decorative artwork. */
export function EmptyState({
  kind,
  title,
  description,
  action,
  variant = 'card',
  testId = 'empty-state',
}: EmptyStateProps) {
  return (
    <section
      className={cx('ds-empty-state', variant === 'plain' && 'ds-empty-state--plain')}
      data-kind={kind}
      data-testid={testId}
    >
      <h3 className="ds-empty-state__title">{title}</h3>
      {description !== undefined && <p className="ds-empty-state__text">{description}</p>}
      {action}
    </section>
  )
}
