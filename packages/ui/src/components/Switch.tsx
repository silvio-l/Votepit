import type { ButtonHTMLAttributes } from 'react'
import { cx } from '../lib/cx'
import { slugId } from './FieldShell'

interface SwitchProps
  extends Omit<
    ButtonHTMLAttributes<HTMLButtonElement>,
    'onChange' | 'onClick' | 'type' | 'role' | 'aria-checked'
  > {
  label: string
  /** Secondary line under the label — typically what the current state means. */
  hint?: string
  checked: boolean
  onChange: (checked: boolean) => void
  id?: string
  disabled?: boolean
  className?: string
  /** "row": label left, switch right, full width — for settings lists. */
  layout?: 'inline' | 'row'
}

/**
 * An on/off setting: a 36×20 rectangular track with a sliding square thumb,
 * ink-filled when on — the same ballot-ink language as `Checkbox`, for the one case a checkbox
 * misreads: a preference that takes effect immediately, not a box in a form
 * that is submitted later. Native <button role="switch"> underneath, so
 * keyboard (Space/Enter), focus ring and AT state announcements come for free.
 */
export function Switch({
  label,
  hint,
  checked,
  onChange,
  id,
  disabled,
  className = '',
  layout = 'inline',
  ...props
}: SwitchProps) {
  const inputId = id ?? slugId('switch', label)
  const hintId = hint ? `${inputId}-hint` : undefined
  const row = layout === 'row'

  const control = (
    <button
      id={inputId}
      type="button"
      role="switch"
      aria-checked={checked}
      aria-describedby={hintId}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cx(
        'relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-vp-sm border-[1.5px] p-px',
        'transition-colors duration-150 cursor-pointer',
        'disabled:cursor-not-allowed disabled:opacity-50',
        checked
          ? 'bg-vp-ink border-vp-ink'
          : 'bg-vp-surface border-vp-border-strong hover:border-vp-ink',
      )}
      {...props}
    >
      <span
        aria-hidden="true"
        className={cx(
          'block size-3.5 rounded-vp-xs transition-transform duration-200 ease-vp-expo',
          checked ? 'translate-x-4 bg-vp-on-ink' : 'translate-x-0 bg-vp-border-strong',
        )}
      />
    </button>
  )

  const text = (
    <span className="flex flex-col min-w-0">
      <label
        htmlFor={inputId}
        className={cx(
          'text-vp-base text-vp-ink leading-5 cursor-pointer select-none',
          row && 'font-medium',
          disabled && 'cursor-not-allowed text-vp-text-muted',
        )}
      >
        {label}
      </label>
      {hint && (
        <span id={hintId} className="text-vp-xs text-vp-text-muted leading-4">
          {hint}
        </span>
      )}
    </span>
  )

  if (row) {
    return (
      <div className={cx('flex items-start justify-between gap-4', className)}>
        {text}
        {control}
      </div>
    )
  }

  return (
    <div className={cx('flex items-start gap-3', className)}>
      {control}
      {text}
    </div>
  )
}
