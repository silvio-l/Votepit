import type { ReactNode } from 'react'
import { cx } from '../lib/cx'

interface FieldShellProps {
  id: string
  label: string
  hint?: string
  error?: string
  required?: boolean
  /** Optional trailing element in the label row (e.g. a "Forgot?" link or a counter). */
  labelEnd?: ReactNode
  className?: string
  children: ReactNode
}

/** Shared label / hint / error frame for every form control. */
export function FieldShell({
  id,
  label,
  hint,
  error,
  required,
  labelEnd,
  className,
  children,
}: FieldShellProps) {
  const hasError = Boolean(error)
  return (
    <div className={cx('flex flex-col gap-1.5', className)}>
      <div className="flex items-baseline justify-between gap-3">
        <label htmlFor={id} className="text-vp-sm font-medium text-vp-ink leading-5">
          {label}
          {required && (
            <span className="text-vp-vote-down-strong ml-0.5" aria-hidden="true">
              *
            </span>
          )}
        </label>
        {labelEnd && <span className="text-vp-xs text-vp-text-muted leading-5">{labelEnd}</span>}
      </div>
      {children}
      {(error ?? hint) && (
        <p
          id={hasError ? `${id}-error` : `${id}-hint`}
          className={cx(
            'flex items-start gap-1.5 text-vp-xs leading-4',
            hasError ? 'text-vp-vote-down-strong animate-vp-fade-in' : 'text-vp-text-muted',
          )}
        >
          {hasError && (
            <svg
              viewBox="0 0 16 16"
              width="12"
              height="12"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
              aria-hidden="true"
              className="mt-0.5 shrink-0"
            >
              <path d="M8 4v5M8 11.5v.5" />
              <circle cx="8" cy="8" r="6.5" />
            </svg>
          )}
          <span>{error ?? hint}</span>
        </p>
      )}
    </div>
  )
}

/** Base classes for text-like controls (input, textarea, select). */
export function controlClassName(hasError: boolean, extra = ''): string {
  return cx(
    'vp-control w-full px-3 text-vp-md text-vp-ink font-inter',
    'placeholder:text-vp-text-tertiary',
    hasError && 'border-vp-vote-down',
    extra,
  )
}

export function describedBy(id: string, hint?: string, error?: string): string | undefined {
  if (error) return `${id}-error`
  if (hint) return `${id}-hint`
  return undefined
}

export function slugId(prefix: string, label: string): string {
  return `${prefix}-${label.toLowerCase().replace(/\s+/g, '-')}`
}
