import type { ReactNode, SelectHTMLAttributes } from 'react'
import { cx } from '../lib/cx'
import { controlClassName, describedBy, FieldShell, slugId } from './FieldShell'

interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange' | 'size'> {
  label: string
  hint?: string
  error?: string
  value: string
  onChange: (value: string) => void
  name?: string
  id?: string
  required?: boolean
  disabled?: boolean
  className?: string
  /** <option> elements. */
  children: ReactNode
  /** Hide the visible label (keeps it for AT) — for selects inside a toolbar. */
  hideLabel?: boolean
  size?: 'sm' | 'md'
  /** Leading glyph rendered inside the field. Decorative only. */
  icon?: ReactNode
  /** Rendered at the label's trailing edge, e.g. a plan-upgrade link. */
  labelEnd?: ReactNode
}

function Chevron() {
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
      className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-vp-text-muted"
    >
      <path d="M4 6l4 4 4-4" />
    </svg>
  )
}

/** Native select in the app's control frame — the OS picker, never a custom menu. */
export function Select({
  label,
  hint,
  error,
  value,
  onChange,
  name,
  id,
  required,
  disabled,
  className = '',
  children,
  hideLabel = false,
  size = 'md',
  icon,
  labelEnd,
  ...props
}: SelectProps) {
  const selectId = id ?? name ?? slugId('select', label)
  const hasError = Boolean(error)

  const control = (
    <div className="relative">
      {icon && (
        <span
          aria-hidden="true"
          className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-vp-text-muted inline-flex"
        >
          {icon}
        </span>
      )}
      <select
        id={selectId}
        name={name}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        required={required}
        disabled={disabled}
        aria-invalid={hasError || undefined}
        aria-describedby={describedBy(selectId, hint, error)}
        aria-label={hideLabel ? label : undefined}
        className={controlClassName(
          hasError,
          cx(
            'appearance-none pr-8 cursor-pointer',
            Boolean(icon) && 'pl-8',
            size === 'sm' ? 'h-8 text-vp-sm' : 'h-10',
          ),
        )}
        {...props}
      >
        {children}
      </select>
      <Chevron />
    </div>
  )

  if (hideLabel) return <div className={className}>{control}</div>

  return (
    <FieldShell
      id={selectId}
      label={label}
      labelEnd={labelEnd}
      hint={hint}
      error={error}
      required={required}
      className={className}
    >
      {control}
    </FieldShell>
  )
}
