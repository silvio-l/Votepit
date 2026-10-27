import type { ReactNode } from 'react'
import { useId } from 'react'
import { cx } from '../lib/cx'

export type SectionEmphasis = 'none' | 'ruled' | 'danger'

export interface SectionProps {
  title?: ReactNode
  description?: ReactNode
  /** Small glyph before the title (16 px). Decorative. */
  icon?: ReactNode
  /** Right-aligned controls in the section head. */
  actions?: ReactNode
  children: ReactNode
  /** Foot row (save row, meta), separated by a hairline rule. */
  footer?: ReactNode
  /**
   * "ruled": a 2 px ink head rule, one per page for the sheet that is the page.
   * "danger": a coral top edge for a destructive zone.
   */
  emphasis?: SectionEmphasis
  /** Heading level of the title (2 by default; 3 inside a page that already has h2s). */
  headingLevel?: 2 | 3
  /** Removes the body padding; for lists and tables that rule their own rows. */
  flush?: boolean
  /** Extra classes for the body wrapper (e.g. a wider padding on a wizard step). */
  bodyClassName?: string
  /** Stable data-testid on the section landmark (kebab-case, `<screen>-<element>`). */
  testId: string
  id?: string
  className?: string
}

/**
 * Plain container on the paper: one hairline, head / body / foot, square corners.
 * Not interactive; it only groups a form, list or settings block.
 */
export function Section({
  title,
  description,
  icon,
  actions,
  children,
  footer,
  emphasis = 'none',
  flush = false,
  bodyClassName,
  headingLevel = 2,
  testId,
  id,
  className,
}: SectionProps) {
  const headingId = useId()
  const hasHead = Boolean(title || description || actions)
  const Heading = headingLevel === 3 ? 'h3' : 'h2'

  return (
    <section
      id={id}
      data-testid={testId}
      aria-labelledby={title ? headingId : undefined}
      className={cx('ds-section', `ds-section--${emphasis}`, className)}
    >
      {hasHead && (
        <div className="ds-section__head">
          <div className="ds-section__heading">
            {title && (
              <Heading id={headingId} className="ds-section__title">
                {icon && (
                  <span className="ds-section__icon" aria-hidden="true">
                    {icon}
                  </span>
                )}
                <span className="ds-section__title-text">{title}</span>
              </Heading>
            )}
            {description && <p className="ds-section__description">{description}</p>}
          </div>
          {actions && <div className="ds-section__actions">{actions}</div>}
        </div>
      )}
      <div className={cx('ds-section__body', flush && 'ds-section__body--flush', bodyClassName)}>
        {children}
      </div>
      {footer && <div className="ds-section__foot">{footer}</div>}
    </section>
  )
}
