import type { ReactNode } from 'react'
import { useId } from 'react'
import { cx } from '../lib/cx'

export interface CheckboxProps {
  label: string
  hint?: string
  checked: boolean
  onChange: (checked: boolean) => void
  name?: string
  id?: string
  disabled?: boolean
  className?: string
  /** Hides the visible label; the name stays on aria-label (rows with their own context). */
  hideLabel?: boolean
  /** Trailing element in the label row, e.g. an upgrade link. */
  labelEnd?: ReactNode
  /** Stable data-testid on the input. */
  testId: string
}

/** Native checkbox with a visible label and an optional hint under it. */
export function Checkbox({
  label,
  hint,
  checked,
  onChange,
  name,
  id,
  disabled,
  className,
  hideLabel = false,
  labelEnd,
  testId,
}: CheckboxProps) {
  const autoId = useId()
  const inputId = id ?? `${autoId}-checkbox`
  const hintId = hint ? `${inputId}-hint` : undefined
  return (
    <div className={cx('ds-checkbox', className)}>
      <input
        id={inputId}
        name={name}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
        aria-label={hideLabel ? label : undefined}
        aria-describedby={hintId}
        data-testid={testId}
        className="ds-checkbox__input ds-focusable"
      />
      <div className="ds-checkbox__text">
        {!hideLabel && (
          <label className="ds-checkbox__label" htmlFor={inputId}>
            {label}
          </label>
        )}
        {labelEnd && <span className="ds-checkbox__end">{labelEnd}</span>}
        {hint && (
          <p id={hintId} className="ds-checkbox__hint">
            {hint}
          </p>
        )}
      </div>
    </div>
  )
}
