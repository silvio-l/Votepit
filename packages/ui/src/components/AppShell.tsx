import type { ReactNode } from 'react'
import { createContext, useContext, useEffect, useId, useMemo, useRef, useState } from 'react'
import { cx } from '../lib/cx'
import { buttonClassName } from './Button'
import { LegalFooter, type PageShellLegalLink } from './PageShell'

/**
 * Lets anything rendered inside the mobile drawer close it (SidebarNav does
 * so on every link click) without the shell having to know its content.
 */
const SidebarDrawerContext = createContext<{ close: () => void } | null>(null)

export interface SidebarNavItem {
  label: string
  href: string
  /** 16px glyph. */
  icon?: ReactNode
  current?: boolean
  /** Small trailing marker: a count, a live dot, a "new" badge. */
  badge?: ReactNode
}

export interface SidebarNavGroup {
  /** Small-caps group label; omit for the primary (unlabelled) group. */
  label?: string
  items: SidebarNavItem[]
}

interface SidebarNavProps {
  groups: SidebarNavGroup[]
  ariaLabel: string
  /** Called when an item is chosen (used to close the mobile drawer). */
  onNavigate?: () => void
}

/** The sidebar's navigation list: grouped, icon + label, current item marked with an ink rule. */
export function SidebarNav({ groups, ariaLabel, onNavigate }: SidebarNavProps) {
  const drawer = useContext(SidebarDrawerContext)
  const handleNavigate = () => {
    drawer?.close()
    onNavigate?.()
  }
  return (
    <nav aria-label={ariaLabel} className="flex flex-col gap-5">
      {groups.map((group, gi) => (
        <div key={group.label ?? `group-${gi}`} className="flex flex-col gap-0.5">
          {group.label && <div className="vp-eyebrow px-2.5 mb-1.5">{group.label}</div>}
          {group.items.map((item) => (
            <a
              key={item.href}
              href={item.href}
              aria-current={item.current ? 'page' : undefined}
              onClick={handleNavigate}
              className="vp-nav-item"
            >
              {item.icon && <span className="vp-nav-icon">{item.icon}</span>}
              <span className="truncate flex-1">{item.label}</span>
              {item.badge && <span className="shrink-0 inline-flex items-center">{item.badge}</span>}
            </a>
          ))}
        </div>
      ))}
    </nav>
  )
}

interface AppShellProps {
  /** Sticky top bar (the app header). */
  header: ReactNode
  /**
   * Sidebar content — typically a `SidebarNav` plus a foot block. Rendered
   * as a fixed column on ≥ lg screens and as a slide-in drawer below.
   */
  sidebar: ReactNode
  /** Optional block pinned to the sidebar foot (plan, help link, version). */
  sidebarFooter?: ReactNode
  /** Accessible name of the sidebar landmark. */
  sidebarAriaLabel: string
  /** Labels for the mobile drawer toggle. */
  openSidebarLabel: string
  closeSidebarLabel: string
  legalLinks?: readonly PageShellLegalLink[]
  /**
   * Content width — the same three presets as PageShell, but never centred:
   * with a permanent sidebar already occupying the left edge, centring a
   * capped column leaves a dead gap between sidebar and content that a
   * left-aligned column (GitHub/Search Console-style) doesn't. "default" is
   * fluid (lists, tables, dashboards use the whole column next to the
   * sidebar), "narrow" caps at 46rem for single-column forms, "wide" caps at
   * 1440px for settings forms whose grids would sprawl on a fluid canvas.
   */
  width?: 'default' | 'narrow' | 'wide'
  children: ReactNode
}

function MenuGlyph() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="18"
      height="18"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <path d="M2.5 4.5h11M2.5 8h11M2.5 11.5h11" />
    </svg>
  )
}

function CloseGlyph() {
  return (
    <svg
      viewBox="0 0 16 16"
      width="16"
      height="16"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <path d="M4 4l8 8M12 4l-8 8" />
    </svg>
  )
}

