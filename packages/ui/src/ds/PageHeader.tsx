import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface PageHeaderProps {
  title: ReactNode
  description?: ReactNode
  /** Small label above the title (section name, board scope). */
  eyebrow?: ReactNode
  /** Breadcrumbs or a back link above the title. */
  back?: ReactNode
  /** Right-aligned actions. They wrap under the title on narrow screens. */
  actions?: ReactNode
  /** Row under the head (tabs, filters, meta). */
  children?: ReactNode
  /** Small element next to the title (a badge). */
  titleEnd?: ReactNode
  /** display: the hero voice for a public masthead; default: the section voice. */
  size?: 'default' | 'display'
  className?: string
}

/**
 * Page head: optional back trail, eyebrow, the page's single h1, one-line description,
 * actions and an optional control row.
 */
export function PageHeader({
  title,
  description,
  eyebrow,
  back,
  actions,
  children,
  titleEnd,
  size = 'default',
  className,
}: PageHeaderProps) {
  return (
    <div className={cx('ds-page-header', className)}>
      {back && <div className="ds-page-header__back">{back}</div>}
      <div className="ds-page-header__head">
        <div className="ds-page-header__main">
          {eyebrow && <div className="ds-page-header__eyebrow">{eyebrow}</div>}
          <div className="ds-page-header__title-row">
            <h1
              className={cx(
                'ds-page-header__title',
                size === 'display' && 'ds-page-header__title--display',
              )}
            >
              {title}
            </h1>
            {titleEnd}
          </div>
          {description && <p className="ds-page-header__description">{description}</p>}
        </div>
        {actions && <div className="ds-page-header__actions">{actions}</div>}
      </div>
      {children && <div className="ds-page-header__row">{children}</div>}
    </div>
  )
}
