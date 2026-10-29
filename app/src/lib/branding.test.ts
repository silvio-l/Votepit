/**
 * Colour decision for the board-branded top bar (lib/branding.ts). The thresholds
 * are WCAG 1.4.3 (text 4.5:1) and 1.4.11 (UI indicators 3:1); the bar is the forest
 * default unless a Lite+ board has a readable secondary colour.
 */
import {
  contrastRatio,
  hexToHsl,
  hslToHex,
  mixHex,
  relativeLuminance,
  tokens,
  tokensDark,
} from '@votepit/ui/ds'
import { describe, expect, it } from 'vitest'
import {
  boardCanvasFor,
  brandingStyle,
  brandThemeVars,
  COOL_CANVAS_HEX,
  coverAccentFor,
  indicatorOnDefaultBar,
  isCoolBrand,
  lightCanvasFor,
  logoPlateVars,
  normalizeHex,
  posterAccent,
  readableTextOn,
  tagTextColor,
  topbarBrandVars,
} from './branding'

const FOREST = '#102F28'
const WHITE = '#FFFFFF'
const INK = '#151918'
const PORCELAIN = '#F4F3EC'
/** WhisPaste "Gletscher", the cool reference palette from dogfood-branding #11. */
const GLETSCHER = { primary: '#005F73', secondary: '#0B122A', accent: '#FFB547' }

describe('normalizeHex', () => {
  it('uppercases a six-digit hex', () => {
    expect(normalizeHex('#0b6b4c')).toBe('#0B6B4C')
  })

  it('expands a three-digit hex, as the server accepts that form', () => {
    expect(normalizeHex('#abc')).toBe('#AABBCC')
  })

  it('rejects anything that is not a hex colour', () => {
    expect(normalizeHex('red')).toBeNull()
    expect(normalizeHex('#12345')).toBeNull()
    expect(normalizeHex('url(javascript:1)')).toBeNull()
    expect(normalizeHex('')).toBeNull()
    expect(normalizeHex(undefined)).toBeNull()
    expect(normalizeHex(42)).toBeNull()
  })
})

describe('readableTextOn', () => {
  it('picks white on a dark colour', () => {
    expect(readableTextOn('#0B6B4C')).toBe(WHITE)
  })

  it('picks ink on a light colour', () => {
    expect(readableTextOn('#F4F3EC')).toBe(INK)
  })

  it('returns null for a mid tone that fails both white and ink at 4.5:1', () => {
    // White is about 4.0:1 and ink about 4.4:1 on this grey, so neither reaches 4.5:1.
    expect(contrastRatio(WHITE, '#7F7F7F')).toBeLessThan(4.5)
    expect(contrastRatio(INK, '#7F7F7F')).toBeLessThan(4.5)
    expect(readableTextOn('#7F7F7F')).toBeNull()
  })
})

describe('indicatorOnDefaultBar', () => {
  it('keeps a light accent that already has 3:1 on the forest bar', () => {
    expect(contrastRatio('#1FA890', FOREST)).toBeGreaterThanOrEqual(3)
    expect(indicatorOnDefaultBar('#1FA890')).toBe('#1FA890')
  })

  it('lightens a dark accent until it reaches 3:1, keeping it off the default', () => {
    // Indigo is far too dark on forest green; the result must be a lighter tint of it.
    const accent = indicatorOnDefaultBar('#312E81')
    expect(contrastRatio(accent, FOREST)).toBeGreaterThanOrEqual(3)
    expect(accent).not.toBe('#312E81')
  })
})

