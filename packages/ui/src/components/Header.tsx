import type { ElementType, ReactNode } from 'react'
import { Avatar } from '../ds/Avatar'
import { TopBar } from '../ds/TopBar'
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
  /** Link relation, e.g. "noopener nofollow ugc" for owner-provided links. */
  rel?: string
  /** Other host: opens in a new tab and announces it through `externalHint`. */
  external?: boolean
  /** Screen-reader text for an external link, e.g. "opens in a new tab". */
  externalHint?: string
}

interface HeaderProps {
  logoHref?: string
  loginLabel: string
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
  /** Required i18n labels: the caller passes translated strings, the bar ships no German defaults. */
  logoAriaLabel: string
  navAriaLabel: string
  boardLabel: string
  roadmapLabel: string
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
  /** Appends a "· DEMO" suffix to the edition label — see VotepitLogo. */
  demo?: boolean
  /**
   * Scope line rendered next to the wordmark: which account / board the
   * visitor is acting in (tenant isolation must stay visible on every view).
   * Pass ready-made text or nodes, e.g. `acme / Feedback`.
   */
  scope?: ReactNode
  /** href of the current page — marks the matching nav link with aria-current. */
  currentHref?: string
  /**
   * Element the page-nav links are rendered as instead of a plain `<a>` —
   * pass a host router's Link (adapted to accept an `href` prop, e.g.
   * `to={href}`) so nav clicks stay client-side navigations instead of full
   * page reloads. Defaults to `'a'` (a non-router consumer, e.g. Astro,
   * keeps working unchanged). Same convention as BackLink's `as` prop.
   */
  linkAs?: ElementType
  /**
   * Optional link back to the site this app lives under (e.g. the marketing
   * landing page on votepit.com, or an operator's own homepage) — rendered
   * as a small link before the wordmark. Only shown when both are given.
   */
  homeHref?: string
  homeLabel?: string
  /**
   * Per-tenant logo (already validated server-side, see BrandingValidator::
   * logoUrl() — https-only or a relative internal path, never attacker
   * input) that REPLACES the Votepit wordmark, not just decorates it — this
   * is the product's actual white-label promise ("your board, your brand"),
   * not a cosmetic add-on. Only swapped in when both are given; the
   * wordmark stays the fallback everywhere else (login, admin, operator
   * panel, no-logo boards).
   */
  brandLogoUrl?: string
  /**
   * Board wordmark (wide topbar logo, BR-LOGO2). Shown before the app symbol when
   * given. `brandWordmarkPlated` puts a light plate behind it on a bar it would
   * otherwise vanish on (decided by the app, see brandPlate.ts).
   */
  brandWordmarkUrl?: string
  brandWordmarkPlated?: boolean
  brandLogoAlt?: string
  /**
   * True while the caller hasn't yet learned whether this board has a
   * custom logo (board data still loading). Renders a neutral placeholder
   * instead of the Votepit wordmark, so a branded board never flashes the
   * platform mark for a moment before swapping to its own logo.
   */
  brandLogoPending?: boolean
  /**
   * Lite and up without an uploaded or linked logo: the board name's initials on
   * the validated accent (one logo source, no Votepit mark). Free keeps the mark.
   */
  brandInitialsName?: string
  brandAccent?: string
  /**
   * A phone bottom tab bar carries the page nav instead: below 640 px the top
   * bar then drops its nav row (desktop unchanged).
   */
  tabbar?: boolean
}

/**
 * The one dual-mark wordmark, in the two fixed variants the brand allows —
 * "Votepit │ CLOUD" (teal suffix) or "Votepit │ COMMUNITY" (muted grey
 * suffix), never anything else. Mirrors web/'s BrandLockup.astro 1:1 so the
 * two surfaces show identical marks; the icon itself never changes between
 * editions, only the suffix colour and text do.
 */
/**
 * The Votepit mark (two vote tiles over a hexagon), 22 x 24. Decorative: the
 * wordmark or the link next to it carries the accessible name.
 */
export function VotepitMark() {
  return (
    <svg
      viewBox="-185 -205 370 410"
      width="22"
      height="24"
      fill="none"
      aria-hidden="true"
      className="shrink-0"
    >
      <path d={TOP} fill="var(--color-vp-vote-up)" />
      <path d={BOT} fill="var(--color-vp-vote-down)" />
      {/* Fixed brand marks: the Votepit logo keeps its own colours in both schemes. */}
      <path d={MID} fill="#084C37" />
      <path d={DARK} fill="#05241A" />
    </svg>
  )
}

