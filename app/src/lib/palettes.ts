/**
 * Colour palettes for the branding editor. A palette is five colours: primary, secondary,
 * accent and the two per-mode values (primaryDark, accentDark). Curated palettes and generated
 * ones pass the same rules the app enforces on a board, so one click or one shuffle cannot write
 * an illegible colour or one the server rejects:
 *
 * - server (BrandingValidator): primary, darkened to 70% on white, reaches 4.5:1 on white
 *   (primaryColor); secondary reaches 4.5:1 on white (secondaryColor);
 * - client (brandThemeVars, the engine behind the preview and ColorField's auto-fix): the theme
 *   engine corrects nothing on either surface. On the light grounds (porcelain and the cool
 *   canvas) primary and accent reach 4.5:1 as text; on the dark canvas primaryDark and accentDark
 *   reach 4.5:1 as text (the accent-indicator rule is implied by the 4.5:1 text rule).
 *
 * Pure: no React, no network. The random source is injected so the generator can be tested.
 */

import {
  adjustLightnessUntil,
  contrastRatio,
  hslToHex,
  isHexColor,
  PORCELAIN_HEX,
  WHITE_HEX,
} from '@votepit/ui/ds'
import { brandThemeVars, COOL_CANVAS_HEX, DARK_GROUND } from './branding'

export const PALETTE_FIELDS = [
  'primary',
  'secondary',
  'accent',
  'primaryDark',
  'accentDark',
] as const
export type PaletteField = (typeof PALETTE_FIELDS)[number]
/** Hex values; '' means unset. */
export type PaletteValues = Record<PaletteField, string>

/** Which fields the plan lets this board set. Primary is always set. */
export interface PaletteAllowance {
  secondary: boolean
  accent: boolean
  /** Per-mode values: primaryDark and accentDark together (canSetPerMode). */
  perMode: boolean
}

/** Colours kept while shuffling. Each one is only offered where the plan allows the field. */
export interface PaletteLocks {
  primary: boolean
  secondary: boolean
  accent: boolean
}

export interface Palette {
  id: string
  values: PaletteValues
}

/** WCAG 1.4.3 threshold for text. */
const TEXT_MIN_CONTRAST = 4.5
/** Attempts at a shuffle before it gives up (only locked colours can make a try fail). */
const RANDOM_ATTEMPTS = 40

export const CURATED_PALETTES: readonly Palette[] = [
  {
    id: 'glacier',
    values: {
      primary: '#2A6FB0',
      secondary: '#14233B',
      accent: '#1E7891',
      primaryDark: '#7CB8FF',
      accentDark: '#6FD8F0',
    },
  },
  {
    id: 'forest',
    values: {
      primary: '#2E7D4F',
      secondary: '#15271D',
      accent: '#64752A',
      primaryDark: '#6FD09A',
      accentDark: '#C4E26B',
    },
  },
  {
    id: 'coral',
    values: {
      primary: '#C0452F',
      secondary: '#2B1C1A',
      accent: '#A95B0D',
      primaryDark: '#FF9B86',
      accentDark: '#FFC27A',
    },
  },
  {
    id: 'graphite',
    values: {
      primary: '#4A5361',
      secondary: '#1B1E24',
      accent: '#93651F',
      primaryDark: '#A9B4C4',
      accentDark: '#F0B45A',
    },
  },
  {
    id: 'sunset',
    values: {
      primary: '#C23B6B',
      secondary: '#2A1530',
      accent: '#BE4A16',
      primaryDark: '#FF8FB6',
      accentDark: '#FFB070',
    },
  },
  {
    id: 'lavender',
    values: {
      primary: '#6A4BC4',
      secondary: '#22183D',
      accent: '#A443C1',
      primaryDark: '#B7A2FF',
      accentDark: '#E3A8FF',
    },
  },
  {
    id: 'sand',
    values: {
      primary: '#89693E',
      secondary: '#2E2418',
      accent: '#8E6723',
      primaryDark: '#E0C08C',
      accentDark: '#F2C76B',
    },
  },
  {
    id: 'berry',
    values: {
      primary: '#9C2A5C',
      secondary: '#2D1222',
      accent: '#CA3268',
      primaryDark: '#FF8EB8',
      accentDark: '#FF9CC6',
    },
  },
  {
    id: 'moss',
    values: {
      primary: '#5B6B2A',
      secondary: '#20260F',
      accent: '#687428',
      primaryDark: '#C2D57A',
      accentDark: '#D7E88A',
    },
  },
  {
    id: 'indigo',
    values: {
      primary: '#3B3FA8',
      secondary: '#161838',
      accent: '#2265E9',
      primaryDark: '#A5AAFF',
      accentDark: '#8EB8FF',
    },
  },
  {
    id: 'citrus',
    values: {
      primary: '#4D7A12',
      secondary: '#1E2A10',
      accent: '#866C00',
      primaryDark: '#B9E05A',
      accentDark: '#FFE066',
    },
  },
  {
    id: 'ember',
    values: {
      primary: '#B8371C',
      secondary: '#2A1410',
      accent: '#A65A17',
      primaryDark: '#FF8A6B',
      accentDark: '#FFB347',
    },
  },
  {
    id: 'teal',
    values: {
      primary: '#0F7C7A',
      secondary: '#0F2A2B',
      accent: '#2B7B70',
      primaryDark: '#5FD6C6',
      accentDark: '#8BE8D8',
    },
  },
]

