import type { InputHTMLAttributes } from 'react'
import { cx } from '../lib/cx'
import { slugId } from './FieldShell'

interface CheckboxProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'type' | 'checked'> {
  label: string
  /** Secondary line under the label. */
  hint?: string
  checked: boolean
  onChange: (checked: boolean) => void
  name?: string
  id?: string
  disabled?: boolean
  className?: string
}

/**
 * A ballot-box checkbox: 18px square, 1.5px rule, ink fill with a stamped
 * check when marked. Native input underneath — keyboard, forms and AT all
 * work for free.
 */
export function Checkbox({
  label,
  hint,
  checked,
  onChange,
  name,
  id,
  disabled,
  className = '',
  ...props
}: CheckboxProps) {
  const inputId = id ?? name ?? slugId('checkbox', label)
  const hintId = hint ? `${inputId}-hint` : undefined

  return (
    <div className={cx('flex items-start gap-2.5', className)}>
      <span className="relative flex size-5 shrink-0 items-center justify-center mt-0.5">
        <input
          id={inputId}
          name={name}
          type="checkbox"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
          disabled={disabled}
          aria-describedby={hintId}
          className={cx(
            'peer appearance-none size-[18px] rounded-vp-sm border-[1.5px] bg-vp-surface cursor-pointer',
            'border-vp-border-strong hover:border-vp-ink shadow-[0_1px_1px_rgba(21,22,26,0.04)]',
            'checked:bg-vp-ink checked:border-vp-ink',
            'focus-visible:outline-none focus-visible:shadow-vp-focus focus-visible:border-vp-accent-strong',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'vp-press',
          )}
          {...props}
        />
        <svg
          viewBox="0 0 16 16"
          width="12"
          height="12"
          fill="none"
          stroke="var(--color-vp-on-ink)"
          strokeWidth="2.25"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          className="pointer-events-none absolute opacity-0 peer-checked:opacity-100 peer-checked:animate-vp-stamp"
        >
          <path d="M3.5 8.5l3 3 6-7" />
        </svg>
      </span>
      <span className="flex flex-col">
        <label
          htmlFor={inputId}
          className={cx(
            'text-vp-base text-vp-ink leading-5 cursor-pointer select-none',
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
    </div>
  )
}
