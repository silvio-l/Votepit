import type { ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react'
import { cx } from '../lib/cx'

export interface TableProps {
  children: ReactNode
  /** Accessible name of the table (rendered visually hidden). */
  caption?: string
  /** Tighter rows for dense lists. */
  density?: 'default' | 'compact'
  className?: string
  /** Stable data-testid on the scroll wrapper (kebab-case, `<screen>-<element>`). */
  testId: string
}

/**
 * Data table on the paper: hairline rules between rows, a sunken head row,
 * tabular numerals for numeric columns. Scrolls horizontally inside its own
 * wrapper on narrow screens so the page never does.
 */
export function Table({ children, caption, density = 'default', className, testId }: TableProps) {
  return (
    <div className={cx('ds-table-wrap', className)} data-testid={testId}>
      <table className="ds-table" data-density={density}>
        {caption && <caption className="ds-visually-hidden">{caption}</caption>}
        {children}
      </table>
    </div>
  )
}

export function TableHead({ children }: { children: ReactNode }) {
  return <thead className="ds-table__head">{children}</thead>
}

export function TableBody({ children }: { children: ReactNode }) {
  return <tbody className="ds-table__body">{children}</tbody>
}

export interface TableRowProps {
  children: ReactNode
  className?: string
  /** Highlights the row (selected / current). */
  selected?: boolean
  /** Stable data-testid on the row when it needs to be addressed. */
  testId?: string
  /** The whole row is a click target (pointer cursor). Keep a link in a cell for keyboard users. */
  onClick?: () => void
}

export function TableRow({ children, className, selected, testId, onClick }: TableRowProps) {
  return (
    <tr
      className={cx('ds-table__row', onClick && 'cursor-pointer', className)}
      aria-selected={selected || undefined}
      data-testid={testId}
      onClick={onClick}
    >
      {children}
    </tr>
  )
}

export interface TableCellProps {
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
}: TableCellProps & ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope="col"
      className={cx('ds-table__th', numeric && 'ds-table__cell--numeric', className)}
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
}: TableCellProps & TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td className={cx('ds-table__td', numeric && 'ds-table__cell--numeric', className)} {...props}>
      {children}
    </td>
  )
}
