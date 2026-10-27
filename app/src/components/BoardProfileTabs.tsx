/**
 * BoardProfileTabs — the board's own navigation under its profile header (G-12):
 * "Ideen" and "Roadmap". Two links, not a tablist: each one navigates to its page,
 * the current one carries aria-current="page". Shown on desktop only; phones keep
 * Board / Roadmap in the bottom tab bar, so the same targets never appear twice.
 * Hidden when the roadmap is not offered to the viewer (a single tab is no nav).
 */

import { Link } from 'react-router-dom'
import { useT } from '../lib/i18n/context'

export interface BoardProfileTabsProps {
  /** Board base path (account-prefixed), e.g. `/acme/feedback`. */
  basePath: string
  current: 'ideas' | 'roadmap'
  /** Whether the viewer is offered the roadmap (see boardRoadmapVisible). */
  showRoadmap: boolean
}

const TAB_CLASS =
  'inline-flex min-h-11 items-center border-b-2 px-1 text-vp-sm font-semibold transition-colors'

export function BoardProfileTabs({ basePath, current, showRoadmap }: BoardProfileTabsProps) {
  const tBoard = useT('boardPage')
  const tCommon = useT('common')
  if (!showRoadmap) return null

  const items = [
    {
      key: 'ideas' as const,
      label: tBoard('ideasAriaLabel'),
      href: basePath || '/',
      testId: 'board-profile-tab-ideas',
    },
    {
      key: 'roadmap' as const,
      label: tCommon('header.roadmap'),
      href: `${basePath}/roadmap`,
      testId: 'board-profile-tab-roadmap',
    },
  ]

  return (
    <nav
      aria-label={tCommon('header.navAriaLabel')}
      data-testid="board-profile-tabs"
      className="mt-6 flex gap-6 border-b border-vp-border"
    >
      {items.map((item) => {
        const active = item.key === current
        return (
          <Link
            key={item.key}
            to={item.href}
            aria-current={active ? 'page' : undefined}
            data-testid={item.testId}
            className={`${TAB_CLASS} ${
              active
                ? 'border-vp-ink text-vp-ink'
                : 'border-transparent text-vp-text-secondary hover:text-vp-ink'
            }`}
          >
            {item.label}
          </Link>
        )
      })}
    </nav>
  )
}
