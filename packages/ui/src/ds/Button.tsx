import type {
  AnchorHTMLAttributes,
  ButtonHTMLAttributes,
  ElementType,
  MouseEvent,
  ReactNode,
} from 'react'
import { forwardRef } from 'react'
import { cx } from '../lib/cx'
import type { IconName } from './icons'
import { Icon } from './icons'

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'text'
  | 'text-destructive'
  | 'destructive'
  | 'on-inverse'
  | 'accent'
export type ButtonSize = 'default' | 'large'

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'type'> {
  variant?: ButtonVariant
  size?: ButtonSize
  /** Shows a spinner and aria-busy. The label keeps its box, so the button does not change width. */
  loading?: boolean
  type?: 'button' | 'submit' | 'reset'
  /** Decorative leading icon; the label carries the meaning. */
  icon?: IconName
  /** Full-width button (forms, side panels). */
  block?: boolean
  /** Stable data-testid on the button element (kebab-case, `<screen>-<element>`). */
  testId: string
  children: ReactNode
}

/**
 * Text button. `loading` blocks repeated activation (the click is swallowed) while
 * the button stays focusable. `disabled` uses the disabled attribute and a dashed
 * outline, so the state does not rely on colour.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'default',
    loading = false,
    disabled = false,
    type = 'button',
    icon,
    block = false,
    testId,
    className,
    onClick,
    children,
    ...rest
  }: ButtonProps,
  ref,
) {
  const handleClick = (event: MouseEvent<HTMLButtonElement>) => {
    if (loading) {
      event.preventDefault()
      return
    }
    onClick?.(event)
  }

  return (
    <button
      {...rest}
      ref={ref}
      type={type}
      data-testid={testId}
      className={cx(
        'ds-button',
        'ds-focusable',
        `ds-button--${variant}`,
        size === 'large' && 'ds-button--large',
        block && 'ds-button--block',
        className,
      )}
      disabled={disabled}
      aria-busy={loading || undefined}
      onClick={handleClick}
    >
      <span className="ds-button__label">
        {icon && <Icon name={icon} size="small" />}
        {children}
      </span>
      {loading && <span className="ds-spinner ds-button__spinner" aria-hidden="true" />}
    </button>
  )
})

export interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'type'> {
  icon: IconName
  /** Required: an icon-only control needs a name for assistive technology. */
  accessibleLabel: string
  /** Toggle state; sets aria-pressed when given. */
  pressed?: boolean
  testId: string
}

/** Square 44 × 44 px icon control with a required accessible name. */
export function IconButton({
  icon,
  accessibleLabel,
  pressed,
  testId,
  className,
  onClick,
  ...rest
}: IconButtonProps) {
  return (
    <button
      {...rest}
      type="button"
      data-testid={testId}
      aria-label={accessibleLabel}
      aria-pressed={pressed}
      title={accessibleLabel}
      className={cx('ds-icon-button', 'ds-focusable', className)}
      onClick={onClick}
    >
      <Icon name={icon} />
    </button>
  )
}

export interface ButtonLinkProps extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> {
  /** Destination; passed to `linkAs` (router links take `to`, so pass an adapter). */
  href: string
  variant?: ButtonVariant
  size?: ButtonSize
  /** Full-width link (forms, side panels). */
  block?: boolean
  /** Decorative leading icon; the label carries the meaning. */
  icon?: IconName
  /** Stable data-testid on the link (kebab-case, `<screen>-<element>`). */
  testId: string
  /** Router link component that accepts `href`. Defaults to a plain anchor. */
  linkAs?: ElementType
  children: ReactNode
}

/** A navigation link styled as a DS button, same variants and sizes as Button. */
export function ButtonLink({
  href,
  variant = 'primary',
  size = 'default',
  block = false,
  icon,
  testId,
  linkAs,
  className,
  children,
  ...rest
}: ButtonLinkProps) {
  const Tag = (linkAs ?? 'a') as ElementType
  return (
    <Tag
      {...rest}
      href={href}
      data-testid={testId}
      className={cx(
        'ds-button',
        'ds-focusable',
        `ds-button--${variant}`,
        size === 'large' && 'ds-button--large',
        block && 'ds-button--block',
        className,
      )}
    >
      <span className="ds-button__label">
        {icon && <Icon name={icon} size="small" />}
        {children}
      </span>
    </Tag>
  )
}
