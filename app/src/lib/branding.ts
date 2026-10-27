import {
  accessibleFill,
  adjustLightnessForContrast,
  adjustLightnessUntil,
  contrastDirection,
  contrastRatio,
  INK_HEX,
  isHexColor,
  mixHex,
  PORCELAIN_HEX,
  tokensDark,
  WHITE_HEX,
} from '@votepit/ui/ds'
import type { CSSProperties } from 'react'

/**
 * Per-board branding hooks (tokens.css `--vp-primary`/`--vp-ink`) — the ONLY
 * two CSS custom properties a board may override, already re-checked
 * server-side against the account's CURRENT plan (BoardHomeAction). Never
 * touches the semantic vote/status tokens.
 *
 * Setting `--vp-primary`/`--vp-ink` alone is NOT enough: every Tailwind
 * utility actually used in the UI (bg-vp-ink, text-vp-accent, …) resolves a
 * `--color-vp-*` alias (tokens.css `@theme`, e.g. `--color-vp-ink: var(--vp-ink)`).
 * That alias's computed value is only substituted where it has its OWN
 * declaration — at :root — and then simply inherits down unchanged, so
 * overriding the underlying `--vp-*` variable on this wrapper never
 * re-triggers the alias's `var()` substitution. Every derived alias must
 * therefore be set here too, or the override is silently invisible even
 * though the raw `--vp-*` variables read back correctly overridden.
 *
 * Shared by every board-scoped page (Board, Idea detail, Submit, Edit, Member
 * profile, Roadmap, Impressum) — each wraps its rendered content in
 * `<div style={brandingStyle(board)}>` so the same board's colors apply no
 * matter which page a voter lands on. The top bar is inside that wrapper, so
 * the bar's own `--vpds-c-topbar-*` properties are set here too (see
 * topbarBrandVars). The matching payload comes from the server's
 * BoardBrandingView (colours, logo, badge, back link, footer note), so the
 * values a page receives are identical on every board-scoped route.
 */
export function brandingStyle(
  board: {
    primary_color?: string | null
    secondary_color?: string | null
  },
  surface: BrandSurface = 'light',
): CSSProperties {
  return {
    ...brandThemeVars(board.primary_color, board.secondary_color, surface).vars,
    ...topbarBrandVars(board.primary_color, board.secondary_color),
  }
}

/** The page ground a board's accent text and indicators are checked against. */
export type BrandSurface = 'light' | 'dark'

/** Dark page ground: the DS dark canvas (generated), so accent contrast matches what is painted. */
// Generated value (tokens.generated.ts). The literal only narrows the type; the key always exists.
const DARK_GROUND = tokensDark['semantic.surface.canvas'] ?? '#0E1412'

const SURFACE_GROUND: Record<BrandSurface, string> = {
  light: PORCELAIN_HEX,
  dark: DARK_GROUND,
}

/**
 * The theme engine (P-26). From one validated accent (and an optional secondary
 * colour) it derives the DS roles a board may override:
 *
 * - accent text (`--color-vp-accent-strong`) reaches 4.5:1 on the surface;
 * - the accent as an indicator (`--color-vp-accent`: sheet edge, rules) reaches 3:1;
 * - the tint (`--color-vp-accent-soft`) and the focus ring stay tints of the accent;
 * - the secondary colour becomes the ink fill (buttons) with its own text colour
 *   (`--vp-on-ink`), adjusted in lightness until that text reaches 4.5:1.
 *
 * Contrast is corrected by adjusting lightness, never by rejecting the colour.
 * Pure and deterministic. `corrections` names every role whose value differs from
 * the raw input, so the preview can warn the owner. Only validated hex and
 * derived colours are returned; no board text ever reaches CSS.
 */
export function brandThemeVars(
  primary: unknown,
  secondary: unknown,
  surface: BrandSurface = 'light',
): { vars: Record<string, string>; corrections: string[] } {
  const vars: Record<string, string> = {}
  const corrections: string[] = []
  const primaryHex = normalizeHex(primary)
  const secondaryHex = normalizeHex(secondary)
  const ground = SURFACE_GROUND[surface]
  const direction = contrastDirection(ground)

  if (primaryHex !== null) {
    const indicator = adjustLightnessForContrast(primaryHex, ground, UI_MIN_CONTRAST, direction)
    const text = adjustLightnessForContrast(primaryHex, ground, TEXT_MIN_CONTRAST, direction)
    if (indicator !== primaryHex) corrections.push('accent-indicator')
    if (text !== primaryHex) corrections.push('accent-text')
    vars['--vp-primary'] = primaryHex
    vars['--color-vp-accent'] = indicator
    vars['--color-vp-accent-strong'] = text
    // The accent as a CTA/tab fill carries text: the better of white/ink, with the fill
    // lightness-corrected (hue kept) until that text reaches 4.5:1 (D-W45-1).
    const accentFill = accessibleFill(text, TEXT_MIN_CONTRAST)
    vars['--color-vp-accent-fill'] = accentFill.fill
    vars['--color-vp-on-accent-fill'] = accentFill.text
    // The tint is a pale wash on light grounds and a dark wash on the dark ground.
    vars['--color-vp-accent-soft'] =
      surface === 'dark' ? mixHex(primaryHex, ground, 0.85) : mixHex(primaryHex, WHITE_HEX, 0.88)
    vars['--color-vp-accent-ring'] = `color-mix(in srgb, ${indicator} 35%, transparent)`
  }
  if (secondaryHex !== null) {
    // On the dark ground the ink is also text (titles, labels, links): lighten it until it reads there.
    const ink =
      surface === 'dark'
        ? adjustLightnessForContrast(secondaryHex, DARK_PLANE, TEXT_MIN_CONTRAST, 'lighter')
        : secondaryHex
    const fill = accessibleFill(ink, TEXT_MIN_CONTRAST)
    if (fill.adjusted || ink !== secondaryHex) corrections.push('button-fill')
    vars['--vp-ink'] = fill.fill
    vars['--vp-on-ink'] = fill.text
    vars['--color-vp-ink'] = fill.fill
    vars['--color-vp-on-ink'] = fill.text
    vars['--color-vp-rule-strong'] = fill.fill
  }
  return { vars, corrections }
}

