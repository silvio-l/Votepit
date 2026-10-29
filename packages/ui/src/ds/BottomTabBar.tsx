import type { CSSProperties, ElementType, MouseEvent, ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface BottomTabItem {
  /** Stable key; also part of the item's data-testid (`<testId>-<id>`). */
  id: string
  /** Visible label under the icon (also the accessible name). */
  label: string
  href: string
  /** 22px glyph, rendered aria-hidden. */
  icon: ReactNode
  /** Unread indicator: a dot, with `badgeLabel` as screen-reader text. */
  dot?: boolean
  badgeLabel?: string
  /** The central action (New idea): coral, lifted above the row. */
  primary?: boolean
  /**
   * Runs instead of following the link (the href stays for the no-JS case and
   * for the current-page check). Used to open a sheet on the same screen.
   */
  onSelect?: () => void
}

export interface BottomTabBarProps {
  items: BottomTabItem[]
  /** href of the current page — marks the matching item with aria-current. */
  currentHref?: string
  /** Router link adapter (e.g. react-router's Link taking `href`). Defaults to `a`. */
  linkAs?: ElementType
  /** Accessible name of the navigation landmark. */
  navLabel: string
  /** Stable data-testid on the nav element. */
  testId: string
}

/**
 * Mobile app navigation pinned to the bottom edge (thumb zone). Phone-only:
 * the stylesheet hides it from 640 px up, where the top bar carries the
 * same links. Respects the iOS home-indicator inset; every item is a
 * 48 px-high target. Markup and classes live in ds/mobile.css.
 */
export function BottomTabBar({ items, currentHref, linkAs, navLabel, testId }: BottomTabBarProps) {
  const Link = (linkAs ?? 'a') as ElementType
  return (
    <nav className="ds-tabbar" aria-label={navLabel} data-testid={testId}>
      <ul
        className="ds-tabbar__list"
        style={{ '--ds-tabbar-count': items.length } as CSSProperties}
      >
        {items.map((item) => {
          const current = currentHref !== undefined && currentHref === item.href
          return (
            <li key={item.id} className="ds-tabbar__item">
              <Link
                href={item.href}
                aria-current={current ? 'page' : undefined}
                data-testid={`${testId}-${item.id}`}
                onClick={
                  item.onSelect
                    ? (event: MouseEvent) => {
                        event.preventDefault()
                        item.onSelect?.()
                      }
                    : undefined
                }
                className={cx('ds-tabbar__link', item.primary && 'ds-tabbar__link--primary')}
              >
                <span className="ds-tabbar__icon" aria-hidden="true">
                  {item.icon}
                  {item.dot && <span className="ds-tabbar__dot" />}
                </span>
                <span className="ds-tabbar__label">{item.label}</span>
                {item.dot && item.badgeLabel && (
                  <span className="ds-tabbar__sr">{item.badgeLabel}</span>
                )}
              </Link>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}