/** The fields the plan allows, primary first. */
export function allowedFields(allowance: PaletteAllowance): PaletteField[] {
  return [
    'primary',
    ...(allowance.secondary ? (['secondary'] as const) : []),
    ...(allowance.accent ? (['accent'] as const) : []),
    ...(allowance.perMode ? (['primaryDark', 'accentDark'] as const) : []),
  ]
}

/** The values of the allowed fields only; what a palette or a shuffle may write. */
export function pickAllowed(
  values: PaletteValues,
  allowance: PaletteAllowance,
): Partial<PaletteValues> {
  const out: Partial<PaletteValues> = {}
  for (const field of allowedFields(allowance)) out[field] = values[field]
  return out
}

/** True when every allowed field already holds the palette's colour. */
export function isPaletteApplied(
  palette: Palette,
  current: PaletteValues,
  allowance: PaletteAllowance,
): boolean {
  return allowedFields(allowance).every(
    (field) => current[field].toUpperCase() === palette.values[field].toUpperCase(),
  )
}

/** BrandingValidator::primaryColor: the colour darkened to 70% (per channel) must reach 4.5:1 on white. */
export function serverPrimaryOk(hex: string): boolean {
  const darkened = [1, 3, 5].map((i) => {
    const channel = Number.parseInt(hex.slice(i, i + 2), 16)
    return Math.round(channel * 0.7)
      .toString(16)
      .padStart(2, '0')
  })
  return contrastRatio(`#${darkened.join('')}`, WHITE_HEX) >= TEXT_MIN_CONTRAST
}

const onWhite = (hex: string): boolean => contrastRatio(hex, WHITE_HEX) >= TEXT_MIN_CONTRAST
const onLightGrounds = (hex: string): boolean =>
  [PORCELAIN_HEX, COOL_CANVAS_HEX].every(
    (ground) => contrastRatio(hex, ground) >= TEXT_MIN_CONTRAST,
  )
const onDarkGround = (hex: string): boolean => contrastRatio(hex, DARK_GROUND) >= TEXT_MIN_CONTRAST

/**
 * Every rule a palette value has to pass, with the direction its lightness moves when it fails.
 * Hue and saturation are kept; only the lightness changes, as in the theme engine.
 */
const REPAIR: Record<
  PaletteField,
  { passes: (hex: string) => boolean; towards: 'lighter' | 'darker' }
> = {
  primary: { passes: (hex) => onLightGrounds(hex) && serverPrimaryOk(hex), towards: 'darker' },
  secondary: { passes: onWhite, towards: 'darker' },
  accent: { passes: onLightGrounds, towards: 'darker' },
  primaryDark: { passes: onDarkGround, towards: 'lighter' },
  accentDark: { passes: onDarkGround, towards: 'lighter' },
}

