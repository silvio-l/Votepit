import type { InputHTMLAttributes, ReactNode } from 'react'
import { cx } from '../lib/cx'
import { controlClassName, describedBy, FieldShell, slugId } from './FieldShell'

interface TextInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'size'> {
  label: string
  hint?: string
  error?: string
  value: string
  onChange: (value: string) => void
  placeholder?: string
  name?: string
  id?: string
  required?: boolean
  disabled?: boolean
  className?: string
  /** Monospace value (slugs, hex colours, tokens). */
  mono?: boolean
  /** Leading glyph rendered inside the field (e.g. a platform icon). Decorative only. */
  icon?: ReactNode
  /** Trailing element inside the field (a unit, a small action). */
  trailing?: ReactNode
  /** Static prefix rendered inside the field before the value (e.g. a URL base). */
  prefix?: string
  /** Optional trailing element in the label row. */
  labelEnd?: ReactNode
  /** Hide the visible label (keeps it for AT) — for inputs inside a toolbar. */
  hideLabel?: boolean
  size?: 'sm' | 'md' | 'lg'
}

export function TextInput({
  label,
  hint,
  error,
  value,
  onChange,
  placeholder,
  name,
  id,
  required,
  disabled,
  className = '',
  mono = false,
  icon,
  trailing,
  prefix,
  labelEnd,
  hideLabel = false,
  size = 'md',
  ...props
}: TextInputProps) {
  const inputId = id ?? name ?? slugId('input', label)
  const hasError = Boolean(error)
  const heightClass = size === 'sm' ? 'h-8 text-vp-sm' : size === 'lg' ? 'h-11 text-vp-md' : 'h-10'

  const input = (
    <input
      id={inputId}
      name={name}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      required={required}
      disabled={disabled}
      aria-invalid={hasError || undefined}
      aria-describedby={describedBy(inputId, hint, error)}
      aria-label={hideLabel ? label : undefined}
      className={controlClassName(
        hasError,
        cx(
          heightClass,
          Boolean(icon) && 'pl-9',
          Boolean(trailing) && 'pr-10',
          Boolean(prefix) && 'rounded-l-none',
          mono && 'font-mono text-vp-base tracking-tight',
        ),
      )}
      {...props}
    />
  )

  const control =
    icon || trailing || prefix ? (
      <div className="relative flex">
        {prefix && (
          <span className="inline-flex items-center px-3 rounded-l-vp-md border border-r-0 border-vp-border-strong bg-vp-surface-frost text-vp-sm text-vp-text-muted font-mono-num whitespace-nowrap select-none">
            {prefix}
          </span>
        )}
        {icon && (
          <span
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-vp-text-muted inline-flex"
          >
            {icon}
          </span>
        )}
        {input}
        {trailing && (
          <span className="absolute right-2 top-1/2 -translate-y-1/2 inline-flex items-center text-vp-text-muted">
            {trailing}
          </span>
        )}
      </div>
    ) : (
      input
    )

  if (hideLabel) return <div className={className}>{control}</div>

  return (
    <FieldShell
      id={inputId}
      label={label}
      hint={hint}
      error={error}
      required={required}
      labelEnd={labelEnd}
      className={className}
    >
      {control}
    </FieldShell>
  )
}
