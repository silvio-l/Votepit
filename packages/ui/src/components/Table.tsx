import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react'
import { cx } from '../lib/cx'

interface TableProps {
  children: ReactNode
  /** Accessible name of the table (rendered visually hidden). */
  caption?: string
  /** Tighter rows for dense operator lists. */
  density?: 'default' | 'compact'
  className?: string
}

/**
 * Result-sheet table: hairline rules between rows, a sunken head row that
 * stays put while the body scrolls, tabular numerals on the right, hover
 * tint on rows. Scrolls horizontally inside its own container on narrow
 * screens so the page never does.
 */
export function Table({ children, caption, density = 'default', className }: TableProps) {
  return (
    <div className={cx('w-full overflow-x-auto', className)}>
      <table
        data-density={density}
        className="group/table w-full border-collapse text-vp-base text-vp-ink"
      >
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  )
}

export function TableHead({ children }: { children: ReactNode }) {
  return <thead className="bg-vp-surface-frost sticky top-0 z-[1]">{children}</thead>
}

export function TableBody({ children }: { children: ReactNode }) {
  return <tbody className="divide-y divide-vp-rule">{children}</tbody>
}

interface TableRowProps {
  children: ReactNode
  className?: string
  /** Highlights the row (selected / current). */
  selected?: boolean
  /** Adds a pointer cursor and stronger hover — the row is clickable as a whole. */
  interactive?: boolean
  onClick?: () => void
}

export function TableRow({ children, className, selected, interactive, onClick }: TableRowProps) {
  return (
    <tr
      aria-selected={selected || undefined}
      onClick={onClick}
      className={cx(
        'align-middle transition-colors duration-150',
        'hover:bg-vp-surface-frost',
        selected && 'bg-vp-accent-soft/60',
        interactive && 'cursor-pointer',
        className,
      )}
    >
      {children}
    </tr>
  )
}

interface CellProps {
  /** Right-aligned tabular numerals. */
  numeric?: boolean
  className?: string
  children?: ReactNode
}

export function TableHeaderCell({
  numeric,
  className,
  children,
  ...props
}: CellProps & ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cx(
        'px-4 sm:px-5 py-2.5 text-vp-2xs font-semibold uppercase tracking-[0.08em] text-vp-text-muted whitespace-nowrap border-b border-vp-rule',
        numeric ? 'text-right' : 'text-left',
        className,
      )}
      {...props}
    >
      {children}
    </th>
  )
}

export function TableCell({
  numeric,
  className,
  children,
  ...props
}: CellProps & TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cx(
        'px-4 sm:px-5 py-3 group-data-[density=compact]/table:py-2',
        numeric ? 'text-right font-mono-num whitespace-nowrap' : 'text-left',
        className,
      )}
      {...props}
    >
      {children}
    </td>
  )
}
