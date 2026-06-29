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

interface HeaderProps {
  logoHref?: string
  boardName?: string
  loginLabel?: string
  onLoginClick?: () => void
  /** When true, shows an "Abmelden" button instead of the login CTA. */
  isAuthenticated?: boolean
  /** Called when the user clicks the logout button (only shown when isAuthenticated). */
  onLogoutClick?: () => void
  /**
   * Board-Kontext-Pfad für nav-interne Links (ADR-11).
   * CE: "/{board}" (z. B. "/demo"), Cloud: "/{account}/{board}".
   * Default: "" (kein Board-Kontext, z. B. Login-Seite oder Admin).
   */
  basePath?: string
}

function VotepitLogo({ href = '/' }: { href?: string }) {
  return (
    <a
      href={href}
      className="flex items-center gap-2 no-underline"
      aria-label="Votepit – Startseite"
    >
      {/* Hex icon scaled to ~32×36px */}
      <svg viewBox="-185 -205 370 410" width="32" height="36" fill="none" aria-hidden="true">
        <path d={TOP} fill="var(--color-vp-vote-up)" />
        <path d={BOT} fill="var(--color-vp-vote-down)" />
        <path d={MID} fill="#084C37" />
        <path d={DARK} fill="#05241A" />
      </svg>

      {/* Logotype */}
      <span
        className="font-archivo font-extrabold text-[28px] leading-none tracking-[-0.7px] select-none"
        style={{ letterSpacing: '-0.025em' }}
      >
        <span className="text-vp-ink">Vote</span>
        <span className="text-vp-vote-down">pit</span>
      </span>
    </a>
  )
}

export function Header({
  logoHref = '/',
  loginLabel = 'Anmelden',
  onLoginClick,
  isAuthenticated = false,
  onLogoutClick,
  basePath = '',
}: HeaderProps) {
  return (
    <header className="sticky top-0 z-50 w-full bg-white/25 backdrop-blur-2xl backdrop-saturate-150 border-b border-vp-border-frost">
      <div className="vp-container flex items-center justify-between h-[71px]">
        {/* Left: logo */}
        <VotepitLogo href={logoHref} />

        {/* Right: nav + CTA */}
        <nav className="flex items-center gap-6" aria-label="Hauptnavigation">
          <a
            href={basePath || '/'}
            className="text-[14px] text-vp-text-secondary hover:text-vp-ink transition-colors"
          >
            Board
          </a>
          <a
            href={`${basePath}/roadmap`}
            className="text-[14px] text-vp-text-secondary hover:text-vp-ink transition-colors"
          >
            Roadmap
          </a>
          {isAuthenticated && onLogoutClick ? (
            <Button variant="secondary" onClick={onLogoutClick}>
              Abmelden
            </Button>
          ) : (
            <Button variant="primary" onClick={onLoginClick}>
              {loginLabel}
            </Button>
          )}
        </nav>
      </div>
    </header>
  )
}