describe('topbarBrandVars', () => {
  it('Free (accent only): the forest bar stays, only the rule and focus take the accent', () => {
    const vars = topbarBrandVars('#312E81', null)
    expect(vars).not.toHaveProperty('--vpds-c-topbar-background')
    expect(vars).not.toHaveProperty('--vpds-c-topbar-mark-plate')
    expect(vars['--vpds-c-topbar-accent']).toBe(vars['--vpds-c-topbar-focus'])
    expect(contrastRatio(vars['--vpds-c-topbar-accent'] ?? '', FOREST)).toBeGreaterThanOrEqual(3)
  })

  it('Lite+: the whole bar takes the secondary colour with a readable foreground', () => {
    const vars = topbarBrandVars('#1FA890', '#0B6B4C')
    expect(vars['--vpds-c-topbar-background']).toBe('#0B6B4C')
    expect(vars['--vpds-c-topbar-foreground']).toBe(WHITE)
    expect(
      contrastRatio(vars['--vpds-c-topbar-foreground'] ?? '', '#0B6B4C'),
    ).toBeGreaterThanOrEqual(4.5)
    expect(vars['--vpds-c-topbar-mark-plate']).toBe(WHITE)
  })

  it('Lite+: the muted link text keeps 4.5:1 on the branded bar', () => {
    const vars = topbarBrandVars(null, '#0B6B4C')
    expect(contrastRatio(vars['--vpds-c-topbar-muted'] ?? '', '#0B6B4C')).toBeGreaterThanOrEqual(
      4.5,
    )
  })

  it('Lite+: the active rule is the board accent when it has 3:1 on the bar, else the text colour', () => {
    expect(topbarBrandVars('#FFD166', '#0B6B4C')['--vpds-c-topbar-accent']).toBe('#FFD166')
    // Same-hue dark accent on a dark bar has too little contrast: falls back to white.
    expect(topbarBrandVars('#0A4A35', '#0B6B4C')['--vpds-c-topbar-accent']).toBe(WHITE)
  })

  it('a mid-tone secondary is adjusted in lightness, not rejected: the bar keeps 4.5:1 text', () => {
    const vars = topbarBrandVars('#312E81', '#7F7F7F')
    const bar = vars['--vpds-c-topbar-background'] ?? ''
    expect(bar).not.toBe('#7F7F7F')
    expect(contrastRatio(vars['--vpds-c-topbar-foreground'] ?? '', bar)).toBeGreaterThanOrEqual(4.5)
  })

  it('returns no variables without a valid colour, so the default bar applies', () => {
    expect(topbarBrandVars('not-a-colour', null)).toEqual({})
    expect(topbarBrandVars(null, '#12345')).toEqual({})
  })

  it('a three-digit secondary colour is expanded before use', () => {
    expect(topbarBrandVars(null, '#0b6')['--vpds-c-topbar-background']).toBe('#00BB66')
  })
})

describe('brandingStyle', () => {
  it('keeps the existing ink mapping for a Lite+ secondary colour', () => {
    const style = brandingStyle({ primary_color: null, secondary_color: '#0B6B4C' })
    expect(style).toMatchObject({ '--vp-ink': '#0B6B4C', '--color-vp-ink': '#0B6B4C' })
    expect(style).toMatchObject({ '--vpds-c-topbar-background': '#0B6B4C' })
  })

  it('a board without colours gets no branding variables at all', () => {
    expect(brandingStyle({ primary_color: null, secondary_color: null })).toEqual({})
  })

  it('a Free board gets the accent for the top bar but no bar background', () => {
    const style = brandingStyle({ primary_color: '#312E81', secondary_color: null })
    expect(style).toHaveProperty('--vp-primary', '#312E81')
    expect(style).toHaveProperty('--vpds-c-topbar-accent')
    expect(style).not.toHaveProperty('--vpds-c-topbar-background')
  })
})

describe('brandThemeVars (P-26 theme engine)', () => {
  it('a valid accent that already passes keeps its colour and reports no correction', () => {
    const { vars, corrections } = brandThemeVars('#0B6B4C', null)
    expect(vars['--vp-primary']).toBe('#0B6B4C')
    expect(vars['--color-vp-accent']).toBe('#0B6B4C')
    expect(vars['--color-vp-accent-strong']).toBe('#0B6B4C')
    expect(corrections).toEqual([])
  })

  it('a light accent is darkened for text on the light surface until 4.5:1', () => {
    const { vars, corrections } = brandThemeVars('#FFD166', null)
    expect(contrastRatio(vars['--color-vp-accent-strong'] ?? '', PORCELAIN)).toBeGreaterThanOrEqual(
      4.5,
    )
    expect(contrastRatio(vars['--color-vp-accent'] ?? '', PORCELAIN)).toBeGreaterThanOrEqual(3)
    expect(corrections).toContain('accent-text')
  })

  it('on the dark surface a dark accent is lightened until its text reaches 4.5:1', () => {
    const { vars } = brandThemeVars('#312E81', null, 'dark')
    expect(
      contrastRatio(
        vars['--color-vp-accent-strong'] ?? '',
        tokensDark['semantic.surface.canvas'] ?? INK,
      ),
    ).toBeGreaterThanOrEqual(4.5)
  })

  it('the secondary colour gets a text colour that reads on it, with a correction flag if the fill moved', () => {
    const plain = brandThemeVars(null, '#0B6B4C')
    expect(plain.vars['--vp-on-ink']).toBe(WHITE)
    expect(plain.corrections).toEqual([])
    const mid = brandThemeVars(null, '#7F7F7F')
    expect(
      contrastRatio(mid.vars['--vp-on-ink'] ?? '', mid.vars['--vp-ink'] ?? ''),
    ).toBeGreaterThanOrEqual(4.5)
    expect(mid.corrections).toContain('button-fill')
  })

  it('is deterministic and returns no variables without colours', () => {
    expect(brandThemeVars('#1FA890', '#0B6B4C')).toEqual(brandThemeVars('#1FA890', '#0B6B4C'))
    expect(brandThemeVars(null, null)).toEqual({ vars: {}, corrections: [] })
  })
})