export function VotepitLogo({
  href = '/',
  ariaLabel,
  edition,
  demo = false,
  onInk = false,
  size = 'default',
  testId,
}: {
  href?: string
  /** Accessible name of the brand link, already translated (no default: packages/ui ships no prose). */
  ariaLabel: string
  edition: 'cloud' | 'community'
  /** Stable data-testid on the brand link (`<screen>-logo`). */
  testId?: string
  /** Appends a "· DEMO" suffix — a throwaway demo instance representing the edition, not a real one. */
  demo?: boolean
  /** Renders the wordmark for a dark (ink) ground. */
  onInk?: boolean
  /** Landing-sized wordmark (auth header). */
  size?: 'default' | 'lg'
}) {
  const editionLabel = (edition === 'cloud' ? 'CLOUD' : 'COMMUNITY') + (demo ? ' · DEMO' : '')
  return (
    <a
      href={href}
      className="ds-topbar__brand flex items-center gap-1.5 no-underline shrink-0 rounded-vp-sm"
      aria-label={ariaLabel}
      data-testid={testId}
    >
      <span className="ds-topbar__mark">
        <VotepitMark />
      </span>
      <span
        className={cx(
          'ds-topbar__wordmark flex items-baseline gap-[0.22em] font-archivo font-extrabold leading-none tracking-[-0.03em] select-none',
          size === 'lg' ? 'text-vp-brand-lg' : 'text-vp-brand',
        )}
      >
        <span className="ds-topbar__wordmark-name">
          {/* On a bar the colours come from --vpds-c-topbar-* (a board may restyle the bar). */}
          <span className={onInk ? 'ds-topbar__on-bar' : 'text-vp-title'}>Vote</span>
          <span className={onInk ? 'ds-topbar__pit' : 'text-vp-vote-down'}>pit</span>
        </span>
        <span
          aria-hidden="true"
          className={cx(
            'ds-topbar__wordmark-name font-inter font-normal opacity-55 text-[0.85em]',
            onInk ? 'ds-topbar__on-bar' : 'text-vp-text-muted',
          )}
        >
          │
        </span>
        <span
          className={cx(
            'text-[0.62em] tracking-[0.08em]',
            edition === 'cloud'
              ? onInk
                ? 'ds-topbar__on-bar'
                : 'text-vp-cloud-ink'
              : onInk
                ? 'ds-topbar__edition-community'
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
 * 56px, a flat paper bar on the paper ground with one hairline rule — the
 * landing's header, so the visitor crossing from votepit.com into a board
 * lands under the same bar. Nav links mark "current" with a 2px ink
 * underline (the landing's rule), never a pill.
 *
 * Two kinds of thing live here and never mix: the nav ("where am I" — the
 * links of the section the visitor is in) and the trailing controls (the
 * language switcher and one session control: login button or account menu).
 */
export function Header({
  logoHref = '/',
  loginLabel,
  onLoginClick,
  account,
  basePath = '',
  logoAriaLabel,
  navAriaLabel,
  boardLabel,
  roadmapLabel,
  navExtra,
  navLinks,
  edition,
  demo = false,
  scope,
  currentHref,
  linkAs,
  homeHref,
  homeLabel,
  brandLogoUrl,
  brandWordmarkUrl,
  brandWordmarkPlated = false,
  brandLogoAlt,
  brandLogoPending = false,
  brandInitialsName,
  brandAccent,
  tabbar = false,
}: HeaderProps) {
  const NavLink = (linkAs ?? 'a') as ElementType
  const links: HeaderNavLink[] = navLinks ?? [
    { label: boardLabel, href: basePath || '/' },
    { label: roadmapLabel, href: `${basePath}/roadmap` },
  ]
  const hasNav = links.length > 0

  const brand = (
    <>
      {homeHref && homeLabel && (
        <a
          href={homeHref}
          className="hidden sm:inline-flex items-center gap-1 text-vp-xs font-medium text-vp-on-ink-muted no-underline hover:text-vp-on-ink shrink-0"
        >
          <span aria-hidden="true">←</span>
          {homeLabel}
        </a>
      )}
      {brandLogoPending ? (
        <span aria-hidden="true" className="ds-skeleton__block h-8 w-24 shrink-0" />
      ) : brandWordmarkUrl ? (
        <a
          href={logoHref}
          className="flex items-center shrink-0 rounded-vp-sm"
          aria-label={logoAriaLabel}
          data-testid="header-wordmark"
        >
          {/* Fixed white plate (exception): a wordmark that would vanish on the bar sits on paper in both schemes. */}
          <span className={brandWordmarkPlated ? 'inline-flex rounded-vp-sm bg-white px-1' : 'inline-flex'}>
            <img
              src={brandWordmarkUrl}
              alt={brandLogoAlt ?? ''}
              className="h-8 max-w-[14rem] object-contain object-left"
              onError={(e) => {
                e.currentTarget.style.display = 'none'
              }}
            />
          </span>
        </a>
      ) : brandLogoUrl ? (
        <a
          href={logoHref}
          className="flex items-center shrink-0 rounded-vp-sm"
          aria-label={logoAriaLabel}
        >
          {/* The plate (board topbar colour, white or none) sits behind the round logo, so
              transparent corners show a colour instead of the page; the ring is hideable. */}
          <span
            className="inline-flex"
            style={{
              background: 'var(--vpds-c-topbar-logo-plate, transparent)',
              boxShadow: 'var(--vpds-c-topbar-logo-ring, none)',
              borderRadius: '22%',
            }}
            data-testid="topbar-logo-plate"
          >
            <img
              src={brandLogoUrl}
              alt={brandLogoAlt ?? ''}
              className="h-8 max-w-vp-logo object-contain object-left"
              onError={(e) => {
                e.currentTarget.style.display = 'none'
              }}
            />
          </span>
        </a>
      ) : brandInitialsName ? (
        <a
          href={logoHref}
          // min-w-0 lets the link shrink on phones so the long board name truncates
          // instead of pushing the actions past the viewport; min-h-11 keeps a 44 px
          // hit area on phones only (desktop bar height unchanged).
          className="flex min-w-0 items-center gap-2 rounded-vp-sm no-underline min-h-11 sm:min-h-0"
          // The bar's own text colour (computed for the bar, see topbarBrandVars), not the button ink.
          style={{ color: 'var(--vpds-c-topbar-foreground)' }}
          aria-label={logoAriaLabel}
          data-testid="topbar-brand-initials"
        >
          <Avatar name={brandInitialsName} size="small" color={brandAccent ?? null} />
          <span
            className="min-w-0 text-vp-sm font-semibold max-w-vp-logo truncate"
            title={brandInitialsName}
          >
            {brandInitialsName}
          </span>
        </a>
      ) : (
        <VotepitLogo
          href={logoHref}
          ariaLabel={logoAriaLabel}
          edition={edition}
          demo={demo}
          onInk
        />
      )}
      {scope && (
        <div className="ds-topbar__scope flex min-w-0 items-center gap-2 text-vp-sm text-vp-on-ink-muted">
          <span aria-hidden="true" className="h-4 w-px bg-vp-rule-on-ink" />
          <span className="truncate min-w-0" title={typeof scope === 'string' ? scope : undefined}>
            {scope}
          </span>
        </div>
      )}
    </>
  )

  const nav = hasNav ? (
    <>
      {links.map((link) => {
        const current = currentHref !== undefined && currentHref === link.href
        // External links leave the app, so they are plain anchors (a router Link would
        // treat an absolute URL as a route) and open in a new tab.
        const Link = link.external ? 'a' : NavLink
        return (
          <Link
            key={link.href}
            href={link.href}
            rel={link.rel}
            target={link.external ? '_blank' : undefined}
            aria-current={current ? 'page' : undefined}
            className="ds-topbar__link"
          >
            {link.icon && (
              <span aria-hidden="true" className="inline-flex">
                {link.icon}
              </span>
            )}
            {link.label}
            {link.external && link.externalHint && (
              <span className="sr-only"> ({link.externalHint})</span>
            )}
          </Link>
        )
      })}
    </>
  ) : undefined

  const actions = (
    <>
      {navExtra}
      {account === undefined ? (
        <Button variant="secondary" size="sm" onClick={onLoginClick}>
          {loginLabel}
        </Button>
      ) : (
        account
      )}
    </>
  )

  return (
    <TopBar
      testId="topbar"
      brand={brand}
      nav={nav}
      actions={actions}
      navLabel={navAriaLabel}
      className={tabbar ? 'ds-topbar--tabbar' : undefined}
    />
  )
}
