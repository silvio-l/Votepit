import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface PageShellLegalLink {
  href: string
  label: string
}

interface PageShellProps {
  children: ReactNode
  header?: ReactNode
  /**
   * Footer links (legal notice, terms, privacy, …). The caller decides what
   * to show — this package knows nothing about who operates the
   * installation. Omitted or empty: no footer link row is rendered.
   */
  legalLinks?: readonly PageShellLegalLink[]
  /**
   * Content width: "default" for lists/admin/data-dense pages — fluid, no
   * artificial cap (same full-bleed gutter as the header bar; SaaS
   * dashboards like GitHub/Search Console never centre a data table in a
   * fixed column) — "narrow" for single-column forms and reading, which
   * stays centred and capped for line-length readability — or "wide" for
   * dashboards that want a generous but still bounded column.
   */
  width?: 'default' | 'narrow' | 'wide'
}

/** Footer link row shared by every shell. */
export function LegalFooter({
  legalLinks,
  className,
}: {
  legalLinks: readonly PageShellLegalLink[]
  className?: string
}) {
  if (legalLinks.length === 0) return null
  return (
    <footer
      className={cx(
        'flex flex-wrap items-center gap-x-4 gap-y-1 py-5 text-vp-xs text-vp-text-muted',
        className,
      )}
    >
      {legalLinks.map((link) => (
        <a
          key={link.href}
          href={link.href}
          rel="noopener noreferrer"
          className="no-underline hover:text-vp-ink hover:underline underline-offset-2 transition-colors duration-150"
        >
          {link.label}
        </a>
      ))}
    </footer>
  )
}

/**
 * The public page frame: top bar, centred content column, legal foot. Board
 * pages and everything a visitor sees without signing in live here; the
 * admin area uses `AppShell` (sidebar + top bar) instead.
 */
export function PageShell({ children, header, legalLinks = [], width = 'default' }: PageShellProps) {
  const containerClass = cx(
    width === 'default' ? 'vp-container-fluid' : 'vp-container',
    width === 'narrow' && 'vp-container--narrow',
    width === 'wide' && 'vp-container--wide',
  )
  return (
    <div className="min-h-screen flex flex-col vp-desk text-vp-ink">
      {header}
      <main id="main" className={cx(containerClass, 'flex-1 py-6 md:py-10')}>
        {children}
      </main>
      {legalLinks.length > 0 && (
        <div className={cx(containerClass, 'border-t border-vp-border-subtle')}>
          <LegalFooter legalLinks={legalLinks} />
        </div>
      )}
    </div>
  )
}
