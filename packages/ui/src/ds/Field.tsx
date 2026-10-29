import {
  forwardRef,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
  useId,
} from 'react'
import { cx } from '../lib/cx'
import { Icon } from './icons'

interface FieldFrameProps {
  label: string
  hint?: string
  error?: string
  required?: boolean
  fieldId: string
  /** Optional trailing element in the label row (e.g. a formatting toolbar). */
  labelEnd?: ReactNode
  /** Replaces the visible label with a hidden one (AT only); the field stays wired to it. */
  hiddenLabel?: ReactNode
  /** 'below' moves the hint under the control so a long hint cannot push the control out of line with its neighbours. */
  hintPlacement?: 'above' | 'below'
  children: (describedBy: string | undefined, invalid: boolean) => ReactNode
}

/** Shared label, hint and error wiring. The error is linked with aria-describedby and aria-invalid. */
function FieldFrame({
  label,
  hint,
  error,
  required,
  fieldId,
  labelEnd,
  hiddenLabel,
  hintPlacement = 'above',
  children,
}: FieldFrameProps) {
  const hintId = `${fieldId}-hint`
  const errorId = `${fieldId}-error`
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ')
  const labelEl = hiddenLabel ?? (
    <label className="ds-field__label" htmlFor={fieldId}>
      {label}
      {required && (
        <span className="ds-field__required" aria-hidden="true">
          *
        </span>
      )}
    </label>
  )
  return (
    <div className="ds-field">
      {labelEnd && !hiddenLabel ? (
        <div className="ds-field__head">
          {labelEl}
          <div className="ds-field__end">{labelEnd}</div>
        </div>
      ) : (
        labelEl
      )}
      {hint && hintPlacement === 'above' && (
        <p id={hintId} className="ds-field__hint">
          {hint}
        </p>
      )}
      {children(describedBy || undefined, Boolean(error))}
      {hint && hintPlacement === 'below' && (
        <p id={hintId} className="ds-field__hint">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="ds-field__message">
          <Icon name="error" size="small" />
          <span>{error}</span>
        </p>
      )}
    </div>
  )
}

export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string
  hint?: string
  /** Validation message. Setting it marks the field invalid. */
  error?: string
  type?: 'text' | 'email' | 'url' | 'password' | 'datetime-local' | 'number' | 'date'
  /** Monospace value (slugs, tokens, codes, URLs). */
  mono?: boolean
  /** Fixed text before the value, e.g. "/" for a slug. Decorative; the label names the field. */
  prefix?: string
  /** Keeps the label for assistive technology only (for inputs inside a toolbar). */
  hideLabel?: boolean
  /** Status icon or short text shown inside the field, at its end (decorative; announce via hint/error). */
  trailing?: ReactNode
  /** Trailing element in the label row, e.g. an upgrade link. */
  labelEnd?: ReactNode
  /** Where the hint sits: above the control (default) or below it, to keep neighbouring fields aligned. */
  hintPlacement?: 'above' | 'below'
  /** Stable data-testid on the input. */
  testId: string
}

/** Single-line input with a visible label. Required and read-only are passed through. */
export function TextField({
  label,
  hint,
  error,
  type = 'text',
  required,
  id,
  className,
  mono = false,
  prefix,
  hideLabel = false,
  trailing,
  labelEnd,
  hintPlacement,
  testId,
  ...rest
}: TextFieldProps) {
  const autoId = useId()
  const fieldId = id ?? `${autoId}-field`
  const frameLabel = hideLabel ? (
    <label className="ds-visually-hidden" htmlFor={fieldId}>
      {label}
    </label>
  ) : undefined
  return (
    <FieldFrame
      label={label}
      hint={hint}
      error={error}
      required={required}
      fieldId={fieldId}
      labelEnd={labelEnd}
      hiddenLabel={frameLabel}
      hintPlacement={hintPlacement}
    >
      {(describedBy, invalid) => {
        const input = (
          <input
            {...rest}
            id={fieldId}
            type={type}
            required={required}
            data-testid={testId}
            className={cx(
              'ds-field__control',
              'ds-focusable',
              mono && 'ds-field__control--mono',
              prefix !== undefined && 'ds-field__control--prefixed',
              trailing !== undefined && 'pr-9',
              className,
            )}
            aria-invalid={invalid ? 'true' : undefined}
            aria-describedby={describedBy}
          />
        )
        if (trailing !== undefined) {
          return (
            <div className="relative">
              {input}
              <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center">
                {trailing}
              </span>
            </div>
          )
        }
        if (prefix === undefined) return input
        return (
          <div className="ds-field__prefixed">
            <span className="ds-field__prefix" aria-hidden="true">
              {prefix}
            </span>
            {input}
          </div>
        )
      }}
    </FieldFrame>
  )
}

export interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label: string
  hint?: string
  error?: string
  /** Trailing element in the label row, e.g. a formatting toolbar. */
  labelEnd?: ReactNode
  testId: string
}

/** Multi-line input with a visible label and the same error wiring as TextField. Forwards its ref. */
export const TextArea = forwardRef<HTMLTextAreaElement, TextAreaProps>(function TextArea(
  { label, hint, error, required, id, className, testId, labelEnd, rows = 4, ...rest },
  ref,
) {
  const autoId = useId()
  const fieldId = id ?? `${autoId}-field`
  return (
    <FieldFrame
      label={label}
      hint={hint}
      error={error}
      required={required}
      fieldId={fieldId}
      labelEnd={labelEnd}
    >
      {(describedBy, invalid) => (
        <textarea
          {...rest}
          ref={ref}
          id={fieldId}
          rows={rows}
          required={required}
          data-testid={testId}
          className={cx('ds-field__control', 'ds-focusable', className)}
          aria-invalid={invalid ? 'true' : undefined}
          aria-describedby={describedBy}
        />
      )}
    </FieldFrame>
  )
})

export interface SearchFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string
  /** Keeps the label for assistive technology only (search inside a toolbar). */
  hideLabel?: boolean
  testId: string
}

/** Search input with a label (visible unless hideLabel) and a decorative icon. */
export function SearchField({
  label,
  hideLabel = false,
  id,
  className,
  testId,
  ...rest
}: SearchFieldProps) {
  const autoId = useId()
  const fieldId = id ?? `${autoId}-search`
  return (
    <div className="ds-field">
      <label className={hideLabel ? 'ds-visually-hidden' : 'ds-field__label'} htmlFor={fieldId}>
        {label}
      </label>
      <div className="ds-search-field">
        <Icon name="search" className="ds-search-field__icon" />
        <input
          {...rest}
          id={fieldId}
          type="search"
          data-testid={testId}
          className={cx('ds-field__control', 'ds-focusable', className)}
        />
      </div>
    </div>
  )
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange'> {
  label: string
  hint?: string
  error?: string
  value: string
  onChange: (value: string) => void
  /** <option> elements. */
  children: ReactNode
  /** Keeps the label for assistive technology only (for selects inside a toolbar). */
  hideLabel?: boolean
  /** Trailing element in the label row, e.g. an upgrade link. */
  labelEnd?: ReactNode
  /** Stable data-testid on the select. */
  testId: string
}

/** Native select with a visible label; the OS picker, never a custom menu. */
export function Select({
  label,
  hint,
  error,
  value,
  onChange,
  children,
  required,
  id,
  className,
  hideLabel = false,
  labelEnd,
  testId,
  ...rest
}: SelectProps) {
  const autoId = useId()
  const fieldId = id ?? `${autoId}-field`
  // hideLabel: the accessible name goes on aria-label, so the text is not duplicated in the DOM.
  return (
    <FieldFrame
      label={label}
      hint={hint}
      error={error}
      required={required}
      fieldId={fieldId}
      labelEnd={labelEnd}
      hiddenLabel={hideLabel ? <></> : undefined}
    >
      {(describedBy, invalid) => (
        <div className="ds-select">
          <select
            {...rest}
            id={fieldId}
            aria-label={hideLabel ? label : undefined}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            required={required}
            data-testid={testId}
            className={cx('ds-field__control', 'ds-focusable', 'ds-select__control', className)}
            aria-invalid={invalid ? 'true' : undefined}
            aria-describedby={describedBy}
          >
            {children}
          </select>
          <Icon name="chevron-down" size="small" className="ds-select__chevron" />
        </div>
      )}
    </FieldFrame>
  )
}
