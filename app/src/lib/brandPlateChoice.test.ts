/**
 * Selection matrix for the topbar wordmark (BR-LOGO2 phase 4): the optional light
 * variant on a dark bar, the normal wordmark otherwise, and the plate rule.
 */

import { describe, expect, it } from 'vitest'
import { boardTopbarWordmark, boardWordmarkPlated, boardWordmarkSrc } from './boardLogo'
import { chooseTopbarWordmark } from './brandPlate'

const DARK_BAR = '#102F28'
const LIGHT_BAR = '#FFFFFF'
const LIGHT_INK = 0.9 // a light mark: high mean luminance
const DARK_INK = 0.05 // a dark mark: low mean luminance

describe('chooseTopbarWordmark', () => {
  it('uses the light variant on a dark bar when it reaches 3:1, without a plate', () => {
    const c = chooseTopbarWordmark(
      DARK_BAR,
      { value: 'normal', ink: DARK_INK },
      { value: 'light', ink: LIGHT_INK },
    )
    expect(c).toEqual({ value: 'light', plated: false, variant: 'light' })
  })

  it('falls back to the normal wordmark when the light variant is too dark for the bar', () => {
    const c = chooseTopbarWordmark(
      DARK_BAR,
      { value: 'normal', ink: LIGHT_INK },
      { value: 'light', ink: DARK_INK },
    )
    expect(c).toEqual({ value: 'normal', plated: false, variant: 'normal' })
  })

  it('plates the normal wordmark when neither variant reaches 3:1', () => {
    const c = chooseTopbarWordmark(DARK_BAR, { value: 'normal', ink: DARK_INK }, null)
    expect(c).toEqual({ value: 'normal', plated: true, variant: 'normal' })
  })

  it('on a light bar the normal wordmark needs no plate when it reads well', () => {
    const c = chooseTopbarWordmark(
      LIGHT_BAR,
      { value: 'normal', ink: DARK_INK },
      { value: 'light', ink: LIGHT_INK },
    )
    expect(c).toEqual({ value: 'normal', plated: false, variant: 'normal' })
  })

  it('a light variant alone on a light bar is plated, as the fallback', () => {
    const c = chooseTopbarWordmark(LIGHT_BAR, null, { value: 'light', ink: LIGHT_INK })
    expect(c).toEqual({ value: 'light', plated: true, variant: 'light' })
  })

  it('ignores a light variant without a measured value', () => {
    const c = chooseTopbarWordmark(
      DARK_BAR,
      { value: 'normal', ink: DARK_INK },
      { value: 'light', ink: null },
    )
    expect(c?.value).toBe('normal')
  })

  it('returns null when neither slot holds an image', () => {
    expect(chooseTopbarWordmark(DARK_BAR, null, null)).toBeNull()
  })
})

describe('board topbar wordmark', () => {
  const board = (extra: Record<string, unknown> = {}) => ({
    secondary_color: null,
    wordmark_public_id: 'normal-id',
    wordmark_ink: DARK_INK,
    wordmark_light_public_id: 'light-id',
    wordmark_light_ink: LIGHT_INK,
    ...extra,
  })

  it('shows the light variant on the forest default bar', () => {
    expect(boardTopbarWordmark(board())?.value).toBe('light-id')
    expect(boardWordmarkSrc('demo', board())).toContain('light-id')
    expect(boardWordmarkPlated(board())).toBe(false)
  })

  it('shows the normal wordmark, plated, when the light slot is empty', () => {
    const b = board({ wordmark_light_public_id: null, wordmark_light_ink: null })
    expect(boardWordmarkSrc('demo', b)).toContain('normal-id')
    expect(boardWordmarkPlated(b)).toBe(true)
  })

  it('has no wordmark when neither slot is set', () => {
    const b = board({
      wordmark_public_id: null,
      wordmark_ink: null,
      wordmark_light_public_id: null,
      wordmark_light_ink: null,
    })
    expect(boardWordmarkSrc('demo', b)).toBeUndefined()
    expect(boardWordmarkPlated(b)).toBe(false)
  })
})

describe('chooseTopbarWordmark on a dark bar (P-26 dark mode)', () => {
  const DARK_BAR = '#102F28'
  it('the light variant wins on the forest bar when it reaches 3:1', () => {
    const c = chooseTopbarWordmark(
      DARK_BAR,
      { value: 'normal', ink: 0.05 },
      { value: 'light', ink: 0.85 },
    )
    expect(c).toEqual({ value: 'light', plated: false, variant: 'light' })
  })
  it('a dark normal wordmark on the forest bar falls back to a plate, not to an unreadable logo', () => {
    const c = chooseTopbarWordmark(DARK_BAR, { value: 'normal', ink: 0.02 }, null)
    expect(c).toEqual({ value: 'normal', plated: true, variant: 'normal' })
  })
})
