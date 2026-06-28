import type { TextareaHTMLAttributes } from 'react'

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
}

export function Textarea({
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
  ...props
}: TextareaProps) {
  const inputId = id ?? name ?? `textarea-${label.toLowerCase().replace(/\s+/g, '-')}`
  const hasError = Boolean(error)

  return (
    <div className={['flex flex-col gap-1', className].join(' ')}>
      <label
        htmlFor={inputId}
        className="text-[13px] font-medium text-vp-text-secondary font-inter"
      >
        {label}
        {required && (
          <span className="text-vp-vote-down ml-0.5" aria-hidden="true">
            *
          </span>
        )}
      </label>

      <textarea
        id={inputId}
        name={name}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        rows={rows}
        className={[
          'bg-vp-surface border rounded-vp-md p-3',
          'text-[15px] leading-[1.48] text-vp-ink font-inter',
          'placeholder:text-vp-text-muted',
          'w-full min-h-[96px] resize-y',
          'focus:outline-2 focus:outline-vp-ink focus:outline-offset-2',
          'disabled:opacity-50 disabled:cursor-not-allowed',
          'transition-colors duration-150',
          hasError ? 'border-vp-vote-down' : 'border-vp-border-subtle',
        ].join(' ')}
        {...props}
      />

      {(error ?? hint) && (
        <p
          className={[
            'text-[12px] font-inter',
            hasError ? 'text-vp-vote-down' : 'text-vp-text-muted',
          ].join(' ')}
        >
          {error ?? hint}
        </p>
      )}
    </div>
  )
}