describe('posterAccent (print on white, owner 26a)', () => {
  it('keeps the accent for title and rule when it reaches 4.5:1 on white', () => {
    expect(posterAccent('#15604f')).toEqual({ text: '#15604F', rule: '#15604F' })
  })

  // Shared correction vectors (same as brandThemeVars: lightness only, hue kept, darker on white).
  // The PHP-free poster is rendered client-side only, so there is no second port to keep in step.
  it.each([
    // [accent, title text, rule]
    ['#15604F', '#15604F', '#15604F'], // reaches 4.5:1 untouched
    ['#101010', '#101010', '#101010'], // very dark: passes untouched
    ['#3A9A5B', '#32864F', '#3A9A5B'], // mid: title darkened to 4.5:1, rule 3:1 already
    ['#1FA890', '#188572', '#1FA68F'], // mid-light: both corrected
    ['#F5D000', '#897400', '#AB9200'], // very light yellow: darkened, hue kept
    ['#FFFF00', '#7A7A00', '#999900'], // pure yellow
    ['#F0F0F0', '#767676', '#929292'], // very light neutral: darkened grey
  ])('corrects accent %s to title %s and rule %s on white', (accent, text, rule) => {
    expect(posterAccent(accent)).toEqual({ text, rule })
  })

  it('never falls back to ink for a valid accent, and keeps both roles on-contrast', () => {
    for (const accent of ['#FFFF00', '#F0F0F0', '#3A9A5B', '#7FB3FF', '#FF0000', '#101010']) {
      const result = posterAccent(accent)
      expect(result).not.toBeNull()
      expect(result?.text).not.toBe(INK)
      expect(result && contrastRatio(result.text, WHITE)).toBeGreaterThanOrEqual(4.5)
      expect(result && contrastRatio(result.rule, WHITE)).toBeGreaterThanOrEqual(3)
    }
  })

  it('returns null without a valid accent, and never a non-hex value', () => {
    expect(posterAccent(null)).toBeNull()
    expect(posterAccent('')).toBeNull()
    expect(posterAccent('red; background:url(x)')).toBeNull()
  })
})

/** Dark page ground, read from the generated DS dark theme (the same value the app paints). */
const DARK_CANVAS = tokensDark['semantic.surface.canvas'] ?? ''

describe('board accent on the dark ground (P-26 dark mode)', () => {
  it('the dark ground is the DS dark canvas, not the light ink default', () => {
    expect(DARK_CANVAS).toMatch(/^#[0-9A-F]{6}$/)
  })

  it.each([
    '#312E81',
    '#0B6B4C',
    '#D4503D',
    '#FF8F6B',
    '#7F7F7F',
    '#102F28',
  ])('accent %s reaches 4.5:1 as text and 3:1 as indicator on the dark ground', (accent) => {
    const { vars } = brandThemeVars(accent, null, 'dark')
    expect(
      contrastRatio(vars['--color-vp-accent-strong'] ?? '', DARK_CANVAS),
    ).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(vars['--color-vp-accent'] ?? '', DARK_CANVAS)).toBeGreaterThanOrEqual(3)
  })

  it('a dark accent is lightened for the dark ground; a light accent is left alone', () => {
    const dark = brandThemeVars('#312E81', null, 'dark')
    expect(dark.corrections).toContain('accent-text')
    const bright = brandThemeVars('#FF8F6B', null, 'dark')
    expect(bright.vars['--color-vp-accent-strong']).toBe('#FF8F6B')
    expect(bright.corrections).not.toContain('accent-text')
  })

  it('the accent tint on the dark ground is a dark wash, not a pale one', () => {
    const style = brandingStyle({ primary_color: '#312E81' }, 'dark') as Record<string, string>
    const tint = String(style['--color-vp-accent-soft'])
    expect(contrastRatio(tint, DARK_CANVAS)).toBeLessThan(2)
    const light = brandingStyle({ primary_color: '#312E81' }) as Record<string, string>
    expect(contrastRatio(String(light['--color-vp-accent-soft']), '#FFFFFF')).toBeLessThan(1.5)
  })

  it('the light surface default is unchanged', () => {
    const plain = brandThemeVars('#312E81', null)
    expect(plain.vars).toEqual(brandThemeVars('#312E81', null, 'light').vars)
  })
})

