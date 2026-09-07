import type { ReactNode, TextareaHTMLAttributes } from 'react'
import { forwardRef } from 'react'
import { controlClassName, describedBy, FieldShell, slugId } from './FieldShell'

interface TextareaProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange'> {
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
  rows?: number
  /** Optional trailing element in the label row (e.g. a character counter, a formatting toolbar). */
  labelEnd?: ReactNode
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  {
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
    rows,
    labelEnd,
    ...props
  },
  ref,
) {
  const inputId = id ?? name ?? slugId('textarea', label)
  const hasError = Boolean(error)

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
      <textarea
        ref={ref}
        id={inputId}
        name={name}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        rows={rows}
        aria-invalid={hasError || undefined}
        aria-describedby={describedBy(inputId, hint, error)}
        className={controlClassName(hasError, 'py-2.5 min-h-24 resize-y leading-6')}
        {...props}
      />
    </FieldShell>
  )
})
