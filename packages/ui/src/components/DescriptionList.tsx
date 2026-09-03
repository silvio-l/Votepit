import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface DescriptionItem {
  term: ReactNode
  detail: ReactNode
  /** Monospace detail (ids, slugs, dates). */
  mono?: boolean
}

interface DescriptionListProps {
  items: DescriptionItem[]
  /** "rows": term left, detail right, one hairline per row (settings summaries). "grid": term above detail in tiles. */
  layout?: 'rows' | 'grid'
  className?: string
}

/** Key/value pairs in one of two layouts — the read-only half of a settings sheet. */
export function DescriptionList({ items, layout = 'rows', className }: DescriptionListProps) {
  if (layout === 'grid') {
    return (
      <dl className={cx('grid gap-4 sm:grid-cols-2 lg:grid-cols-3', className)}>
        {items.map((item, i) => (
          // biome-ignore lint/suspicious/noArrayIndexKey: static, ordered display list.
          <div key={i} className="flex flex-col gap-1 min-w-0">
            <dt className="vp-eyebrow">{item.term}</dt>
            <dd className={cx('text-vp-base text-vp-ink break-words', item.mono && 'font-mono-num text-vp-sm')}>
              {item.detail}
            </dd>
          </div>
        ))}
      </dl>
    )
  }
  return (
    <dl className={cx('divide-y divide-vp-border-subtle', className)}>
      {items.map((item, i) => (
        // biome-ignore lint/suspicious/noArrayIndexKey: static, ordered display list.
        <div key={i} className="flex flex-col sm:flex-row sm:items-baseline gap-x-6 gap-y-1 py-3 first:pt-0 last:pb-0">
          <dt className="sm:w-44 shrink-0 text-vp-sm font-medium text-vp-text-secondary">{item.term}</dt>
          <dd className={cx('flex-1 min-w-0 text-vp-base text-vp-ink break-words', item.mono && 'font-mono-num text-vp-sm')}>
            {item.detail}
          </dd>
        </div>
      ))}
    </dl>
  )
}
