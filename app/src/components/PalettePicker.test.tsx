/**
 * Behaviour tests for PalettePicker: a click writes the palette, the plan gating limits what is
 * written, locks hold on shuffle, Back restores the previous colours, and a shuffle that cannot
 * keep the locked colours changes nothing.
 */

import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CURATED_PALETTES,
  type Palette,
  type PaletteAllowance,
  type PaletteValues,
} from '../lib/palettes'
import { PalettePicker } from './PalettePicker'

/** The curated palette with this id; a missing id is a test bug, so it throws. */
function curated(id: string): Palette {
  const found = CURATED_PALETTES.find((p) => p.id === id)
  if (found === undefined) throw new Error(`no curated palette ${id}`)
  return found
}

const ALL: PaletteAllowance = { secondary: true, accent: true, perMode: true }
const LITE: PaletteAllowance = { secondary: false, accent: true, perMode: false }

/** A palette that passes every rule, so a shuffle with locks has something to keep. */
const current: PaletteValues = { ...curated('glacier').values }

function renderPicker(
  overrides: Partial<Parameters<typeof PalettePicker>[0]> = {},
  values: PaletteValues = current,
) {
  const onApply = vi.fn()
  render(<PalettePicker values={values} allowance={ALL} onApply={onApply} {...overrides} />)
  return { onApply }
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('PalettePicker', () => {
  it('renders the landmark and one button per curated palette', () => {
    renderPicker()
    expect(screen.getByTestId('admin-palette-picker')).toBeInTheDocument()
    for (const palette of CURATED_PALETTES) {
      expect(screen.getByTestId(`admin-palette-${palette.id}`)).toHaveAttribute('aria-pressed')
    }
  })

  it('writes all five colours when a palette is clicked', () => {
    const { onApply } = renderPicker()
    fireEvent.click(screen.getByTestId('admin-palette-forest'))
    expect(onApply).toHaveBeenCalledWith(CURATED_PALETTES.find((p) => p.id === 'forest')?.values)
  })

  it('writes only the colours the plan allows', () => {
    const { onApply } = renderPicker({ allowance: LITE })
    fireEvent.click(screen.getByTestId('admin-palette-forest'))
    const palette = CURATED_PALETTES.find((p) => p.id === 'forest')?.values
    expect(onApply).toHaveBeenCalledWith({ primary: palette?.primary, accent: palette?.accent })
  })

  it('marks the palette that matches the current colours as pressed', () => {
    const forest = CURATED_PALETTES.find((p) => p.id === 'forest')?.values as PaletteValues
    renderPicker({}, forest)
    expect(screen.getByTestId('admin-palette-forest')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('admin-palette-glacier')).toHaveAttribute('aria-pressed', 'false')
  })

  it('restores the previous colours with Back', () => {
    const { onApply } = renderPicker()
    fireEvent.click(screen.getByTestId('admin-palette-coral'))
    expect(screen.getByTestId('admin-palette-undo')).not.toBeDisabled()
    onApply.mockClear()
    fireEvent.click(screen.getByTestId('admin-palette-undo'))
    expect(onApply).toHaveBeenCalledWith(current)
  })

  it('keeps the locked primary and its per-mode value on shuffle', () => {
    const { onApply } = renderPicker()
    fireEvent.click(screen.getByTestId('admin-palette-lock-primary'))
    fireEvent.click(screen.getByTestId('admin-palette-shuffle'))
    const patch = onApply.mock.calls[0]?.[0] as Partial<PaletteValues>
    expect(patch.primary).toBe(current.primary)
    expect(patch.primaryDark).toBe(current.primaryDark)
  })

  it('shows the no-result notice and writes nothing when the locks cannot be kept', () => {
    const { onApply } = renderPicker({}, { ...current, accent: '#FFFFFF' })
    fireEvent.click(screen.getByTestId('admin-palette-lock-accent'))
    fireEvent.click(screen.getByTestId('admin-palette-shuffle'))
    expect(onApply).not.toHaveBeenCalled()
    expect(screen.getByTestId('admin-palette-notice')).toBeInTheDocument()
  })

  it('disables locks the plan does not allow', () => {
    renderPicker({ allowance: LITE })
    expect(screen.getByTestId('admin-palette-lock-secondary')).toBeDisabled()
    expect(screen.getByTestId('admin-palette-lock-accent')).not.toBeDisabled()
  })

  it('shows the plan note only when some colours are locked by the plan', () => {
    renderPicker({ planNote: 'Upgrade' })
    expect(screen.queryByTestId('admin-palette-plan-note')).not.toBeInTheDocument()
  })

  it('shows the plan note when the plan locks some colours', () => {
    renderPicker({ allowance: LITE, planNote: 'Upgrade' })
    expect(screen.getByTestId('admin-palette-plan-note')).toHaveTextContent('Upgrade')
  })

  it('disables every control while saving', () => {
    renderPicker({ disabled: true })
    expect(screen.getByTestId('admin-palette-glacier')).toBeDisabled()
    expect(screen.getByTestId('admin-palette-shuffle')).toBeDisabled()
    expect(screen.getByTestId('admin-palette-undo')).toBeDisabled()
    expect(screen.getByTestId('admin-palette-lock-primary')).toBeDisabled()
  })
})
