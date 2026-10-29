/**
 * ColorField — a brand colour with a native colour picker, the hex field and a
 * contrast auto-fix at the field (P-26 engine). The picker and the hex field
 * edit the same value; a valid hex is the only thing stored (the server validates
 * again). When the theme engine would have to adjust the colour to reach the
 * contrast rule, the field offers the adjusted value as a one-click fix; nothing
 * is changed until the owner clicks it.
 */

import { Button, InlineNotice, TextField } from '@votepit/ui/ds'
import type { ReactNode } from 'react'
import { brandThemeVars } from '../lib/branding'

const SIX_DIGIT = /^#[0-9a-fA-F]{6}$/

export type ColorRole = 'accent' | 'fill'

export interface ColorFieldProps {
  testId: string
  id: string
  name: string
  label: string
  value: string
  onChange: (value: string) => void
  /** 'accent' = a colour used as text/indicator on the page; 'fill' = a button colour. */
  swatchRole: ColorRole
  placeholder: string
  error?: string
  hint?: string
  labelEnd?: ReactNode
  disabled?: boolean
  pickerLabel: string
  autoFixLabel: string
  autoFixNotice: string
}

/** The contrast-corrected value for this colour, or null when it already passes. */
export function contrastSuggestion(value: string, role: ColorRole): string | null {
  if (!SIX_DIGIT.test(value)) return null
  if (role === 'accent') {
    const { corrections, vars } = brandThemeVars(value, null)
    return corrections.includes('accent-text') ? (vars['--color-vp-accent-strong'] ?? null) : null
  }
  const { corrections, vars } = brandThemeVars(null, value)
  return corrections.includes('button-fill') ? (vars['--vp-ink'] ?? null) : null
}

export function ColorField({
  testId,
  id,
  name,
  label,
  value,
  onChange,
  swatchRole,
  placeholder,
  error,
  hint,
  labelEnd,
  disabled = false,
  pickerLabel,
  autoFixLabel,
  autoFixNotice,
}: ColorFieldProps) {
  const pickerValue = (SIX_DIGIT.test(value) ? value : placeholder).toLowerCase()
  const suggestion = contrastSuggestion(value, swatchRole)

  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-end gap-3">
        <input
          type="color"
          data-testid={`${testId}-picker`}
          aria-label={pickerLabel}
          value={pickerValue}
          disabled={disabled}
          onChange={(e) => onChange(e.target.value.toUpperCase())}
          className="mb-1 size-11 shrink-0 cursor-pointer rounded-vp-sm border border-vp-border-subtle bg-vp-surface p-1 disabled:cursor-not-allowed"
        />
        <div className="min-w-0 flex-1">
          <TextField
            testId={testId}
            label={label}
            name={name}
            id={id}
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={placeholder}
            mono
            error={error}
            hint={hint}
            labelEnd={labelEnd}
            disabled={disabled}
            autoComplete="off"
          />
        </div>
      </div>
      {suggestion !== null && error === undefined && (
        <div className="flex flex-col gap-2">
          <InlineNotice tone="warning" testId={`${testId}-contrast`}>
            {autoFixNotice} <span className="font-mono">{suggestion}</span>
          </InlineNotice>
          <div>
            <Button
              type="button"
              variant="secondary"
              testId={`${testId}-autofix`}
              disabled={disabled}
              onClick={() => onChange(suggestion)}
            >
              {autoFixLabel}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
