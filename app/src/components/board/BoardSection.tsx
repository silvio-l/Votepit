import { cx } from '@votepit/ui/ds'
import { ChevronDown } from 'lucide-react'
import type { ReactNode } from 'react'
import { remainingIn } from '../../lib/boardGroups'

export interface BoardSectionProps {
  id: string
  /** Section heading; omitted for a flat list (status filter or custom status). */
  title?: string
  total: number
  loaded: number
  collapsed: boolean
  onToggle: () => void
  loadingMore: boolean
  onLoadMore: () => void
  loadMoreLabel: (remaining: number) => string
  loadingLabel: string
  children: ReactNode
}

/**
 * One status group of the board list: a header button that folds the rows
 * (aria-expanded / aria-controls), the rows, and a per-group "Load more".
 */
export function BoardSection({
  id,
  title,
  total,
  loaded,
  collapsed,
  onToggle,
  loadingMore,
  onLoadMore,
  loadMoreLabel,
  loadingLabel,
  children,
}: BoardSectionProps) {
  const remaining = remainingIn(total, loaded)
  const bodyId = `${id}-body`

  return (
    <section data-testid={`board-section-${id}`} className="flex flex-col">
      {title !== undefined && (
        <h2 className="m-0">
          <button
            type="button"
            className="flex w-full items-center justify-between gap-3 border-b border-vp-rule px-3 py-3 text-left sm:px-4"
            aria-expanded={!collapsed}
            aria-controls={bodyId}
            onClick={onToggle}
            data-testid={`board-section-${id}-toggle`}
          >
            <span className="font-archivo font-bold text-vp-base text-vp-ink">{title}</span>
            <span className="flex items-center gap-2 text-vp-sm text-vp-text-muted tabular-nums">
              {total}
              <ChevronDown
                size={16}
                aria-hidden="true"
                className={cx('transition-transform', collapsed && '-rotate-90')}
              />
            </span>
          </button>
        </h2>
      )}
      {!collapsed && (
        <div id={bodyId} className="flex flex-col gap-3 pb-3">
          {children}
          {remaining > 0 && (
            <button
              type="button"
              className="min-h-11 self-center rounded-vp-full border border-vp-rule px-4 py-2 text-vp-sm text-vp-ink disabled:opacity-60"
              onClick={onLoadMore}
              disabled={loadingMore}
              data-testid={`board-section-${id}-more`}
            >
              {loadingMore ? loadingLabel : loadMoreLabel(remaining)}
            </button>
          )}
        </div>
      )}
    </section>
  )
}