describe('accent CTA fill carries its own text (D-W45-1)', () => {
  const fillOf = (accent: string, surface: 'light' | 'dark') => {
    const vars = brandThemeVars(accent, null, surface).vars
    return {
      fill: vars['--color-vp-accent-fill'] ?? '',
      text: vars['--color-vp-on-accent-fill'] ?? '',
    }
  }

  // Vectors: very light, very dark, mid tones, the demo-product indigo, and the dark-ground
  // case that measured 4.17-4.40:1 with the old ink text (the fill was accent-strong).
  it.each([
    ['#FFFF00', 'light'],
    ['#FFFF00', 'dark'],
    ['#F5D000', 'light'],
    ['#F5D000', 'dark'],
    ['#FFD166', 'dark'],
    ['#101010', 'light'],
    ['#101010', 'dark'],
    ['#3A9A5B', 'light'],
    ['#3A9A5B', 'dark'],
    ['#7F7F7F', 'dark'],
    ['#4F46E5', 'light'],
    ['#4F46E5', 'dark'],
    ['#312E81', 'dark'],
    ['#1FA890', 'light'],
    ['#1FA890', 'dark'],
  ] as const)('accent %s on the %s surface: fill text reaches 4.5:1', (accent, surface) => {
    const { fill, text } = fillOf(accent, surface)
    expect([WHITE, INK]).toContain(text)
    expect(contrastRatio(text, fill)).toBeGreaterThanOrEqual(4.5)
  })

  it('the demo-product indigo fill on the dark ground picks the better text and reaches 4.5:1', () => {
    const { fill, text } = fillOf('#4F46E5', 'dark')
    const best = Math.max(contrastRatio(WHITE, fill), contrastRatio(INK, fill))
    expect(contrastRatio(text, fill)).toBeCloseTo(best, 10)
    expect(best).toBeGreaterThanOrEqual(4.5)
  })

  it('an accent that already passes keeps its colour as the fill (no visual change)', () => {
    const { vars, corrections } = brandThemeVars('#0B6B4C', null)
    expect(vars['--color-vp-accent-fill']).toBe(vars['--color-vp-accent-strong'])
    expect(corrections).toEqual([])
  })

  it('property: every valid accent on a 16-level RGB grid reaches 4.5:1 on both surfaces', () => {
    const levels = Array.from({ length: 16 }, (_, i) => i * 17)
    const hex = (r: number, g: number, b: number) =>
      `#${[r, g, b]
        .map((c) => c.toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()}`
    for (const surface of ['light', 'dark'] as const) {
      for (const r of levels)
        for (const g of levels)
          for (const b of levels) {
            const accent = hex(r, g, b)
            const { fill, text } = fillOf(accent, surface)
            if (contrastRatio(text, fill) < 4.5) {
              throw new Error(`${accent} on ${surface}: ${text} on ${fill} below 4.5:1`)
            }
          }
    }
  })
})

describe('tag chip text reaches 4.5:1 on its ground in both themes', () => {
  // A tag chip has no background: its text sits on the card or the canvas, and the pressed
  // chip paints a 16% wash of the text colour over that ground. Both must reach 4.5:1.
  const LIGHT_GROUNDS = [WHITE, PORCELAIN, COOL_CANVAS_HEX]
  const DARK_GROUNDS = [tokensDark['semantic.surface.card'] ?? '', DARK_CANVAS]
  const grounds = (surface: 'light' | 'dark') => (surface === 'dark' ? DARK_GROUNDS : LIGHT_GROUNDS)
  const worstContrast = (text: string, surface: 'light' | 'dark') =>
    Math.min(
      ...grounds(surface).flatMap((ground) => [
        contrastRatio(text, ground),
        contrastRatio(text, mixHex(ground, text, 0.16)),
      ]),
    )

  it.each([
    ['#FFFF00', 'light'],
    ['#FFFF00', 'dark'],
    ['#F5D000', 'light'],
    ['#F5D000', 'dark'],
    ['#FF8F6B', 'dark'],
    ['#7FB3FF', 'light'],
    ['#101010', 'light'],
    ['#101010', 'dark'],
    ['#0B6B4C', 'light'],
    ['#4F46E5', 'light'],
    ['#4F46E5', 'dark'],
  ] as const)('tag colour %s on the %s theme: text reaches 4.5:1 on card, canvas and wash', (hex, surface) => {
    expect(worstContrast(tagTextColor(hex, surface), surface)).toBeGreaterThanOrEqual(4.5)
  })

  it('a tag colour that already passes keeps its colour as text', () => {
    expect(tagTextColor('#0B6B4C', 'light')).toBe('#0B6B4C')
  })

  it('a light tag is darkened for the light theme and keeps its hue', () => {
    const text = tagTextColor('#FFFF00', 'light')
    expect(text).not.toBe('#FFFF00')
    expect(Math.abs(hexToHsl(text).h - hexToHsl('#FFFF00').h)).toBeLessThan(3)
  })

  it('property: every tag colour on a 16-level RGB grid reaches 4.5:1 in both themes', () => {
    const levels = Array.from({ length: 16 }, (_, i) => i * 17)
    const hex = (r: number, g: number, b: number) =>
      `#${[r, g, b]
        .map((c) => c.toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()}`
    for (const surface of ['light', 'dark'] as const)
      for (const r of levels)
        for (const g of levels)
          for (const b of levels) {
            const color = hex(r, g, b)
            const text = tagTextColor(color, surface)
            if (worstContrast(text, surface) < 4.5) {
              throw new Error(`${color} on ${surface}: text ${text} below 4.5:1`)
            }
          }
  })
})

