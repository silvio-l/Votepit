import { relativeLuminance } from '@votepit/ui/ds'
import { topbarBrandVars } from './branding'

/**
 * Light plate behind a board logo or wordmark on the topbar (BR-LOGO2).
 *
 * Owner question 25 is open: (a) automatic plate, (b) an extra light-variant
 * slot, (c) a warning only. The default below is (a). Changing the answer means
 * changing this one flag: `'variant'` and `'warn'` never add a plate here, and
 * the settings tip follows the same answer through `logoNeedsPlate`.
 *
 * The ink value is the server's mean relative luminance of the visible pixels
 * of the rendition (board_assets.ink_luminance). It is a heuristic, not a
 * colour analysis: a mixed-colour logo is judged by its average.
 */
export type LogoPlatePolicy = 'auto' | 'variant' | 'warn'

export const LOGO_PLATE_POLICY: LogoPlatePolicy = 'auto'

/** WCAG minimum for a graphic against its background (1.4.11). */
const LOGO_MIN_CONTRAST = 3

/** Forest brand bar: a fixed brand surface in both schemes (DESIGN.md "Fixed colours"). */
const FOREST_TOPBAR = '#102F28'

/**
 * The colour the topbar paints behind its logo, for one board: the board's own
 * bar colour (Lite+ with a valid secondary colour), otherwise the forest default
 * that Free boards keep. Free payloads carry no secondary colour, so they resolve
 * to the default by construction.
 */
export function topbarBarColor(secondary: string | null | undefined): string {
  return topbarBrandVars(null, secondary ?? null)['--vpds-c-topbar-background'] ?? FOREST_TOPBAR
}

/** A wordmark candidate: any value (id or URL) plus its mean luminance, when measured. */
export interface WordmarkCandidate<T> {
  value: T
  ink: number | null | undefined
}

export interface WordmarkChoice<T> {
  value: T
  /** A light plate sits behind it on the bar. */
  plated: boolean
  variant: 'light' | 'normal'
}

/** WCAG contrast of two mean relative luminances (1..21). */
function contrastOf(a: number, b: number): number {
  const hi = Math.max(a, b)
  const lo = Math.min(a, b)
  return (hi + 0.05) / (lo + 0.05)
}

/**
 * Picks the topbar wordmark (BR-LOGO2, phase 4). The optional light variant wins
 * when the bar is dark enough that it reaches the WCAG minimum. Otherwise the
 * normal wordmark is used, plated when it would still fall below it. Without a
 * normal wordmark the light variant is the fallback, with the same plate rule.
 * Null when neither slot holds an image.
 */
export function chooseTopbarWordmark<T>(
  barHex: string,
  normal: WordmarkCandidate<T> | null,
  light: WordmarkCandidate<T> | null,
  policy: LogoPlatePolicy = LOGO_PLATE_POLICY,
): WordmarkChoice<T> | null {
  const bar = relativeLuminance(barHex)
  if (
    light !== null &&
    typeof light.ink === 'number' &&
    contrastOf(bar, light.ink) >= LOGO_MIN_CONTRAST
  ) {
    return { value: light.value, plated: false, variant: 'light' }
  }
  const pick = normal ?? light
  if (pick === null) return null
  return {
    value: pick.value,
    plated: logoNeedsPlate(barHex, pick.ink, policy),
    variant: normal !== null ? 'normal' : 'light',
  }
}

/**
 * True when a logo with this mean luminance would sit below the WCAG minimum
 * on the bar, so a light plate is applied. False without a measured value
 * (nothing visible or an upload from before the metadata existed).
 */
export function logoNeedsPlate(
  barHex: string,
  ink: number | null | undefined,
  policy: LogoPlatePolicy = LOGO_PLATE_POLICY,
): boolean {
  if (policy !== 'auto' || ink === null || ink === undefined) return false
  const bar = relativeLuminance(barHex)
  const hi = Math.max(bar, ink)
  const lo = Math.min(bar, ink)

  return (hi + 0.05) / (lo + 0.05) < LOGO_MIN_CONTRAST
}