/** The dark plane tags and cards sit on (DS dark card). */
const DARK_PLANE = tokensDark['semantic.surface.card'] ?? '#161F1C'

/**
 * Text colour for a user-picked tag colour (the stored colour is never changed). The chip has
 * no background, so the text must reach 4.5:1 on the card and the canvas, and on the pressed
 * wash over each, in both themes. Light: darkened; dark: lightened. Hue is kept.
 */
export function tagTextColor(hex: string, surface: BrandSurface): string {
  const isDark = surface === 'dark'
  const grounds = isDark ? [DARK_PLANE, DARK_GROUND] : [WHITE_HEX, PORCELAIN_HEX]
  return adjustLightnessUntil(
    hex,
    (candidate) => chipTextReadable(candidate, grounds),
    isDark ? 'lighter' : 'darker',
  )
}

/**
 * Share of the text colour that the pressed chip (`.ds-chip-toggle[aria-pressed=true]`)
 * paints over its ground as a wash (`color-mix(currentColor 16%, transparent)`).
 */
const CHIP_PRESSED_WASH = 0.16

/**
 * Whether a chip text colour reaches 4.5:1 on every ground a tag chip can sit on, and on
 * the pressed-chip wash over each of them. The chip paints no background of its own.
 */
function chipTextReadable(text: string, grounds: readonly string[]): boolean {
  return grounds.every(
    (ground) =>
      contrastRatio(text, ground) >= TEXT_MIN_CONTRAST &&
      contrastRatio(text, mixHex(ground, text, CHIP_PRESSED_WASH)) >= TEXT_MIN_CONTRAST,
  )
}

/** Forest top bar (tokens.generated.css `component.topbar.background`), the bar every non-board page keeps. */
const DEFAULT_TOPBAR_BACKGROUND = '#102F28'
/** WCAG 1.4.3 text threshold. */
const TEXT_MIN_CONTRAST = 4.5
/** WCAG 1.4.11 threshold for UI indicators (active-tab rule, focus ring). */
const UI_MIN_CONTRAST = 3

/**
 * A six-digit uppercase hex for a stored colour, or null. The server accepts
 * three-digit hex as well (BrandingValidator::color), so expand those here;
 * anything else is not a colour and the caller uses its fallback.
 */
export function normalizeHex(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const trimmed = value.trim()
  if (/^#[0-9a-fA-F]{3}$/.test(trimmed)) {
    const [r = '', g = '', b = ''] = trimmed.slice(1)
    return `#${r}${r}${g}${g}${b}${b}`.toUpperCase()
  }
  return isHexColor(trimmed) ? trimmed.toUpperCase() : null
}

/**
 * The text colour (white or ink) with the higher contrast against `background`,
 * or null when neither reaches `minContrast`. For a server-valid secondary colour
 * white always passes (the write-time rule), so null only happens for a stale or
 * unvalidated value — and then the caller shows the default bar.
 */
export function readableTextOn(background: string, minContrast = TEXT_MIN_CONTRAST): string | null {
  const best = [WHITE_HEX, INK_HEX]
    .map((fg) => ({ fg, ratio: contrastRatio(fg, background) }))
    .sort((a, b) => b.ratio - a.ratio)[0]
  return best !== undefined && best.ratio >= minContrast ? best.fg : null
}

/**
 * The board accent as an indicator (active-tab rule, focus ring) on the forest
 * bar. Keeps the accent's hue; when it is too dark for 3:1 it is lightened
 * towards white in small steps until it reaches 3:1 (white itself always does).
 */
export function indicatorOnDefaultBar(accent: string): string {
  for (let step = 0; step <= 20; step += 1) {
    const candidate = mixHex(accent, WHITE_HEX, step / 20)
    if (contrastRatio(candidate, DEFAULT_TOPBAR_BACKGROUND) >= UI_MIN_CONTRAST) return candidate
  }
  return WHITE_HEX
}

