import { cx } from '../lib/cx'

interface PaginationProps {
  page: number
  totalPages: number
  onChange: (page: number) => void
  /** i18n overrides — default to English. */
  prevAriaLabel?: string
  nextAriaLabel?: string
  prevLabel?: string
  nextLabel?: string
  pageOfLabel?: (page: number, totalPages: number) => string
  /** aria-label for a page number button ("Page 3"). */
  pageAriaLabel?: (page: number) => string
}

/** Page numbers to show: first, last, and a window around the current page. */
function pageWindow(page: number, total: number): Array<number | 'gap'> {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set<number>([1, total, page - 1, page, page + 1])
  if (page <= 3) {
    pages.add(2)
    pages.add(3)
    pages.add(4)
  }
  if (page >= total - 2) {
    pages.add(total - 1)
    pages.add(total - 2)
    pages.add(total - 3)
  }
  const sorted = [...pages].filter((p) => p >= 1 && p <= total).sort((a, b) => a - b)
  const out: Array<number | 'gap'> = []
  for (let i = 0; i < sorted.length; i++) {
    const p = sorted[i] as number
    const prev = sorted[i - 1]
    if (prev !== undefined && p - prev > 1) out.push('gap')
    out.push(p)
  }
  return out
}

function Arrow({ dir }: { dir: 'left' | 'right' }) {
  return (
    <svg
      viewBox="0 0 16 16"
      width="14"
      height="14"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      {dir === 'left' ? <path d="M10 3L5 8l5 5" /> : <path d="M6 3l5 5-5 5" />}
    </svg>
  )
}

const cell =
  'inline-flex items-center justify-center h-8 min-w-8 px-2 rounded-vp-md text-vp-sm font-medium transition-colors duration-150 cursor-pointer vp-press disabled:opacity-40 disabled:cursor-not-allowed'

/** Numbered pagination with prev/next arrows; the current page is stamped in ink. */
export function Pagination({
  page,
  totalPages,
  onChange,
  prevAriaLabel = 'Previous page',
  nextAriaLabel = 'Next page',
  prevLabel,
  nextLabel,
  pageOfLabel = (p, total) => `Page ${p} of ${total}`,
  pageAriaLabel = (p) => `Page ${p}`,
}: PaginationProps) {
  const hasPrev = page > 1
  const hasNext = page < totalPages
  const items = pageWindow(page, totalPages)

  return (
    <nav
      className="flex items-center justify-between gap-3"
      aria-label={pageOfLabel(page, totalPages)}
    >
      <button
        type="button"
        onClick={() => onChange(page - 1)}
        disabled={!hasPrev}
        aria-label={prevAriaLabel}
        className={cx(cell, 'gap-1 text-vp-text-secondary hover:bg-vp-ink-soft hover:text-vp-ink')}
      >
        <Arrow dir="left" />
        {prevLabel && <span className="hidden sm:inline">{prevLabel}</span>}
      </button>

      <ol className="flex items-center gap-1 m-0 p-0 list-none" aria-hidden={false}>
        {items.map((item, i) =>
          item === 'gap' ? (
            // biome-ignore lint/suspicious/noArrayIndexKey: gaps have no identity of their own.
            <li key={`gap-${i}`} className="px-1 text-vp-text-muted select-none" aria-hidden="true">
              …
            </li>
          ) : (
            <li key={item}>
              <button
                type="button"
                onClick={() => onChange(item)}
                aria-label={pageAriaLabel(item)}
                aria-current={item === page ? 'page' : undefined}
                className={cx(
                  cell,
                  'font-mono-num',
                  item === page
                    ? 'bg-vp-ink text-vp-on-ink'
                    : 'text-vp-text-secondary hover:bg-vp-ink-soft hover:text-vp-ink',
                )}
              >
                {item}
              </button>
            </li>
          ),
        )}
      </ol>

      <button
        type="button"
        onClick={() => onChange(page + 1)}
        disabled={!hasNext}
        aria-label={nextAriaLabel}
        className={cx(cell, 'gap-1 text-vp-text-secondary hover:bg-vp-ink-soft hover:text-vp-ink')}
      >
        {nextLabel && <span className="hidden sm:inline">{nextLabel}</span>}
        <Arrow dir="right" />
      </button>
    </nav>
  )
}
