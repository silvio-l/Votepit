import { cx } from '../lib/cx'
import type { StatusTone } from './StatusChip'

export interface StatusBubbleItem {
  key: string
  label: string
  /** Real idea count from the API, or null when the board does not report one. */
  count: number | null
  tone: StatusTone
  pressed: boolean
  onSelect: () => void
  /** Stable data-testid (kebab-case, unique on the page). */
  testId: string
}

export interface StatusBubblesProps {
  items: StatusBubbleItem[]
  /** Accessible name of the group, e.g. the status filter label. */
  label: string
  className?: string
  testId: string
}

/**
 * Round status counts used as quick filters (G-12). Each bubble is a toggle button
 * (aria-pressed); the row scrolls sideways on phones. Touch targets are 44 px tall.
 */
export function StatusBubbles({ items, label, className, testId }: StatusBubblesProps) {
  return (
    <div
      role="group"
      aria-label={label}
      className={cx('ds-status-bubbles', className)}
      data-testid={testId}
    >
      {items.map((item) => (
        <button
          key={item.key}
          type="button"
          aria-pressed={item.pressed}
          onClick={item.onSelect}
          data-testid={item.testId}
          className={cx(
            'ds-status-bubble ds-focusable',
            `ds-status-bubble--${item.tone}`,
            item.pressed && 'ds-status-bubble--pressed',
          )}
        >
          <span className="ds-status-bubble__dot" aria-hidden="true" />
          <span className="ds-status-bubble__label">{item.label}</span>
          {item.count !== null && (
            <span className="ds-status-bubble__count" data-testid={`${item.testId}-count`}>
              {item.count}
            </span>
          )}
        </button>
      ))}
    </div>
  )
}