/** v0.1.5 role split (owner decision 2026-10-27): primary = action, accent = highlight, secondary = surface. */
describe('v0.1.5 role split, per-mode values and the logo plate', () => {
  const lower = (value: string | undefined) => value?.toLowerCase()
  const CANVAS = tokensDark['semantic.surface.canvas'] ?? '#0E1412'

  it('never sets a title colour: titles stay on the neutral text token', () => {
    const { vars } = brandThemeVars('#005F73', '#0B122A', 'light')
    expect(vars).not.toHaveProperty('--color-vp-title')
  })

  it('the secondary colour is a surface on the dark theme too: it is not lightened into text', () => {
    const { vars } = brandThemeVars(null, '#0B122A', 'dark')
    expect(lower(vars['--color-vp-ink'])).toBe('#0b122a')
    expect(vars).not.toHaveProperty('--color-vp-accent-strong')
  })

  it('a secondary colour alone never sets highlight or action text', () => {
    const { vars } = brandThemeVars(null, '#0B122A', 'light')
    expect(vars).not.toHaveProperty('--color-vp-accent')
    expect(vars).not.toHaveProperty('--color-vp-action-text')
    expect(vars).not.toHaveProperty('--vpds-c-button-accent-background')
  })

  it('the CTA (DS accent button) takes the primary colour, with a matching text colour', () => {
    const { vars } = brandThemeVars('#005F73', null, 'light')
    expect(vars['--vpds-c-button-accent-background']).toBe(vars['--color-vp-accent-fill'])
    expect(vars['--vpds-c-button-accent-foreground']).toBe(vars['--color-vp-on-accent-fill'])
    expect(vars).toHaveProperty('--vpds-c-button-accent-background-hover')
  })

  it('without a primary colour the DS accent button keeps its own colour', () => {
    const { vars } = brandThemeVars(null, '#0B122A', 'light')
    expect(vars).not.toHaveProperty('--vpds-c-button-accent-background')
  })

  it('an accent colour drives the highlights, not the primary colour', () => {
    const { vars } = brandThemeVars('#005F73', null, 'light', { accent: '#FFB547' })
    expect(lower(vars['--color-vp-accent-soft'])).toBe(lower(mixHex('#FFB547', WHITE, 0.88)))
    // The CTA stays on the primary colour.
    expect(vars['--vpds-c-button-accent-background']).toBe(vars['--color-vp-accent-fill'])
  })

  it('without an accent the highlights follow the primary colour (today’s behaviour)', () => {
    const withoutAccent = brandThemeVars('#005F73', null, 'light').vars
    const emptyAccent = brandThemeVars('#005F73', null, 'light', { accent: 'not a colour' }).vars
    expect(emptyAccent).toEqual(withoutAccent)
  })

  it('the dark theme uses the per-mode primary for the CTA when one is set', () => {
    const { vars } = brandThemeVars('#005F73', null, 'dark', { primaryDark: '#6FDDF0' })
    expect(lower(vars['--vp-primary'])).toBe('#6fddf0')
  })

  it('the dark theme derives the primary from the light value when no per-mode value is set', () => {
    const { vars } = brandThemeVars('#005F73', null, 'dark')
    expect(lower(vars['--vp-primary'])).toBe('#005f73')
  })

  it('a per-mode accent is used on the dark theme only', () => {
    const light = brandThemeVars(null, null, 'light', {
      accent: '#FFB547',
      accentDark: '#FFC870',
    }).vars
    const dark = brandThemeVars(null, null, 'dark', {
      accent: '#FFB547',
      accentDark: '#FFC870',
    }).vars
    expect(lower(light['--color-vp-accent-soft'])).toBe(lower(mixHex('#FFB547', WHITE, 0.88)))
    expect(lower(dark['--color-vp-accent-soft'])).toBe(lower(mixHex('#FFC870', CANVAS, 0.85)))
  })

  it('the top bar active rule is the accent when readable on the bar, the focus is the primary colour', () => {
    const vars = topbarBrandVars('#005F73', '#0B122A', '#FFB547')
    expect(lower(vars['--vpds-c-topbar-accent'])).toBe('#ffb547')
  })

  it('coverAccentFor picks the per-mode accent on the dark theme and the accent otherwise', () => {
    const board = {
      primary_color: '#005F73',
      accent_color: '#FFB547',
      accent_color_dark: '#FFC870',
    }
    expect(lower(coverAccentFor(board, false) ?? undefined)).toBe('#ffb547')
    expect(lower(coverAccentFor(board, true) ?? undefined)).toBe('#ffc870')
    expect(lower(coverAccentFor({ primary_color: '#005F73' }, false) ?? undefined)).toBe('#005f73')
    expect(coverAccentFor({}, true)).toBeNull()
  })

  it('a board without a plate choice and without a bar colour gets no logo plate variables', () => {
    expect(logoPlateVars(null, 'auto', false)).toEqual({})
  })

  it('the logo plate follows the bar colour (auto and brand), white is white, and the ring can be hidden', () => {
    expect(logoPlateVars('#0B122A', 'auto', false)).toEqual({
      '--vpds-c-topbar-logo-plate': '#0B122A',
      '--vpds-c-board-logo-plate': '#0B122A',
      '--vpds-c-topbar-logo-ring': '0 0 0 1px var(--vpds-c-topbar-rule, transparent)',
      '--vpds-c-board-logo-ring': '0 0 0 1px var(--vpds-surface-canvas)',
    })
    expect(logoPlateVars('#0B122A', 'brand', true)['--vpds-c-topbar-logo-ring']).toBe('none')
    expect(logoPlateVars('#0B122A', 'white', false)['--vpds-c-topbar-logo-plate']).toBe(WHITE)
  })

  it('none is a transparent plate without a ring, so the round logo corners show the page', () => {
    expect(logoPlateVars('#0B122A', 'none', false)).toEqual({
      '--vpds-c-topbar-logo-plate': 'transparent',
      '--vpds-c-board-logo-plate': 'transparent',
      '--vpds-c-topbar-logo-ring': 'none',
      '--vpds-c-board-logo-ring': 'none',
    })
  })

  it('the hidden ring reaches the round board logo too', () => {
    expect(logoPlateVars('#0B122A', 'white', true)['--vpds-c-board-logo-ring']).toBe('none')
    expect(logoPlateVars(null, 'auto', true)).toEqual({
      '--vpds-c-topbar-logo-ring': 'none',
      '--vpds-c-board-logo-ring': 'none',
    })
  })

  it('auto turns white when the logo ink would vanish on the bar, brand and white never look at the ink', () => {
    const inkOfBar = 0.007 // mean luminance of a logo drawn in the bar colour itself
    expect(logoPlateVars('#0B122A', 'auto', false, inkOfBar)['--vpds-c-board-logo-plate']).toBe(
      WHITE,
    )
    expect(logoPlateVars('#0B122A', 'auto', false, 0.178)['--vpds-c-board-logo-plate']).toBe(
      '#0B122A',
    )
    expect(logoPlateVars('#0B122A', 'brand', false, inkOfBar)['--vpds-c-board-logo-plate']).toBe(
      '#0B122A',
    )
    expect(logoPlateVars('#0B122A', 'white', false, 0.9)['--vpds-c-board-logo-plate']).toBe(WHITE)
  })

  it('brandingStyle reads the logo ink from the board payload for the auto plate', () => {
    const board = { primary_color: '#005F73', secondary_color: '#0B122A', logo_plate: 'auto' }
    const bar = expect.stringMatching(/^#0b122a$/i)
    expect(brandingStyle({ ...board, asset_ink: { logo: 0.007 } })).toHaveProperty(
      '--vpds-c-board-logo-plate',
      WHITE,
    )
    expect(brandingStyle({ ...board, asset_ink: { logo: 0.178 } })).toHaveProperty(
      '--vpds-c-board-logo-plate',
      bar,
    )
    expect(brandingStyle(board)).toHaveProperty('--vpds-c-board-logo-plate', bar)
  })

  it('pinned edge and vote highlights follow the accent only; without one the defaults stay', () => {
    const without = brandThemeVars('#005F73', '#0B122A').vars
    expect(without).not.toHaveProperty('--vpds-c-idea-pinned-edge')
    expect(without).not.toHaveProperty('--vpds-c-vote-control-up-selected-background')
    expect(without).not.toHaveProperty('--vpds-c-vote-control-field-up-background')

    const withAccent = brandThemeVars('#005F73', '#0B122A', 'light', { accent: '#FFB547' }).vars
    expect(lower(withAccent['--vpds-c-idea-pinned-edge'])).toBe(
      lower(withAccent['--color-vp-accent']),
    )
    expect(withAccent['--vpds-c-vote-control-selected-ring-up']).toBe(
      withAccent['--vpds-c-vote-control-up-selected-background'],
    )
    expect(
      contrastRatio(
        withAccent['--vpds-c-vote-control-up-selected-foreground'] ?? '',
        withAccent['--vpds-c-vote-control-up-selected-background'] ?? '',
      ),
    ).toBeGreaterThanOrEqual(4.5)
    expect(withAccent).toHaveProperty('--vpds-c-vote-control-field-up-background')
    expect(withAccent).toHaveProperty('--vpds-c-vote-control-up-selected-hover')
  })

  it('brandingStyle carries the accent and the logo plate variables of the board', () => {
    const style = brandingStyle({
      primary_color: '#005F73',
      secondary_color: '#0B122A',
      accent_color: '#FFB547',
      logo_plate: 'white',
      logo_ring_hidden: true,
    })
    expect(style).toHaveProperty('--color-vp-accent-soft')
    expect(style).toHaveProperty('--vpds-c-button-accent-background')
    expect(style).toHaveProperty('--vpds-c-topbar-logo-plate', WHITE)
    expect(style).toHaveProperty('--vpds-c-topbar-logo-ring', 'none')
  })
})

/** The light canvas a board's pages paint with (dogfood-branding #11): a cool brand gets a cool grey. */
const CANVAS_VAR_KEYS = [
  '--vpds-surface-canvas',
  '--vp-bg',
  '--color-vp-bg',
  '--color-vp-surface-frost',
  '--vpds-surface-sunken',
  '--color-vp-surface-sunken',
  '--vpds-action-secondary-background-hover',
  '--vpds-action-on-inverse-background-hover',
]

describe('isCoolBrand: the cool hue window with a chroma floor', () => {
  it.each([
    ['the Gletscher primary', GLETSCHER.primary],
    ['the Gletscher secondary, as a primary', GLETSCHER.secondary],
    ['a teal at 170°', hslToHex(170, 70, 35)],
    ['an indigo at 270°', hslToHex(270, 60, 45)],
    ['a pale cool blue', '#A8C8E8'],
  ])('%s is cool', (_name, hex) => {
    expect(isCoolBrand(hex)).toBe(true)
  })

  it.each([
    ['the warm orange reference', '#C2410C'],
    ['the Gletscher accent', GLETSCHER.accent],
    ['a green at 150°', hslToHex(150, 70, 35)],
    ['a magenta at 290°', hslToHex(290, 70, 45)],
    ['a mid grey', '#808080'],
    ['a slate grey (chroma under the floor)', '#6B7280'],
    ['a barely tinted blue-grey', hslToHex(200, 8, 50)],
    ['white', WHITE],
    ['black', '#000000'],
  ])('%s is not cool', (_name, hex) => {
    expect(isCoolBrand(hex)).toBe(false)
  })
})

describe('lightCanvasFor: the rule for the board canvas', () => {
  it('a cool primary gets the cool canvas, whatever the secondary colour', () => {
    expect(lightCanvasFor(GLETSCHER.primary, GLETSCHER.secondary)).toBe(COOL_CANVAS_HEX)
    expect(lightCanvasFor(GLETSCHER.primary, null)).toBe(COOL_CANVAS_HEX)
  })

  it('the primary decides when it is present: a warm primary keeps porcelain beside a cool secondary', () => {
    expect(lightCanvasFor('#C2410C', GLETSCHER.secondary)).toBe(PORCELAIN)
  })

  it('a grey primary keeps porcelain, even beside a cool secondary', () => {
    expect(lightCanvasFor('#808080', GLETSCHER.secondary)).toBe(PORCELAIN)
  })

  it('without a valid primary the secondary colour decides', () => {
    expect(lightCanvasFor('not-a-colour', GLETSCHER.secondary)).toBe(COOL_CANVAS_HEX)
    expect(lightCanvasFor(null, GLETSCHER.secondary)).toBe(COOL_CANVAS_HEX)
    expect(lightCanvasFor(null, '#C2410C')).toBe(PORCELAIN)
  })

  it('an unset board keeps porcelain', () => {
    expect(lightCanvasFor(null, null)).toBe(PORCELAIN)
    expect(lightCanvasFor(undefined, undefined)).toBe(PORCELAIN)
  })

  it('boardCanvasFor: the dark theme keeps the dark canvas whatever the board colours', () => {
    expect(boardCanvasFor({ primary_color: GLETSCHER.primary }, 'dark')).toBe(
      tokensDark['semantic.surface.canvas'],
    )
    expect(boardCanvasFor({ primary_color: GLETSCHER.primary }, 'light')).toBe(COOL_CANVAS_HEX)
    expect(boardCanvasFor({}, 'light')).toBe(PORCELAIN)
  })

  it('the cool canvas is close in luminance to porcelain, so the contrast maths barely moves', () => {
    expect(
      Math.abs(relativeLuminance(COOL_CANVAS_HEX) - relativeLuminance(PORCELAIN)),
    ).toBeLessThan(0.02)
  })
})

describe('the emitted canvas variables', () => {
  it('a cool light board sets the base token and every alias the pages paint with', () => {
    const { vars } = brandThemeVars(GLETSCHER.primary, GLETSCHER.secondary, 'light')
    expect(vars['--vpds-surface-canvas']).toBe(COOL_CANVAS_HEX)
    expect(vars['--color-vp-bg']).toBe(COOL_CANVAS_HEX)
    expect(vars['--vp-bg']).toBe(COOL_CANVAS_HEX)
    expect(vars['--color-vp-surface-frost']).toBe(COOL_CANVAS_HEX)
    expect(vars['--color-vp-surface-sunken']).toBe(vars['--vpds-surface-sunken'])
    expect(vars['--vpds-action-secondary-background-hover']).toBe(COOL_CANVAS_HEX)
    for (const key of CANVAS_VAR_KEYS) expect(vars).toHaveProperty(key)
  })

  it('reaches the board wrapper through brandingStyle, so the page and its components inherit it', () => {
    const style = brandingStyle(
      { primary_color: GLETSCHER.primary, secondary_color: GLETSCHER.secondary },
      'light',
    ) as Record<string, string>
    expect(style['--vpds-surface-canvas']).toBe(COOL_CANVAS_HEX)
    expect(style['--color-vp-bg']).toBe(COOL_CANVAS_HEX)
  })

  it('a warm board sets none of the canvas variables: its page is exactly as before', () => {
    const style = brandingStyle(
      { primary_color: '#C2410C', secondary_color: GLETSCHER.secondary },
      'light',
    ) as Record<string, string>
    for (const key of CANVAS_VAR_KEYS) expect(style).not.toHaveProperty(key)
  })

  it('an unset board sets none of the canvas variables', () => {
    const style = brandingStyle({}, 'light') as Record<string, string>
    for (const key of CANVAS_VAR_KEYS) expect(style).not.toHaveProperty(key)
  })

  it('the dark theme never takes the cool canvas', () => {
    const style = brandingStyle(
      { primary_color: GLETSCHER.primary, secondary_color: GLETSCHER.secondary },
      'dark',
    ) as Record<string, string>
    for (const key of CANVAS_VAR_KEYS) expect(style).not.toHaveProperty(key)
  })
})

describe('contrast on the cool canvas is re-checked, not assumed', () => {
  it('the accent text and indicator of a cool board reach their thresholds on the cool canvas', () => {
    const { vars } = brandThemeVars(GLETSCHER.primary, null, 'light')
    expect(
      contrastRatio(vars['--color-vp-accent-strong'] ?? '', COOL_CANVAS_HEX),
    ).toBeGreaterThanOrEqual(4.5)
    expect(contrastRatio(vars['--color-vp-accent'] ?? '', COOL_CANVAS_HEX)).toBeGreaterThanOrEqual(
      3,
    )
  })

  it('the design-system light text tokens read at least as well on the cool canvas as on porcelain', () => {
    for (const name of [
      'semantic.text.primary',
      'semantic.text.secondary',
      'semantic.text.link',
    ] as const) {
      const text = tokens[name]?.value ?? ''
      expect(text).toMatch(/^#/)
      expect(contrastRatio(text, COOL_CANVAS_HEX)).toBeGreaterThanOrEqual(
        contrastRatio(text, PORCELAIN),
      )
      if (contrastRatio(text, PORCELAIN) >= 4.5) {
        expect(contrastRatio(text, COOL_CANVAS_HEX)).toBeGreaterThanOrEqual(4.5)
      }
    }
  })

  it('property: every cool primary on a 16-level RGB grid gets accent text 4.5:1 and indicator 3:1 on the cool canvas', () => {
    const levels = Array.from({ length: 16 }, (_, i) => i * 17)
    const hex = (r: number, g: number, b: number) =>
      `#${[r, g, b]
        .map((c) => c.toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()}`
    let checked = 0
    for (const r of levels)
      for (const g of levels)
        for (const b of levels) {
          const primary = hex(r, g, b)
          if (!isCoolBrand(primary)) continue
          checked += 1
          const { vars } = brandThemeVars(primary, null, 'light')
          const text = contrastRatio(vars['--color-vp-accent-strong'] ?? '', COOL_CANVAS_HEX)
          const indicator = contrastRatio(vars['--color-vp-accent'] ?? '', COOL_CANVAS_HEX)
          if (text < 4.5 || indicator < 3) {
            throw new Error(`${primary} on the cool canvas: text ${text}, indicator ${indicator}`)
          }
        }
    expect(checked).toBeGreaterThan(100)
  })
})