/** Moves each value in lightness until it passes its rule. */
export function repairPalette(values: PaletteValues): PaletteValues {
  const out = { ...values }
  for (const field of PALETTE_FIELDS) {
    const { passes, towards } = REPAIR[field]
    out[field] = adjustLightnessUntil(values[field], passes, towards)
  }
  return out
}

/**
 * The rule violations of a full palette, as short labels (empty when it is clean). Checks the
 * format, the server rules and the theme engine's corrections on the light and the dark ground.
 */
export function paletteProblems(values: PaletteValues): string[] {
  const problems: string[] = []
  for (const field of PALETTE_FIELDS) {
    const value = values[field]
    if (value !== '' && !isHexColor(value)) problems.push(`format:${field}`)
  }
  if (values.primary !== '' && !serverPrimaryOk(values.primary)) problems.push('server:primary')
  if (values.secondary !== '' && !onWhite(values.secondary)) problems.push('server:secondary')
  const highlights = {
    accent: values.accent,
    primaryDark: values.primaryDark,
    accentDark: values.accentDark,
  }
  for (const surface of ['light', 'dark'] as const) {
    const { corrections } = brandThemeVars(values.primary, values.secondary, surface, highlights)
    for (const correction of corrections) problems.push(`${surface}:${correction}`)
  }
  return problems
}

/** Harmonies: the hue offsets of primary, accent and secondary from a base hue. */
const HARMONIES: readonly { primary: number; accent: number; secondary: number }[] = [
  { primary: 0, accent: 180, secondary: 0 },
  { primary: 0, accent: 35, secondary: -25 },
  { primary: 0, accent: 120, secondary: 240 },
  { primary: 0, accent: 150, secondary: 210 },
]

/**
 * One harmonious palette from colour-theory rules (complementary, analogous, triadic or
 * split-complementary hues in HSL), repaired so that every value passes its rule.
 */
export function generatePalette(random: () => number): PaletteValues {
  const base = random() * 360
  const harmony = HARMONIES[Math.floor(random() * HARMONIES.length)] ?? HARMONIES[0]
  const hue = (offset: number) => (((base + offset) % 360) + 360) % 360
  const range = (min: number, span: number) => min + random() * span
  const primaryHue = hue(harmony?.primary ?? 0)
  const accentHue = hue(harmony?.accent ?? 0)
  const secondaryHue = hue(harmony?.secondary ?? 0)
  return repairPalette({
    primary: hslToHex(primaryHue, range(45, 35), range(30, 12)),
    secondary: hslToHex(secondaryHue, range(10, 30), range(12, 12)),
    accent: hslToHex(accentHue, range(60, 30), range(35, 15)),
    primaryDark: hslToHex(primaryHue, range(55, 30), range(60, 12)),
    accentDark: hslToHex(accentHue, range(60, 30), range(62, 14)),
  })
}

/**
 * A shuffled palette that keeps the locked colours, or null when no attempt yields a clean
 * palette (a locked colour can conflict with the new ones). A locked primary keeps its per-mode
 * value too, a locked accent its accentDark; a lock only counts where the plan allows the field.
 * The result is the full palette: callers write pickAllowed(result, allowance).
 */
export function randomPalette(
  current: PaletteValues,
  locks: PaletteLocks,
  allowance: PaletteAllowance,
  random: () => number = Math.random,
): PaletteValues | null {
  const keep: Record<PaletteField, boolean> = {
    primary: locks.primary,
    secondary: locks.secondary && allowance.secondary,
    accent: locks.accent && allowance.accent,
    primaryDark: locks.primary && allowance.perMode,
    accentDark: locks.accent && allowance.perMode,
  }
  for (let attempt = 0; attempt < RANDOM_ATTEMPTS; attempt += 1) {
    const candidate = generatePalette(random)
    const merged = { ...candidate }
    for (const field of PALETTE_FIELDS) if (keep[field]) merged[field] = current[field]
    if (paletteProblems(merged).length === 0) return merged
  }
  return null
}
