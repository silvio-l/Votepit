import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { forwardRef } from 'react'
import { cx } from '../lib/cx'

export type ButtonVariant =
  | 'primary'
  | 'accent'
  | 'secondary'
  | 'ghost'
  | 'ghost-danger'
  | 'danger'
  | 'link'
export type ButtonSize = 'xs' | 'sm' | 'md' | 'lg'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  /** Shows a spinner, sets aria-busy and blocks clicks — the label stays readable. */
  loading?: boolean
  /** Leading glyph (16px lucide icon or inline SVG). Decorative — the label carries meaning. */
  icon?: ReactNode
  /** Trailing glyph. */
  iconEnd?: ReactNode
  /** Stretches to the container width. */
  block?: boolean
  children: ReactNode
}

/*
 * The landing's button grammar: flat rectangular planes, no shadow, no inset
 * highlight. The filled variants lift 2px on hover (.vp-lift); the quiet
 * ones only change colour. "secondary" is the landing's paper button with
 * an ink outline; "link" is the landing's underlined text link (.vp-link).
 */
const variantClasses: Record<ButtonVariant, string> = {
  primary: 'bg-vp-ink text-vp-on-ink border border-vp-ink hover:bg-vp-ink-2 hover:border-vp-ink-2 vp-lift',
  accent:
    'bg-vp-accent-strong text-vp-on-ink border border-vp-accent-strong hover:brightness-110 vp-lift',
  secondary: 'bg-vp-surface text-vp-ink border border-vp-ink hover:bg-vp-bg vp-lift',
  ghost:
    'bg-transparent text-vp-text-secondary border border-transparent hover:bg-vp-ink-soft hover:text-vp-ink',
  // The quiet destructive row action (remove, revoke, delete in a table
  // cell): coral text, a coral wash on hover — never a filled danger button
  // inside a list.
  'ghost-danger':
    'bg-transparent text-vp-vote-down-strong border border-transparent hover:bg-vp-vote-down-soft',
  danger:
    'bg-vp-surface text-vp-vote-down-strong border border-vp-vote-down hover:bg-vp-vote-down-soft vp-lift',
  link: 'vp-link bg-transparent border-0 px-0 h-auto',
}

const sizeClasses: Record<ButtonSize, string> = {
  xs: 'h-7 px-2 text-vp-xs gap-1 rounded-vp-sm',
  sm: 'h-8 px-3 text-vp-sm gap-1.5 rounded-vp-md',
  md: 'h-9 px-4 text-vp-base gap-2 rounded-vp-md',
  lg: 'h-11 px-6 text-vp-md gap-2 rounded-vp-lg',
}

/**
 * Class string for a Button-looking element that is not a <button> (e.g. a
 * router <Link>). Keeps link-vs-button semantics honest while sharing one look.
 */
export function buttonClassName(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  className = '',
): string {
  return cx(
    'inline-flex items-center justify-center whitespace-nowrap select-none',
    'font-inter font-semibold vp-press',
    variant !== 'link' && 'no-underline',
    'cursor-pointer',
    'disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100 aria-busy:cursor-progress',
    // A pressed toggle is an ink plane — the same weight the landing gives
    // its current nav link and the filter chips give their active state.
    'aria-pressed:bg-vp-ink aria-pressed:text-vp-on-ink aria-pressed:border-vp-ink',
    variantClasses[variant],
    variant !== 'link' && sizeClasses[size],
    variant === 'link' && 'text-vp-base gap-1',
    className,
  )
}

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'inline-block size-3.5 rounded-vp-full border-2 border-current border-r-transparent animate-spin',
        className,
      )}
    />
  )
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    loading = false,
    icon,
    iconEnd,
    block = false,
    children,
    className = '',
    disabled,
    type = 'button',
    onClick,
    ...props
  },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled}
      aria-busy={loading || undefined}
      onClick={loading ? undefined : onClick}
      className={buttonClassName(variant, size, cx(block && 'w-full', className))}
      {...props}
    >
      {loading ? (
        <Spinner />
      ) : (
        icon && (
          <span aria-hidden="true" className="inline-flex shrink-0 -ml-0.5">
            {icon}
          </span>
        )
      )}
      {children}
      {iconEnd && (
        <span aria-hidden="true" className="inline-flex shrink-0 -mr-0.5">
          {iconEnd}
        </span>
      )}
    </button>
  )
})

interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
  /** Accessible name — icon-only buttons MUST have one. */
  label: string
  variant?: Exclude<ButtonVariant, 'link'>
  size?: ButtonSize
  loading?: boolean
  children: ReactNode
}

const iconSizeClasses: Record<ButtonSize, string> = {
  xs: 'size-7 rounded-vp-sm',
  sm: 'size-8 rounded-vp-md',
  md: 'size-9 rounded-vp-md',
  lg: 'size-11 rounded-vp-lg',
}

/** Square icon-only button — same variants as Button, always labelled. */
export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, variant = 'ghost', size = 'md', loading = false, children, className = '', type = 'button', ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      aria-label={label}
      title={label}
      aria-busy={loading || undefined}
      className={cx(
        'inline-flex items-center justify-center shrink-0 cursor-pointer vp-press',
        'disabled:opacity-50 disabled:cursor-not-allowed',
        variantClasses[variant],
        iconSizeClasses[size],
        className,
      )}
      {...props}
    >
      {loading ? <Spinner /> : children}
    </button>
  )
})
