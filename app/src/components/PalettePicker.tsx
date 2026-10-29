/**
 * PalettePicker — curated colour palettes and a random palette for the branding editor's colour
 * group. One click writes a palette's colours into the form (nothing is saved until Save). The
 * shuffle keeps the colours the owner locks; Back restores the previous colours. A plan only gets
 * the fields it allows (see palettes.ts): the other colours are shown dimmed and never written.
 */

import { InlineNotice } from '@votepit/ui/ds'
import { Lock, LockOpen, Shuffle, Undo2 } from 'lucide-react'
import { type ReactNode, useId, useState } from 'react'
import { useT } from '../lib/i18n/context'
import {
  allowedFields,
  CURATED_PALETTES,
  isPaletteApplied,
  PALETTE_FIELDS,
  type PaletteAllowance,
  type PaletteLocks,
  type PaletteValues,
  pickAllowed,
  randomPalette,
} from '../lib/palettes'

/** Undo steps kept; older palette changes fall off the stack. */
const HISTORY_LIMIT = 20

const NO_LOCKS: PaletteLocks = { primary: false, secondary: false, accent: false }

export interface PalettePickerProps {
  values: PaletteValues
  allowance: PaletteAllowance
  /** Writes the given fields into the form. Only allowed fields are ever passed. */
  onApply: (patch: Partial<PaletteValues>) => void
  disabled?: boolean
  /** Shown under the controls when the plan locks some colours. */
  planNote?: ReactNode
}

export function PalettePicker({
  values,
  allowance,
  onApply,
  disabled = false,
  planNote,
}: PalettePickerProps) {
  const t = useT('adminPage')
  const titleId = useId()
  const [locks, setLocks] = useState<PaletteLocks>(NO_LOCKS)
  const [history, setHistory] = useState<Partial<PaletteValues>[]>([])
  const [noResult, setNoResult] = useState(false)
  const shown = new Set(allowedFields(allowance))
  const gated = !allowance.secondary || !allowance.accent || !allowance.perMode

  const apply = (patch: Partial<PaletteValues>) => {
    setHistory((steps) => [...steps.slice(-(HISTORY_LIMIT - 1)), pickAllowed(values, allowance)])
    setNoResult(false)
    onApply(patch)
  }

  const undo = () => {
    const previous = history[history.length - 1]
    if (previous === undefined) return
    setHistory((steps) => steps.slice(0, -1))
    setNoResult(false)
    onApply(previous)
  }

  const shuffle = () => {
    const next = randomPalette(values, locks, allowance)
    if (next === null) {
      setNoResult(true)
      return
    }
    apply(pickAllowed(next, allowance))
  }

  const toggleLock = (key: keyof PaletteLocks) =>
    setLocks((current) => ({ ...current, [key]: !current[key] }))

  const lockSlots: { key: keyof PaletteLocks; label: string; allowed: boolean }[] = [
    { key: 'primary', label: t('paletteLockPrimary'), allowed: true },
    { key: 'secondary', label: t('paletteLockSecondary'), allowed: allowance.secondary },
    { key: 'accent', label: t('paletteLockAccent'), allowed: allowance.accent },
  ]

  const tileBase =
    'flex min-h-11 min-w-0 flex-col gap-2 rounded-vp-sm border p-2 text-left transition-colors duration-ds-fast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-accent disabled:cursor-not-allowed disabled:opacity-60'
  const controlBase =
    'inline-flex min-h-9 items-center gap-1.5 rounded-vp-sm border px-3 text-vp-xs font-medium transition-colors duration-ds-fast focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-vp-accent disabled:cursor-not-allowed disabled:opacity-60'

  return (
    <section
      data-testid="admin-palette-picker"
      aria-labelledby={titleId}
      className="flex flex-col gap-3"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p id={titleId} className="text-vp-sm font-medium text-vp-title">
          {t('palettePickerTitle')}
        </p>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            data-testid="admin-palette-undo"
            disabled={disabled || history.length === 0}
            onClick={undo}
            className={`${controlBase} border-vp-border-subtle text-vp-text-secondary hover:text-vp-title`}
          >
            <Undo2 size={14} aria-hidden="true" />
            {t('paletteUndo')}
          </button>
          <button
            type="button"
            data-testid="admin-palette-shuffle"
            disabled={disabled}
            onClick={shuffle}
            className={`${controlBase} border-vp-border-subtle text-vp-title hover:bg-vp-surface-sunken`}
          >
            <Shuffle size={14} aria-hidden="true" />
            {t('paletteShuffle')}
          </button>
        </div>
      </div>
      <p className="text-vp-xs text-vp-text-secondary">{t('palettePickerHint')}</p>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {CURATED_PALETTES.map((palette) => {
          const name = t(`palette_${palette.id}`)
          const applied = isPaletteApplied(palette, values, allowance)
          return (
            <button
              key={palette.id}
              type="button"
              data-testid={`admin-palette-${palette.id}`}
              aria-pressed={applied}
              aria-label={t('paletteApplyLabel', { name })}
              disabled={disabled}
              onClick={() => apply(pickAllowed(palette.values, allowance))}
              className={`${tileBase} ${applied ? 'border-vp-ink bg-vp-surface' : 'border-vp-border-subtle bg-vp-surface hover:border-vp-text-secondary'}`}
            >
              <span aria-hidden="true" className="flex h-6 w-full overflow-hidden rounded-vp-xs">
                {PALETTE_FIELDS.map((field) => (
                  <span
                    key={field}
                    className="h-full flex-1"
                    style={{
                      backgroundColor: palette.values[field],
                      opacity: shown.has(field) ? 1 : 0.35,
                    }}
                  />
                ))}
              </span>
              <span className="w-full hyphens-auto text-vp-xs font-medium text-vp-title [overflow-wrap:anywhere]">
                {name}
              </span>
            </button>
          )
        })}
      </div>
      <div role="group" aria-label={t('paletteLockGroupLabel')} className="flex flex-wrap gap-2">
        {lockSlots.map((slot) => {
          const pressed = locks[slot.key]
          return (
            <button
              key={slot.key}
              type="button"
              data-testid={`admin-palette-lock-${slot.key}`}
              aria-pressed={pressed}
              disabled={disabled || !slot.allowed}
              onClick={() => toggleLock(slot.key)}
              className={`${controlBase} ${pressed ? 'border-vp-ink bg-vp-ink text-vp-on-ink' : 'border-vp-border-subtle text-vp-text-secondary hover:text-vp-title'}`}
            >
              {pressed ? (
                <Lock size={14} aria-hidden="true" />
              ) : (
                <LockOpen size={14} aria-hidden="true" />
              )}
              {slot.label}
            </button>
          )
        })}
      </div>
      {noResult && (
        <InlineNotice tone="warning" testId="admin-palette-notice">
          {t('paletteNoResult')}
        </InlineNotice>
      )}
      {gated && planNote !== undefined && (
        <p data-testid="admin-palette-plan-note" className="text-vp-xs text-vp-text-secondary">
          {planNote}
        </p>
      )}
    </section>
  )
}
