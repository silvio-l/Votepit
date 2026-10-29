import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface PageShellLegalLink {
  href: string
  label: string
}

export interface PageShellProps {
  children: ReactNode
  /** The top bar (a Header or a DS top bar). */
  header?: ReactNode
  /**
   * Footer links (legal notice, terms, privacy, …). The caller decides what to
   * show. Omitted or empty: no footer row is rendered.
   */
  legalLinks?: readonly PageShellLegalLink[]
  /**
   * Content width: "default" fluid (lists, admin and data-dense pages), "narrow"
   * for single-column forms and reading (44 rem), "wide" for dashboards (1440 px).
   */
  width?: 'default' | 'narrow' | 'wide'
}

/**
 * The public page frame: top bar, centred content column, legal footer. Board
 * pages and everything a visitor sees without signing in use it; the admin area
 * uses the app shell (sidebar and top bar) instead.
 */
export function PageShell({ children, header, legalLinks = [], width = 'default' }: PageShellProps) {
  const frame = `ds-page-shell__frame--${width}`
  return (
    <div className="ds-page-shell">
      {header}
      <main id="main" className={cx('ds-page-shell__main', frame)}>
        {children}
      </main>
      {legalLinks.length > 0 && (
        <div className={cx('ds-page-shell__legal-frame', frame)}>
          <footer className="ds-page-shell__legal">
            {legalLinks.map((link, i) => (
              <a
                key={link.href}
                href={link.href}
                rel="noopener noreferrer"
                data-testid={`page-shell-legal-${i}`}
              >
                {link.label}
              </a>
            ))}
          </footer>
        </div>
      )}
    </div>
  )
}
