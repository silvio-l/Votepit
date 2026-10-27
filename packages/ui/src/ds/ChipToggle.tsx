import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

export interface ChipToggleProps {
  /** Current selection state, exposed as aria-pressed. */
  pressed: boolean
  onToggle: () => void
  disabled?: boolean
  /** Optional accent colour (for example a tag colour), applied as text colour. */
  color?: string
  className?: string
  /** Stable data-testid (kebab-case, unique on the page). */
  testId: string
  children: ReactNode
}

/**
 * Selectable chip (toggle button). Native button, so Enter and Space work
 * without extra handlers. 44 px tall on phones, compact from 768 px.
 */
export function ChipToggle({
  pressed,
  onToggle,
  disabled = false,
  color,
  className,
  testId,
  children,
}: ChipToggleProps) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      disabled={disabled}
      data-testid={testId}
      className={cx('ds-chip-toggle ds-focusable', className)}
      style={color !== undefined ? { color } : undefined}
      onClick={onToggle}
    >
      {children}
    </button>
  )
}
