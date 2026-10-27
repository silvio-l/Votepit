import { cx } from '../lib/cx'

export interface PageShellLegalLink {
  href: string
  label: string
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
          className="no-underline hover:text-vp-ink hover:underline decoration-2 underline-offset-[0.2em] decoration-vp-underline transition-colors duration-ds-fast"
        >
          {link.label}
        </a>
      ))}
    </footer>
  )
}
