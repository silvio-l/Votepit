import { useId } from 'react'
import { cx } from '../lib/cx'

export interface SwitchProps {
  label: string
  /** Secondary line under the label, usually what the current state means. */
  hint?: string
  checked: boolean
  /** Called with the new value; the switch takes effect immediately (no form submit). */
  onChange: (checked: boolean) => void
  disabled?: boolean
  className?: string
  /** Stable data-testid on the switch button (kebab-case, `<screen>-<element>`). */
  testId: string
}

/**
 * An on/off setting that takes effect immediately. A native button with
 * role="switch", so Space/Enter, focus ring and state announcements come from the browser.
 * The whole control is a 44 px target; the visual track is smaller.
 */
export function Switch({
  label,
  hint,
  checked,
  onChange,
  disabled = false,
  className,
  testId,
}: SwitchProps) {
  const id = useId()
  const hintId = hint ? `${id}-hint` : undefined

  return (
    <div className={cx('ds-switch', className)}>
      <span className="ds-switch__text">
        <label htmlFor={id} className="ds-switch__label">
          {label}
        </label>
        {hint && (
          <span id={hintId} className="ds-switch__hint">
            {hint}
          </span>
        )}
      </span>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        aria-describedby={hintId}
        disabled={disabled}
        data-testid={testId}
        onClick={() => onChange(!checked)}
        className="ds-switch__control ds-focusable"
      >
        <span
          aria-hidden="true"
          className={cx('ds-switch__track', checked && 'ds-switch__track--on')}
        >
          <span className="ds-switch__thumb" />
        </span>
      </button>
    </div>
  )
}
