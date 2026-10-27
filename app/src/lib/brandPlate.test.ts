/**
 * Unit tests for the logo plate rule (BR-LOGO2): a light plate behind a wordmark
 * only when its ink would sit below 3:1 on the topbar colour, and nothing for
 * policies other than the default automatic plate.
 */

import { describe, expect, it } from 'vitest'
import { LOGO_PLATE_POLICY, logoNeedsPlate, topbarBarColor } from './brandPlate'

const FOREST = '#102F28'

describe('logoNeedsPlate', () => {
  it('uses the automatic plate by default (owner question 25, option a)', () => {
    expect(LOGO_PLATE_POLICY).toBe('auto')
  })

  it('plates a dark mark on the forest topbar', () => {
    expect(logoNeedsPlate(FOREST, 0.01)).toBe(true)
  })

  it('does not plate a light mark on the forest topbar', () => {
    expect(logoNeedsPlate(FOREST, 0.9)).toBe(false)
  })

  it('plates a light mark on a light topbar colour', () => {
    expect(logoNeedsPlate('#FFFFFF', 0.95)).toBe(true)
  })

  it('does not plate without a measured value', () => {
    expect(logoNeedsPlate(FOREST, null)).toBe(false)
    expect(logoNeedsPlate(FOREST, undefined)).toBe(false)
  })

  it('does not plate under the variant or warn policies (not implemented yet)', () => {
    expect(logoNeedsPlate(FOREST, 0.01, 'variant')).toBe(false)
    expect(logoNeedsPlate(FOREST, 0.01, 'warn')).toBe(false)
  })
})

describe('topbarBarColor', () => {
  it('keeps the forest default for a board without a secondary colour (Free)', () => {
    expect(topbarBarColor(null)).toBe(FOREST)
    expect(topbarBarColor(undefined)).toBe(FOREST)
  })

  it('returns a hex bar colour for a valid secondary colour', () => {
    expect(topbarBarColor('#1E40AF')).toMatch(/^#[0-9A-Fa-f]{6}$/)
  })
})
