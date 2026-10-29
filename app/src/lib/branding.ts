import {
  accessibleFill,
  adjustLightnessForContrast,
  adjustLightnessUntil,
  contrastDirection,
  contrastRatio,
  hexToHsl,
  INK_HEX,
  isHexColor,
  mixHex,
  PORCELAIN_HEX,
  relativeLuminance,
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
export function brandingStyle(board: BrandBoard, surface: BrandSurface = 'light'): CSSProperties {
  const bar = topbarBrandVars(board.primary_color, board.secondary_color, board.accent_color)
  return {
    ...brandThemeVars(board.primary_color, board.secondary_color, surface, {
      accent: board.accent_color,
      primaryDark: board.primary_color_dark,
      accentDark: board.accent_color_dark,
    }).vars,
    ...bar,
    ...logoPlateVars(
      bar['--vpds-c-topbar-background'] ?? null,
      board.logo_plate,
      board.logo_ring_hidden === true,
      board.asset_ink?.logo ?? null,
    ),
  }
}

/**
 * The colour fields a board page's payload carries (BoardHomeAction / BoardBrandingView).
 * Every value is validated server-side and re-checked here; a missing one is the default.
 */
export interface BrandBoard {
  primary_color?: string | null
  secondary_color?: string | null
  accent_color?: string | null
  primary_color_dark?: string | null
  accent_color_dark?: string | null
  logo_plate?: string | null
  logo_ring_hidden?: boolean
  /** Mean relative luminance of each uploaded image variant (board_assets.ink_luminance). */
  asset_ink?: Partial<Record<string, number>> | null
}

/**
 * The accent the generated cover is painted from for one theme: the accent, else the
 * primary (per-mode values first on the dark theme). Null when the board has neither.
 */
export function coverAccentFor(board: BrandBoard, dark: boolean): string | null {
  return dark
    ? firstHex(
        board.accent_color_dark,
        board.accent_color,
        board.primary_color_dark,
        board.primary_color,
      )
    : firstHex(board.accent_color, board.primary_color)
}

/** The first valid colour of the given values (six-digit upper case), or null. */
function firstHex(...values: unknown[]): string | null {
  for (const value of values) {
    const hex = normalizeHex(value)
    if (hex !== null) return hex
  }
  return null
}

/** The page ground a board's accent text and indicators are checked against. */
export type BrandSurface = 'light' | 'dark'

/** Dark page ground: the DS dark canvas (generated), so accent contrast matches what is painted. */
// Generated value (tokens.generated.ts). The literal only narrows the type; the key always exists.
export const DARK_GROUND = tokensDark['semantic.surface.canvas'] ?? '#0E1412'

/**
 * Light canvas of a cool-branded board (dogfood-branding #11, owner decision 2a: automatic, no
 * setting). A cool-neutral grey close in luminance to porcelain (relative 0.90 against 0.89), so
 * accent and text contrast on it is at least what it is on porcelain (branding.test.ts checks it).
 * Its sunken layer follows, so a cool page never mixes a warm and a cool grey.
 */
export const COOL_CANVAS_HEX = '#F3F4F6'
const COOL_SUNKEN_HEX = '#E5E7EB'

/** Hue window of a cool brand colour in degrees: green-teal (160) through blue to violet (280). */
const COOL_HUE_MIN = 160
const COOL_HUE_MAX = 280
/** Chroma floor on a 0-1 scale (0 grey, 1 pure hue): below it a colour reads as a grey. */
const COOL_MIN_CHROMA = 0.1

/**
 * True when a six-digit colour is a cool hue (160°-280°, both inclusive) with enough chroma to
 * read as a colour rather than a grey. Chroma is (1 - |2L - 1|) × S of the colour's HSL.
 */
export function isCoolBrand(hex: string): boolean {
  const { h, s, l } = hexToHsl(hex)
  const chroma = (1 - Math.abs(2 * (l / 100) - 1)) * (s / 100)
  return h >= COOL_HUE_MIN && h <= COOL_HUE_MAX && chroma >= COOL_MIN_CHROMA
}

/**
 * The light canvas of a board: the ground its pages and light-theme accents are checked against.
 * Rule (decided here, not per board): the primary colour decides; a board without a valid primary
 * uses its secondary colour; a board with neither keeps porcelain. A cool brand colour (see
 * isCoolBrand) gets COOL_CANVAS_HEX. Warm, neutral and grey brand colours keep porcelain, so only
 * cool boards change and every unset or warm board looks exactly as before.
 */
export function lightCanvasFor(primary: unknown, secondary: unknown): string {
  const brand = normalizeHex(primary) ?? normalizeHex(secondary)
  return brand !== null && isCoolBrand(brand) ? COOL_CANVAS_HEX : PORCELAIN_HEX
}

/** The ground a board's pages are painted on in one theme: the DS dark canvas, or the light canvas. */
export function boardCanvasFor(board: BrandBoard, surface: BrandSurface = 'light'): string {
  return surface === 'dark'
    ? DARK_GROUND
    : lightCanvasFor(board.primary_color, board.secondary_color)
}

/**
 * The theme engine (P-26). From the validated colours it derives the DS roles a
 * board may override:
 *
 * - primary = action: the CTA fill (`--vpds-c-button-accent-*`, `--color-vp-accent-fill`)
 *   and its text reach 4.5:1; the per-mode value applies on the dark surface;
 * - accent = highlight: `--color-vp-accent` (sheet edge, rules) reaches 3:1 and
 *   `--color-vp-accent-strong` 4.5:1; the tint and focus ring stay tints of it. Without
 *   an accent the primary is used;
 * - secondary = surface: the ink fill (bars, panels, ink buttons) with its own text colour
 *   (`--vp-on-ink`), adjusted in lightness until that text reaches 4.5:1. Never a title colour.
 *
 * Contrast is corrected by adjusting lightness, never by rejecting the colour.
 * Pure and deterministic. `corrections` names every role whose value differs from
 * the raw input, so the preview can warn the owner. Only validated hex and
 * derived colours are returned; no board text ever reaches CSS.
 */
/** Per-mode and highlight values a board adds on top of primary and secondary (v0.1.5). */
export interface BrandHighlights {
  accent?: unknown
  primaryDark?: unknown
  accentDark?: unknown
}

export function brandThemeVars(
  primary: unknown,
  secondary: unknown,
  surface: BrandSurface = 'light',
  highlights: BrandHighlights = {},
): { vars: Record<string, string>; corrections: string[] } {
  const vars: Record<string, string> = {}
  const corrections: string[] = []
  const secondaryHex = normalizeHex(secondary)
  const dark = surface === 'dark'
  const ground = dark ? DARK_GROUND : lightCanvasFor(primary, secondary)
  const direction = contrastDirection(ground)
  // Action role (CTA fill, links, focus): the primary colour; on the dark ground its per-mode value first.
  const action = firstHex(...(dark ? [highlights.primaryDark, primary] : [primary]))
  // Highlight role (sheet edge, counts, badges, pinned): the accent, else the action colour.
  const highlight = firstHex(
    ...(dark ? [highlights.accentDark, highlights.accent, action] : [highlights.accent, action]),
  )

  if (action !== null) {
    vars['--vp-primary'] = action
    const actionText = adjustLightnessForContrast(action, ground, TEXT_MIN_CONTRAST, direction)
    if (actionText !== action) corrections.push('accent-text')
    // The action colour as a fill carries text: the better of white/ink, with the fill
    // lightness-corrected (hue kept) until that text reaches 4.5:1 (D-W45-1).
    const actionFill = accessibleFill(actionText, TEXT_MIN_CONTRAST)
    vars['--color-vp-accent-fill'] = actionFill.fill
    vars['--color-vp-on-accent-fill'] = actionFill.text
    vars['--color-vp-action-text'] = actionText
    // The masthead and other accent buttons (DS `.ds-button-accent`) take the same fill.
    vars['--vpds-c-button-accent-background'] = actionFill.fill
    vars['--vpds-c-button-accent-background-hover'] = buttonHoverFill(
      actionFill.fill,
      actionFill.text,
    )
    vars['--vpds-c-button-accent-foreground'] = actionFill.text
  }
  if (!dark && ground === COOL_CANVAS_HEX) {
    // Cool light canvas: every alias the pages paint with, not only the base token. The
    // [data-theme] block resolves `--color-vp-*` at the theme root, so an override of the base
    // token alone would not reach them. Warm and unset boards emit none of these.
    vars['--vpds-surface-canvas'] = COOL_CANVAS_HEX
    vars['--vp-bg'] = COOL_CANVAS_HEX
    vars['--color-vp-bg'] = COOL_CANVAS_HEX
    vars['--color-vp-surface-frost'] = COOL_CANVAS_HEX
    vars['--vpds-surface-sunken'] = COOL_SUNKEN_HEX
    vars['--color-vp-surface-sunken'] = COOL_SUNKEN_HEX
    vars['--vpds-action-secondary-background-hover'] = COOL_CANVAS_HEX
    vars['--vpds-action-on-inverse-background-hover'] = COOL_CANVAS_HEX
  }
  if (highlight !== null) {
    const indicator = adjustLightnessForContrast(highlight, ground, UI_MIN_CONTRAST, direction)
    const text = adjustLightnessForContrast(highlight, ground, TEXT_MIN_CONTRAST, direction)
    if (indicator !== highlight) corrections.push('accent-indicator')
    vars['--color-vp-accent'] = indicator
    vars['--color-vp-accent-strong'] = text
    // The tint is a pale wash on light grounds and a dark wash on the dark ground.
    vars['--color-vp-accent-soft'] = dark
      ? mixHex(highlight, ground, 0.85)
      : mixHex(highlight, WHITE_HEX, 0.88)
    vars['--color-vp-accent-ring'] = `color-mix(in srgb, ${indicator} 35%, transparent)`
  }
  // Accent-only highlights (v0.1.5, item 07): the pinned edge, the vote count and the voted
  // state. Only a set accent draws them; without one the design-system defaults stay.
  const accentHex = firstHex(
    ...(dark ? [highlights.accentDark, highlights.accent] : [highlights.accent]),
  )
  if (accentHex !== null && highlight !== null) {
    vars['--vpds-c-idea-pinned-edge'] = vars['--color-vp-accent'] ?? highlight
    const soft = vars['--color-vp-accent-soft'] ?? WHITE_HEX
    vars['--vpds-c-vote-control-field-up-background'] = soft
    vars['--vpds-c-vote-control-field-up-foreground'] = adjustLightnessForContrast(
      highlight,
      soft,
      TEXT_MIN_CONTRAST,
      contrastDirection(soft),
    )
    const voted = accessibleFill(highlight, TEXT_MIN_CONTRAST)
    vars['--vpds-c-vote-control-up-selected-background'] = voted.fill
    vars['--vpds-c-vote-control-up-selected-foreground'] = voted.text
    vars['--vpds-c-vote-control-up-selected-hover'] = buttonHoverFill(voted.fill, voted.text)
    vars['--vpds-c-vote-control-selected-ring-up'] = voted.fill
  }
  if (secondaryHex !== null) {
    // The secondary colour is a surface (bars, panels, the ink buttons), never a text colour:
    // titles use the neutral `--color-vp-title` token, so it is not lightened for text here.
    const fill = accessibleFill(secondaryHex, TEXT_MIN_CONTRAST)
    if (fill.adjusted) corrections.push('button-fill')
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
 * - Paid plans with a valid `secondary_color`: the whole bar takes that colour, its
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
export function topbarBrandVars(
  primary: unknown,
  secondary: unknown,
  accent?: unknown,
): Record<string, string> {
  const primaryHex = normalizeHex(primary)
  const accentHex = normalizeHex(accent)
  const secondaryHex = normalizeHex(secondary)
  // The theme engine adjusts a mid-tone secondary colour instead of rejecting it.
  const fill = secondaryHex !== null ? accessibleFill(secondaryHex, TEXT_MIN_CONTRAST) : null
  const bar = fill?.fill ?? null
  const text = fill?.text ?? null

  if (bar !== null && text !== null) {
    // The active rule is a highlight (accent, else primary); focus is the action colour.
    const highlight = accentHex ?? primaryHex
    const accentOnBar =
      highlight !== null && contrastRatio(highlight, bar) >= UI_MIN_CONTRAST ? highlight : text
    const focus =
      primaryHex !== null && contrastRatio(primaryHex, bar) >= UI_MIN_CONTRAST ? primaryHex : text
    const muted = mixHex(text, bar, 0.25)
    const mutedText = contrastRatio(muted, bar) >= TEXT_MIN_CONTRAST ? muted : text
    return {
      '--vpds-c-topbar-background': bar,
      '--vpds-c-topbar-foreground': text,
      '--vpds-c-topbar-muted': mutedText,
      '--vpds-c-topbar-rule': mixHex(bar, text, 0.25),
      '--vpds-c-topbar-accent': accentOnBar,
      '--vpds-c-topbar-focus': focus,
      '--vpds-c-topbar-wordmark-pit': text,
      '--vpds-c-topbar-edition-community': mutedText,
      '--vpds-c-topbar-mark-plate': WHITE_HEX,
      '--vpds-c-topbar-mark-padding': 'var(--vpds-space-2xs)',
    }
  }

  const highlight = accentHex ?? primaryHex
  if (highlight !== null) {
    const accentOnDefault = indicatorOnDefaultBar(highlight)
    return {
      '--vpds-c-topbar-accent': accentOnDefault,
      '--vpds-c-topbar-focus':
        primaryHex !== null ? indicatorOnDefaultBar(primaryHex) : accentOnDefault,
    }
  }

  return {}
}

/**
 * The plate behind the round board logo on the topbar (v0.1.5, item 09). `auto` and `brand`
 * use the bar colour (the secondary colour, so transparent corners meet the bar), `white`
 * a white plate, `none` no plate. A board without a bar colour (Free) gets no plate, and the
 * ring (a hairline on the plate's edge) is only drawn while a plate is. Only derived colours
 * and the fixed keywords reach the custom properties.
 */
export function logoPlateVars(
  barHex: string | null,
  choice: string | null | undefined,
  ringHidden: boolean,
  logoInk: number | null = null,
): Record<string, string> {
  // `none` keeps the plate transparent, so no colour shows through the logo's corners.
  const plate = choice === 'none' ? 'transparent' : logoPlateFill(choice, barHex, logoInk)
  // No plate and the ring shown: no variables at all, so both surfaces keep their default look.
  if (plate === null && !ringHidden) return {}
  const vars: Record<string, string> = {}
  if (plate !== null) {
    // The topbar logo and the round board logo (profile, masthead) share one plate rule.
    vars['--vpds-c-topbar-logo-plate'] = plate
    vars['--vpds-c-board-logo-plate'] = plate
  }
  const ringOff = ringHidden || choice === 'none'
  vars['--vpds-c-topbar-logo-ring'] = ringOff
    ? 'none'
    : '0 0 0 1px var(--vpds-c-topbar-rule, transparent)'
  vars['--vpds-c-board-logo-ring'] = ringOff ? 'none' : '0 0 0 1px var(--vpds-surface-canvas)'
  return vars
}

/** WCAG minimum for a logo graphic against the bar behind it (1.4.11, as brandPlate.ts). */
const LOGO_PLATE_MIN_CONTRAST = 3

/**
 * The plate colour for a stored choice on a bar, or null for no plate. `auto` uses the bar
 * colour, or white when the logo's mean ink (board_assets.ink_luminance) would sit below
 * the WCAG minimum on it. `brand` always uses the bar colour, `white` always white.
 */
function logoPlateFill(
  choice: string | null | undefined,
  barHex: string | null,
  logoInk: number | null = null,
): string | null {
  if (choice === 'none') return null
  if (choice === 'white') return WHITE_HEX
  if (barHex === null) return null
  if (choice !== 'brand' && logoInk !== null) {
    const bar = relativeLuminance(barHex)
    const hi = Math.max(bar, logoInk)
    const lo = Math.min(bar, logoInk)
    if ((hi + 0.05) / (lo + 0.05) < LOGO_PLATE_MIN_CONTRAST) return WHITE_HEX
  }
  return barHex
}

/**
 * The hover colour of an accent button: the fill moved a little towards its text, when the
 * text still reaches 4.5:1 there; otherwise the fill itself (no hover change, never less contrast).
 */
function buttonHoverFill(fill: string, text: string): string {
  const candidate = mixHex(fill, text, 0.12)
  return contrastRatio(text, candidate) >= TEXT_MIN_CONTRAST ? candidate : fill
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
