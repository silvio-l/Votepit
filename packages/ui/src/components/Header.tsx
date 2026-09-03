import type { ReactNode } from 'react'
import { cx } from '../lib/cx'
import { Button } from './Button'

// Same hex paths as BrandBackdrop.tsx — kept in sync.
const TOP =
  'M 165.0 0.0 L 165.0 -44.0 Q 165.0 -72.0 141.6 -87.3 L 23.4 -164.7 Q 0.0 -180.0 -23.4 -164.7 L -141.6 -87.3 Q -165.0 -72.0 -165.0 -44.0 L -165.0 0.0 Z'
const BOT =
  'M -165.0 0.0 L -165.0 44.0 Q -165.0 72.0 -141.6 87.3 L -23.4 164.7 Q 0.0 180.0 23.4 164.7 L 141.6 87.3 Q 165.0 72.0 165.0 44.0 L 165.0 0.0 Z'
const MID =
  'M -15.9 -112.0 Q 0.0 -122.4 15.9 -112.0 L 96.3 -59.4 Q 112.2 -49.0 112.2 -29.9 L 112.2 29.9 Q 112.2 49.0 96.3 59.4 L 15.9 112.0 Q 0.0 122.4 -15.9 112.0 L -96.3 59.4 Q -112.2 49.0 -112.2 29.9 L -112.2 -29.9 Q -112.2 -49.0 -96.3 -59.4 Z'
const DARK =
  'M -11.7 -82.3 Q 0.0 -90.0 11.7 -82.3 L 70.8 -43.7 Q 82.5 -36.0 82.5 -22.0 L 82.5 22.0 Q 82.5 36.0 70.8 43.7 L 11.7 82.3 Q 0.0 90.0 -11.7 82.3 L -70.8 43.7 Q -82.5 36.0 -82.5 22.0 L -82.5 -22.0 Q -82.5 -36.0 -70.8 -43.7 Z'

export interface HeaderNavLink {
  label: string
  href: string
  /** Optional 16px glyph. */
  icon?: ReactNode
}

interface HeaderProps {
  logoHref?: string
  loginLabel?: string
  onLoginClick?: () => void
  /**
   * Session control for a signed-in visitor (typically a `Menu` grouping
   * profile, admin and logout). Rendered in place of the login button when
   * given — the bar itself has no notion of "authenticated".
   */
  account?: ReactNode
  /**
   * Board context path for the default nav links (ADR-11).
   * CE: "/{board}" (e.g. "/feedback"), Cloud: "/{account}/{board}".
   * Default: "" (no board context, e.g. login page or admin).
   */
  basePath?: string
  /** i18n overrides — all default to German so existing callers keep working untranslated. */
  logoAriaLabel?: string
  navAriaLabel?: string
  boardLabel?: string
  roadmapLabel?: string
  /** Optional controls rendered before the session control (e.g. a language switcher). */
  navExtra?: ReactNode
  /**
   * Overrides the default Board/Roadmap links with an arbitrary set (e.g.
   * account-admin pages, where "Board"/"Roadmap" relative to basePath don't
   * resolve to anything meaningful). Absolute hrefs — not joined with basePath.
   * Pass an empty array to render no page nav at all (the app shell's
   * sidebar carries it instead).
   */
  navLinks?: HeaderNavLink[]
  /**
   * Which Votepit edition is running — Cloud (multi-tenant) or Community
   * (self-host). Every deployment knows this unambiguously (it is 1:1 with
   * `Config::routingMode`), so it is required, not optional: the wordmark
   * always carries "│ CLOUD" or "│ COMMUNITY", never a neutral/blank state,
   * mirroring web/'s BrandLockup.astro so both surfaces show the same two
   * fixed marks and nothing else (brand-consistency requirement).
   */
  edition: 'cloud' | 'community'
  /**
   * Scope line rendered next to the wordmark: which account / board the
   * visitor is acting in (tenant isolation must stay visible on every view).
   * Pass ready-made text or nodes, e.g. `acme / Feedback`.
   */
  scope?: ReactNode
  /** href of the current page — marks the matching nav link with aria-current. */
  currentHref?: string
}

/**
 * The one dual-mark wordmark, in the two fixed variants the brand allows —
 * "Votepit │ CLOUD" (teal suffix) or "Votepit │ COMMUNITY" (muted grey
 * suffix), never anything else. Mirrors web/'s BrandLockup.astro 1:1 so the
 * two surfaces show identical marks; the icon itself never changes between
 * editions, only the suffix colour and text do.
 */