/**
 * The operate-mode frame: a sticky top bar, a 248px sidebar on the left
 * (drawer below `lg`), and the content column. The sidebar is where the
 * account's sections live; the top bar carries scope, notifications and the
 * session control. Mobile: a menu button in the content head opens the
 * drawer; Escape, backdrop and any nav click close it, focus returns to the
 * button.
 */
export function AppShell({
  header,
  sidebar,
  sidebarFooter,
  sidebarAriaLabel,
  openSidebarLabel,
  closeSidebarLabel,
  legalLinks = [],
  width = 'default',
  children,
}: AppShellProps) {
  const [open, setOpen] = useState(false)
  const toggleRef = useRef<HTMLButtonElement>(null)
  const drawerId = useId()
  const drawerContext = useMemo(() => ({ close: () => setOpen(false) }), [])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false)
        toggleRef.current?.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    const prevOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = prevOverflow
    }
  }, [open])

  const sidebarInner = (
    <div className="flex h-full flex-col">
      <div className="flex-1 overflow-y-auto px-3 py-4">{sidebar}</div>
      {sidebarFooter && (
        <div className="border-t border-vp-rule px-3 py-3">{sidebarFooter}</div>
      )}
    </div>
  )

  return (
    <div className="min-h-screen flex flex-col vp-desk text-vp-ink">
      {header}
      <div className="flex flex-1 min-h-0">
        {/* Desktop sidebar */}
        <aside
          aria-label={sidebarAriaLabel}
          className="hidden lg:block sticky top-vp-topbar h-[calc(100vh-var(--spacing-vp-topbar))] w-vp-sidebar shrink-0 border-r border-vp-rule bg-vp-bg"
        >
          {sidebarInner}
        </aside>

        {/* Mobile drawer */}
        {open && (
          <div className="lg:hidden fixed inset-0 z-50">
            <button
              type="button"
              aria-label={closeSidebarLabel}
              onClick={() => setOpen(false)}
              className="absolute inset-0 bg-vp-ink/40 animate-vp-fade-in cursor-default"
            />
            <aside
              id={drawerId}
              aria-label={sidebarAriaLabel}
              className="absolute inset-y-0 left-0 w-[min(18rem,85vw)] bg-vp-bg border-r-2 border-vp-ink animate-vp-slide-in-left flex flex-col"
            >
              <div className="flex items-center justify-end px-3 h-vp-topbar border-b border-vp-rule">
                <button
                  type="button"
                  onClick={() => {
                    setOpen(false)
                    toggleRef.current?.focus()
                  }}
                  aria-label={closeSidebarLabel}
                  className="inline-flex size-9 items-center justify-center rounded-vp-md text-vp-text-secondary hover:bg-vp-ink-soft hover:text-vp-ink cursor-pointer"
                >
                  <CloseGlyph />
                </button>
              </div>
              <SidebarDrawerContext.Provider value={drawerContext}>
                <div className="flex-1 min-h-0">{sidebarInner}</div>
              </SidebarDrawerContext.Provider>
            </aside>
          </div>
        )}

        <div className="flex-1 min-w-0 flex flex-col">
          <main
            id="main"
            className={cx(
              'flex-1 w-full px-4 sm:px-6 lg:px-8 py-5 md:py-8',
              width === 'narrow' && 'max-w-[46rem]',
              width === 'wide' && 'max-w-[1440px]',
            )}
          >
            <div className="lg:hidden mb-4">
              <button
                ref={toggleRef}
                type="button"
                aria-expanded={open}
                aria-controls={open ? drawerId : undefined}
                onClick={() => setOpen(true)}
                className={buttonClassName('secondary', 'sm')}
              >
                <MenuGlyph />
                {openSidebarLabel}
              </button>
            </div>
            {children}
          </main>
          {legalLinks.length > 0 && (
            <div className="px-4 sm:px-6 lg:px-8 border-t border-vp-rule">
              <LegalFooter legalLinks={legalLinks} />
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