/**
 * The top bar's custom properties for one board (empty for no valid colour).
 *
 * - Lite+ with a valid `secondary_color`: the whole bar takes that colour, its
 *   text/wordmark the white-or-ink colour that reads on it, the active rule and
 *   focus the board accent when it has 3:1 against the bar (else the text colour).
 *   The Votepit mark sits on a white plate so it stays visible on any bar.
 * - Free (no secondary colour, or none readable on it): the forest bar stays,
 *   and only the active-tab rule and focus take the board accent, lightened where
 *   needed for 3:1 — the one accent colour the Free plan publishes.
 *
 * Only validated hex values and derived colours go into the custom properties;
 * no board text, URL or markup ever reaches CSS (shared-origin invariant).
 */
export function topbarBrandVars(primary: unknown, secondary: unknown): Record<string, string> {
  const primaryHex = normalizeHex(primary)
  const secondaryHex = normalizeHex(secondary)
  // The theme engine adjusts a mid-tone secondary colour instead of rejecting it.
  const fill = secondaryHex !== null ? accessibleFill(secondaryHex, TEXT_MIN_CONTRAST) : null
  const bar = fill?.fill ?? null
  const text = fill?.text ?? null

  if (bar !== null && text !== null) {
    const accent =
      primaryHex !== null && contrastRatio(primaryHex, bar) >= UI_MIN_CONTRAST ? primaryHex : text
    const muted = mixHex(text, bar, 0.25)
    const mutedText = contrastRatio(muted, bar) >= TEXT_MIN_CONTRAST ? muted : text
    return {
      '--vpds-c-topbar-background': bar,
      '--vpds-c-topbar-foreground': text,
      '--vpds-c-topbar-muted': mutedText,
      '--vpds-c-topbar-rule': mixHex(bar, text, 0.25),
      '--vpds-c-topbar-accent': accent,
      '--vpds-c-topbar-focus': accent,
      '--vpds-c-topbar-wordmark-pit': text,
      '--vpds-c-topbar-edition-community': mutedText,
      '--vpds-c-topbar-mark-plate': WHITE_HEX,
      '--vpds-c-topbar-mark-padding': 'var(--vpds-space-2xs)',
    }
  }

  if (primaryHex !== null) {
    const accent = indicatorOnDefaultBar(primaryHex)
    return {
      '--vpds-c-topbar-accent': accent,
      '--vpds-c-topbar-focus': accent,
    }
  }

  return {}
}

/**
 * The board accent on the printed poster (owner 26a). The poster is printed on
 * white paper, so the accent is checked against white, not the app's porcelain:
 * accent text needs 4.5:1 (WCAG 1.4.3), a non-text accent element such as the
 * rule under the title needs 3:1 (WCAG 1.4.11). Like brandThemeVars, the accent
 * is corrected by lightness (hue kept, darker on white), never swapped for ink,
 * so the poster stays on-brand. Null when the board has no valid accent, so the
 * poster keeps its plain look. Only validated hex values are returned.
 */
export function posterAccent(primary: unknown): { text: string; rule: string } | null {
  const hex = normalizeHex(primary)
  if (hex === null) return null
  const direction = contrastDirection(WHITE_HEX)

  return {
    text: adjustLightnessForContrast(hex, WHITE_HEX, TEXT_MIN_CONTRAST, direction),
    rule: adjustLightnessForContrast(hex, WHITE_HEX, UI_MIN_CONTRAST, direction),
  }
}

/** Pro typography preset (migration 0093): Archivo/Inter/JetBrains Mono only, no new font files. */
export type TypographyPreset = 'default' | 'inter' | 'mono'
/** Pro corner style. */
export type ShapeStyle = 'rounded' | 'soft' | 'square'
/** Pro spacing density. Touch targets stay 44 px on coarse pointers. */
export type DensityPreset = 'comfortable' | 'compact'
/** Custom wording fields; each maps onto an existing Votepit text (see boardWording.tsx). */
export type WordingField =
  | 'idea_singular'
  | 'idea_plural'
  | 'vote_for'
  | 'vote_against'
  | 'submit_button'
  | 'empty_state'
  | 'thank_you'

/** The Pro branding values the public payload applies (BoardProBrandingView). */
export interface BrandingPro {
  typography: TypographyPreset
  shape: ShapeStyle
  density: DensityPreset
  /** `{locale: {field: text}}` — only locales and fields the board customised. */
  wording: Partial<Record<string, Partial<Record<WordingField, string>>>>
  header_links: Array<{ url: string; label: string }>
  roadmap_hidden: boolean
}

/**
 * The data attributes that switch the Pro presets on a board wrapper. The
 * values are the server-validated enums; tokens.css maps each to its styles.
 * No preset value ever reaches CSS as text (shared-origin invariant).
 */
export function brandingProAttributes(pro: BrandingPro | null | undefined): Record<string, string> {
  if (!pro) return {}
  return {
    'data-board-typography': pro.typography,
    'data-board-shape': pro.shape,
    'data-board-density': pro.density,
  }
}
