import type { ReactNode } from 'react'
import { useId } from 'react'
import { cx } from '../lib/cx'

interface SectionProps {
  title?: ReactNode
  description?: ReactNode
  /** Small glyph before the title (16px). Decorative. */
  icon?: ReactNode
  /** Right-aligned controls in the section head. */
  actions?: ReactNode
  children: ReactNode
  /** Sheet foot (save row, meta). Separated by a hairline rule. */
  footer?: ReactNode
  /**
   * "ruled": the 2px ink head rule — one per page, for the sheet that IS the
   * page (the idea list, the current plan). "accent": the tenant's colour as
   * a thin edge (board masthead only). "danger": a coral edge for the
   * destructive zone of a settings page.
   */
  emphasis?: 'none' | 'ruled' | 'accent' | 'danger'
  /** Removes the body padding — for tables and lists that rule their own rows. */
  flush?: boolean
  /** Heading level of the title (2 by default; 3 inside a page that already has h2s). */
  headingLevel?: 2 | 3
  id?: string
  className?: string
  /** Extra classes for the body wrapper. */
  bodyClassName?: string
}

/**
 * A sheet of paper: white, one hairline ring, head / body / foot.
 * Every admin form, list and result block lives on one of these — the only
 * container the app has, so nothing is ever nested inside another card.
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
  headingLevel = 2,
  id,
  className,
  bodyClassName,
}: SectionProps) {
  const headingId = useId()
  const hasHead = Boolean(title || description || actions)
  const Heading = headingLevel === 3 ? 'h3' : 'h2'

  return (
    <section
      id={id}
      aria-labelledby={title ? headingId : undefined}
      className={cx(
        'vp-sheet overflow-hidden',
        emphasis === 'ruled' && 'vp-sheet--ruled',
        emphasis === 'accent' && 'vp-sheet--accent',
        emphasis === 'danger' && 'border-vp-vote-down/40',
        className,
      )}
    >
      {hasHead && (
        <div
          className={cx(
            'flex flex-wrap items-start justify-between gap-x-6 gap-y-2 px-4 sm:px-5 py-3.5 border-b border-vp-border-subtle',
            emphasis === 'danger' && 'bg-vp-vote-down-soft/40',
          )}
        >
          <div className="min-w-0 flex-1">
            {title && (
              <Heading
                id={headingId}
                className="flex items-center gap-2 text-vp-md font-semibold text-vp-ink leading-6 tracking-[-0.005em]"
              >
                {icon && (
                  <span
                    aria-hidden="true"
                    className={cx(
                      'inline-flex shrink-0',
                      emphasis === 'danger' ? 'text-vp-vote-down-strong' : 'text-vp-text-muted',
                    )}
                  >
                    {icon}
                  </span>
                )}
                <span className="min-w-0">{title}</span>
              </Heading>
            )}
            {description && (
              <p className="mt-0.5 text-vp-sm text-vp-text-secondary max-w-prose text-pretty">
                {description}
              </p>
            )}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
      )}
      <div className={cx(!flush && 'px-4 sm:px-5 py-4', bodyClassName)}>{children}</div>
      {footer && (
        <div className="flex flex-wrap items-center gap-3 px-4 sm:px-5 py-3 border-t border-vp-border-subtle bg-vp-surface-frost">
          {footer}
        </div>
      )}
    </section>
  )
}
