/**
 * Palette logic: every curated palette and every generated palette passes the rules the app
 * enforces on a board (server primary/secondary contrast, theme-engine corrections on the light
 * and dark ground), locks hold, and the allowance limits what a palette may write.
 */

import { describe, expect, it } from 'vitest'
import {
  allowedFields,
  CURATED_PALETTES,
  generatePalette,
  isPaletteApplied,
  PALETTE_FIELDS,
  type Palette,
  type PaletteValues,
  paletteProblems,
  pickAllowed,
  randomPalette,
  repairPalette,
  serverPrimaryOk,
} from './palettes'

/** The curated palette with this id; a missing id is a test bug, so it throws. */
function curated(id: string): Palette {
  const found = CURATED_PALETTES.find((p) => p.id === id)
  if (found === undefined) throw new Error(`no curated palette ${id}`)
  return found
}

const ALL = { secondary: true, accent: true, perMode: true }
const NONE_ALLOWED = { secondary: false, accent: false, perMode: false }

/** Deterministic random source (mulberry32), so every run sees the same sequence. */
function seeded(seed: number): () => number {
  let state = seed >>> 0
  return () => {
    state = (state + 0x6d2b79f5) >>> 0
    let t = state
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const EMPTY: PaletteValues = {
  primary: '',
  secondary: '',
  accent: '',
  primaryDark: '',
  accentDark: '',
}

describe('curated palettes', () => {
  it('has 13 palettes with unique ids and all five values set', () => {
    expect(CURATED_PALETTES).toHaveLength(13)
    expect(new Set(CURATED_PALETTES.map((p) => p.id)).size).toBe(13)
    for (const palette of CURATED_PALETTES) {
      for (const field of PALETTE_FIELDS) expect(palette.values[field]).toMatch(/^#[0-9A-F]{6}$/)
    }
  })

  it('passes every rule on both grounds', () => {
    for (const palette of CURATED_PALETTES) {
      expect(paletteProblems(palette.values), palette.id).toEqual([])
    }
  })
})

describe('serverPrimaryOk', () => {
  it('accepts a dark primary and rejects a light one (BrandingValidator::primaryColor)', () => {
    expect(serverPrimaryOk('#000000')).toBe(true)
    expect(serverPrimaryOk('#2A6FB0')).toBe(true)
    expect(serverPrimaryOk('#FFFF00')).toBe(false)
  })
})

describe('paletteProblems', () => {
  it('reports the rule a bad value breaks', () => {
    const problems = paletteProblems({ ...curated('glacier').values, primary: '#FFFF00' })
    expect(problems).toContain('server:primary')
  })

  it('reports a malformed value', () => {
    expect(paletteProblems({ ...EMPTY, accent: 'red' })).toContain('format:accent')
  })

  it('treats unset values as no constraint', () => {
    expect(paletteProblems(EMPTY)).toEqual([])
  })
})

describe('repairPalette', () => {
  it('makes an illegible palette pass without changing its hues', () => {
    const bad: PaletteValues = {
      primary: '#FFFF00',
      secondary: '#FFFF00',
      accent: '#FFFFFF',
      primaryDark: '#000000',
      accentDark: '#000000',
    }
    const fixed = repairPalette(bad)
    expect(paletteProblems(fixed)).toEqual([])
  })
})

describe('generatePalette', () => {
  it('yields a clean palette for 500 seeded runs', () => {
    const random = seeded(20261028)
    for (let i = 0; i < 500; i += 1) {
      const palette = generatePalette(random)
      expect(paletteProblems(palette), `run ${i}: ${JSON.stringify(palette)}`).toEqual([])
    }
  })
})

describe('randomPalette', () => {
  it('returns a clean full palette without locks', () => {
    const next = randomPalette(
      EMPTY,
      { primary: false, secondary: false, accent: false },
      ALL,
      seeded(7),
    )
    expect(next).not.toBeNull()
    expect(paletteProblems(next as PaletteValues)).toEqual([])
  })

  it('keeps a locked primary and its per-mode value', () => {
    const current = { ...curated('glacier').values }
    for (let seed = 1; seed <= 50; seed += 1) {
      const next = randomPalette(
        current,
        { primary: true, secondary: false, accent: false },
        ALL,
        seeded(seed),
      )
      expect(next?.primary).toBe(current.primary)
      expect(next?.primaryDark).toBe(current.primaryDark)
      expect(paletteProblems(next as PaletteValues)).toEqual([])
    }
  })

  it('keeps a locked accent and its accentDark, re-rolls the rest', () => {
    const current = { ...curated('graphite').values }
    const next = randomPalette(
      current,
      { primary: false, secondary: false, accent: true },
      ALL,
      seeded(3),
    )
    expect(next?.accent).toBe(current.accent)
    expect(next?.accentDark).toBe(current.accentDark)
    expect(next?.primary).not.toBe(current.primary)
  })

  it('returns null when a locked colour cannot fit any palette', () => {
    const current = { ...curated('glacier').values, accent: '#FFFFFF' }
    const next = randomPalette(
      current,
      { primary: false, secondary: false, accent: true },
      ALL,
      seeded(5),
    )
    expect(next).toBeNull()
  })

  it('ignores locks on fields the plan does not allow', () => {
    const current = { ...curated('glacier').values, secondary: '#FFFFFF' }
    const next = randomPalette(
      current,
      { primary: false, secondary: true, accent: false },
      NONE_ALLOWED,
      seeded(9),
    )
    expect(next).not.toBeNull()
    expect(next?.secondary).not.toBe('#FFFFFF')
  })
})

describe('allowance', () => {
  it('always includes primary and adds the gated fields', () => {
    expect(allowedFields(NONE_ALLOWED)).toEqual(['primary'])
    expect(allowedFields(ALL)).toEqual([
      'primary',
      'secondary',
      'accent',
      'primaryDark',
      'accentDark',
    ])
  })

  it('picks only the allowed values', () => {
    const palette = curated('glacier').values
    expect(pickAllowed(palette, { secondary: false, accent: true, perMode: false })).toEqual({
      primary: palette.primary,
      accent: palette.accent,
    })
  })

  it('judges a palette as applied only on the allowed fields', () => {
    const palette = curated('glacier')
    const current = { ...palette.values, secondary: '#FFFFFF' }
    expect(isPaletteApplied(palette, current, NONE_ALLOWED)).toBe(true)
    expect(isPaletteApplied(palette, current, ALL)).toBe(false)
  })
})