export function VotepitLogo({
  href = '/',
  ariaLabel = 'Votepit – Startseite',
  edition,
  onInk = false,
}: {
  href?: string
  ariaLabel?: string
  edition: 'cloud' | 'community'
  /** Renders the wordmark for a dark (ink) ground. */
  onInk?: boolean
}) {
  const editionLabel = edition === 'cloud' ? 'CLOUD' : 'COMMUNITY'
  return (
    <a
      href={href}
      className="flex items-center gap-1.5 no-underline shrink-0 rounded-vp-sm"
      aria-label={ariaLabel}
    >
      <svg viewBox="-185 -205 370 410" width="22" height="24" fill="none" aria-hidden="true" className="shrink-0">
        <path d={TOP} fill="var(--color-vp-vote-up)" />
        <path d={BOT} fill="var(--color-vp-vote-down)" />
        <path d={MID} fill="#084C37" />
        <path d={DARK} fill="#05241A" />
      </svg>
      <span className="flex items-baseline gap-[0.22em] font-archivo font-extrabold text-[19px] leading-none tracking-[-0.03em] select-none">
        <span>
          <span className={onInk ? 'text-white' : 'text-vp-ink'}>Vote</span>
          <span className="text-vp-vote-down">pit</span>
        </span>
        <span
          aria-hidden="true"
          className={cx(
            'font-inter font-normal opacity-55 text-[0.85em]',
            onInk ? 'text-white' : 'text-vp-text-muted',
          )}
        >
          │
        </span>
        <span
          className={cx(
            'text-[0.62em] tracking-[0.08em]',
            edition === 'cloud'
              ? onInk
                ? 'text-vp-vote-up'
                : 'text-vp-cloud-ink'
              : onInk
                ? 'text-white/70'
                : 'text-vp-text-muted',
          )}
        >
          {editionLabel}
        </span>
      </span>
    </a>
  )
}

/**
 * App bar: wordmark, the scope you are acting in, page nav, session control.
 * 56px, glass over the desk, one hairline rule — it is a bar, not a hero.
 *
 * Two kinds of thing live here and never mix: the nav ("where am I" — the
 * links of the section the visitor is in) and the trailing controls (the
 * language switcher and one session control: login button or account menu).
 */
export function Header({
  logoHref = '/',
  loginLabel = 'Anmelden',
  onLoginClick,
  account,
  basePath = '',
  logoAriaLabel = 'Votepit – Startseite',
  navAriaLabel = 'Hauptnavigation',
  boardLabel = 'Board',
  roadmapLabel = 'Roadmap',
  navExtra,
  navLinks,
  edition,
  scope,
  currentHref,
}: HeaderProps) {
  const links: HeaderNavLink[] = navLinks ?? [
    { label: boardLabel, href: basePath || '/' },
    { label: roadmapLabel, href: `${basePath}/roadmap` },
  ]
  const hasNav = links.length > 0

  return (
    <header className="sticky top-0 z-40 w-full vp-glass border-b border-vp-border-subtle">
      <div className="vp-container-fluid flex flex-wrap items-center gap-x-3 min-h-14 py-2 sm:py-0 sm:h-14">
        <VotepitLogo href={logoHref} ariaLabel={logoAriaLabel} edition={edition} />

        {scope && (
          <div className="flex min-w-0 items-center gap-2 text-vp-sm text-vp-text-secondary">
            <span aria-hidden="true" className="h-4 w-px bg-vp-rule" />
            <span className="truncate">{scope}</span>
          </div>
        )}

        {hasNav && (
          <nav
            className="order-last sm:order-none w-full sm:w-auto sm:ml-auto flex items-center gap-0.5 -mx-2 sm:mx-0 mt-1 sm:mt-0 pt-1 sm:pt-0 border-t border-vp-border-subtle sm:border-0"
            aria-label={navAriaLabel}
          >
            {links.map((link) => {
              const current = currentHref !== undefined && currentHref === link.href
              return (
                <a
                  key={link.href}
                  href={link.href}
                  aria-current={current ? 'page' : undefined}
                  className={cx(
                    'inline-flex items-center gap-1.5 h-8 px-2.5 rounded-vp-md text-vp-sm no-underline transition-colors duration-150',
                    current
                      ? 'font-semibold text-vp-ink bg-vp-ink-soft'
                      : 'font-medium text-vp-text-secondary hover:text-vp-ink hover:bg-vp-ink-soft',
                  )}
                >
                  {link.icon && (
                    <span aria-hidden="true" className="inline-flex">
                      {link.icon}
                    </span>
                  )}
                  {link.label}
                </a>
              )
            })}
          </nav>
        )}

        <div className={cx('flex items-center gap-1.5', hasNav ? 'ml-auto sm:ml-0' : 'ml-auto')}>
          {navExtra}
          {account ?? (
            <Button variant="primary" size="sm" onClick={onLoginClick}>
              {loginLabel}
            </Button>
          )}
        </div>
      </div>
    </header>
  )
}
