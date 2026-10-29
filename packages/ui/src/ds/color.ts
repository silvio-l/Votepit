/**
 * Colour helpers for generated board art and avatar initials. Pure module (no React),
 * so the Astro pages can use it too. Only validated six-digit hex values are accepted;
 * anything else falls back to the caller's default.
 */

/**
 * Token values mirrored here so contrast maths matches the CSS (tokens.generated.css). Computation constants,
 * not styling: they are the grounds the contrast checks are run against.
 */
export const INK_HEX = '#151918'
export const PORCELAIN_HEX = '#F4F3EC'
export const WHITE_HEX = '#FFFFFF'

const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/

/** True for a six-digit hex colour such as "#0B6B4C". */
export function isHexColor(value: unknown): value is string {
  return typeof value === 'string' && HEX_PATTERN.test(value)
}

/** The given colour when valid, otherwise the fallback. */
export function validHexOr(value: unknown, fallback: string): string {
  return isHexColor(value) ? value.toUpperCase() : fallback
}

function channels(hex: string): [number, number, number] {
  const n = Number.parseInt(hex.slice(1), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}

function toHex(parts: number[]): string {
  return `#${parts
    .map((p) => Math.round(p).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase()}`
}

/** Linear mix: `amount` 0 returns `from`, 1 returns `to`. Both inputs must be valid hex. */
export function mixHex(from: string, to: string, amount: number): string {
  const a = channels(from)
  const b = channels(to)
  return toHex(a.map((v, i) => v + ((b[i] ?? 0) - v) * amount))
}

function linear(channel: number): number {
  const c = channel / 255
  return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

/** WCAG relative luminance, 0 (black) to 1 (white). */
export function relativeLuminance(hex: string): number {
  const [r, g, b] = channels(hex).map(linear)
  return 0.2126 * (r ?? 0) + 0.7152 * (g ?? 0) + 0.0722 * (b ?? 0)
}

/** WCAG contrast ratio between two colours, 1 to 21. */
export function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)
  const [hi, lo] = la > lb ? [la, lb] : [lb, la]
  return (hi + 0.05) / (lo + 0.05)
}

/**
 * Initials colours on an accent: white on the accent when it reaches 4.5:1,
 * otherwise ink on the accent when that does, otherwise ink on porcelain (the
 * accent is too light for any text colour on its own disc).
 */
export function initialsPaletteFor(accent: string): { background: string; foreground: string } {
  if (contrastRatio(WHITE_HEX, accent) >= 4.5) {
    return { background: accent, foreground: WHITE_HEX }
  }
  if (contrastRatio(INK_HEX, accent) >= 4.5) {
    return { background: accent, foreground: INK_HEX }
  }
  return { background: PORCELAIN_HEX, foreground: INK_HEX }
}

/** HSL of a six-digit hex: hue 0-360, saturation and lightness 0-100. */
export function hexToHsl(hex: string): { h: number; s: number; l: number } {
  const [r0 = 0, g0 = 0, b0 = 0] = channels(hex).map((c) => c / 255)
  const max = Math.max(r0, g0, b0)
  const min = Math.min(r0, g0, b0)
  const l = (max + min) / 2
  const d = max - min
  if (d === 0) return { h: 0, s: 0, l: l * 100 }
  const s = d / (1 - Math.abs(2 * l - 1))
  let h: number
  if (max === r0) h = ((g0 - b0) / d) % 6
  else if (max === g0) h = (b0 - r0) / d + 2
  else h = (r0 - g0) / d + 4
  return { h: (h * 60 + 360) % 360, s: s * 100, l: l * 100 }
}

/** Six-digit uppercase hex from HSL (hue 0-360, saturation and lightness 0-100). */
export function hslToHex(h: number, s: number, l: number): string {
  const sat = Math.min(100, Math.max(0, s)) / 100
  const lig = Math.min(100, Math.max(0, l)) / 100
  const c = (1 - Math.abs(2 * lig - 1)) * sat
  const hp = (((h % 360) + 360) % 360) / 60
  const x = c * (1 - Math.abs((hp % 2) - 1))
  const [r, g, b] =
    hp < 1
      ? [c, x, 0]
      : hp < 2
        ? [x, c, 0]
        : hp < 3
          ? [0, c, x]
          : hp < 4
            ? [0, x, c]
            : hp < 5
              ? [x, 0, c]
              : [c, 0, x]
  const m = lig - c / 2
  return toHex([(r + m) * 255, (g + m) * 255, (b + m) * 255])
}

/**
 * Tonal scale of one accent: ten steps from 50 (lightest) to 900 (darkest),
 * keeping the hue and saturation of the accent. Deterministic; the lightness
 * steps are fixed so the same accent always yields the same scale.
 */
export const TONE_STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as const
export type ToneStep = (typeof TONE_STEPS)[number]

const TONE_LIGHTNESS: Record<ToneStep, number> = {
  50: 97,
  100: 93,
  200: 86,
  300: 76,
  400: 66,
  500: 56,
  600: 46,
  700: 37,
  800: 28,
  900: 18,
}

export function tonalScale(hex: string): Record<ToneStep, string> {
  const { h, s } = hexToHsl(hex)
  const out = {} as Record<ToneStep, string>
  for (const step of TONE_STEPS) out[step] = hslToHex(h, s, TONE_LIGHTNESS[step])
  return out
}

/**
 * Moves `color` in lightness (towards white when `towards` is 'lighter', towards
 * black when 'darker') in 1-point HSL steps until `passes(candidate)` holds. Hue and
 * saturation are kept. Returns the input unchanged when it already passes; the
 * extremes (white/black) are the last candidates.
 */
export function adjustLightnessUntil(
  color: string,
  passes: (candidate: string) => boolean,
  towards: 'lighter' | 'darker',
): string {
  if (passes(color)) return color
  const { h, s, l } = hexToHsl(color)
  const target = towards === 'lighter' ? 100 : 0
  for (let step = 1; step <= 100; step += 1) {
    const lightness = l + ((target - l) * step) / 100
    const candidate = hslToHex(h, s, lightness)
    if (passes(candidate)) return candidate
  }
  return towards === 'lighter' ? WHITE_HEX : INK_HEX
}

/**
 * Moves `color` in lightness (towards white when `towards` is 'lighter', towards
 * black when 'darker') in 1-point HSL steps until `contrastRatio(color, background)`
 * reaches `minRatio`. Hue and saturation are kept. Returns the input unchanged
 * when it already passes; the extremes (white/black) are the last candidates.
 */
export function adjustLightnessForContrast(
  color: string,
  background: string,
  minRatio: number,
  towards: 'lighter' | 'darker',
): string {
  return adjustLightnessUntil(
    color,
    (candidate) => contrastRatio(candidate, background) >= minRatio,
    towards,
  )
}

/**
 * The lightness direction that gives `color` more contrast against `background`:
 * darken on a light ground, lighten on a dark one.
 */
export function contrastDirection(background: string): 'lighter' | 'darker' {
  return relativeLuminance(background) > 0.179 ? 'darker' : 'lighter'
}

/**
 * A fill colour for a button/chip and the text colour on it. The text is white
 * or ink, whichever contrasts more; the fill is adjusted in lightness until that
 * text reaches `minRatio` (4.5:1 for text). Returns the adjusted fill and whether
 * it changed.
 */
export function accessibleFill(
  fill: string,
  minRatio = 4.5,
): { fill: string; text: string; adjusted: boolean } {
  const pick = (bg: string) =>
    contrastRatio(WHITE_HEX, bg) >= contrastRatio(INK_HEX, bg) ? WHITE_HEX : INK_HEX
  const bestRatio = (bg: string) =>
    Math.max(contrastRatio(WHITE_HEX, bg), contrastRatio(INK_HEX, bg))
  if (bestRatio(fill) >= minRatio) return { fill, text: pick(fill), adjusted: false }
  const { h, s, l } = hexToHsl(fill)
  // Move away from the middle tones, where neither text colour is strong enough.
  const towards = l >= 50 ? 100 : 0
  for (let step = 1; step <= 100; step += 1) {
    const candidate = hslToHex(h, s, l + ((towards - l) * step) / 100)
    if (bestRatio(candidate) >= minRatio)
      return { fill: candidate, text: pick(candidate), adjusted: true }
  }
  return {
    fill: towards === 100 ? WHITE_HEX : INK_HEX,
    text: pick(towards === 100 ? WHITE_HEX : INK_HEX),
    adjusted: true,
  }
}
