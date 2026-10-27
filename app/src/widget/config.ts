/**
 * Runtime configuration of the widget iframe, injected by WidgetPageAction
 * as `<meta name="votepit-widget" content="{json}">` (no inline script — the
 * CSP stays script-src 'self'). Parsed defensively: anything malformed
 * yields null and the widget renders nothing interactive.
 */

/** Colour tokens the board's plan lets the widget follow (P-32, Pro). */
interface WidgetTheme {
  primary_color?: string
  secondary_color?: string
}

type WidgetThemeMode = 'system' | 'light' | 'dark'

export interface WidgetConfig {
  /** Origins allowed to frame the widget — the only postMessage peers. */
  origins: string[]
  /** `theme_mode` is the board's display mode (P-26); anything unknown reads as system. */
  board: { slug: string; name: string; themeMode: WidgetThemeMode }
  /** Board path on this origin, e.g. "/acme/ideas" (derived from the iframe URL). */
  basePath: string
  /** Validated colour tokens, or null for the default Votepit look. */
  theme?: WidgetTheme | null
}

const ORIGIN = /^https:\/\/[a-z0-9.-]+(?::\d{1,5})?$/
const HEX_COLOR = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

function readThemeMode(value: unknown): WidgetThemeMode {
  return value === 'light' || value === 'dark' ? value : 'system'
}

/** Keeps only well-formed hex colours; anything else is dropped, never passed on. */
function readTheme(value: unknown): WidgetTheme | null {
  if (typeof value !== 'object' || value === null) return null
  const { primary_color, secondary_color } = value as Record<string, unknown>
  const theme: WidgetTheme = {}
  if (typeof primary_color === 'string' && HEX_COLOR.test(primary_color)) {
    theme.primary_color = primary_color
  }
  if (typeof secondary_color === 'string' && HEX_COLOR.test(secondary_color)) {
    theme.secondary_color = secondary_color
  }
  return Object.keys(theme).length > 0 ? theme : null
}

export function parseWidgetConfig(
  metaContent: string | null,
  pathname: string,
): WidgetConfig | null {
  if (metaContent === null || !pathname.endsWith('/widget')) return null
  let raw: unknown
  try {
    raw = JSON.parse(metaContent)
  } catch {
    return null
  }
  if (typeof raw !== 'object' || raw === null) return null
  const { origins, board, theme } = raw as { origins?: unknown; board?: unknown; theme?: unknown }
  if (!Array.isArray(origins) || origins.length === 0) return null
  if (!origins.every((o): o is string => typeof o === 'string' && ORIGIN.test(o))) return null
  if (typeof board !== 'object' || board === null) return null
  const { slug, name, theme_mode } = board as {
    slug?: unknown
    name?: unknown
    theme_mode?: unknown
  }
  if (typeof slug !== 'string' || typeof name !== 'string') return null

  return {
    origins,
    board: { slug, name, themeMode: readThemeMode(theme_mode) },
    basePath: pathname.slice(0, -'/widget'.length),
    theme: readTheme(theme),
  }
}

/** Messages the host page may send (see public/widget.js). */
export type HostMessage =
  | { type: 'votepit:init' }
  | { type: 'votepit:identify'; jwt: string }
  | { type: 'votepit:logout' }

/**
 * Accepts a message only from the direct parent window AND an allowed
 * origin, with a known shape — everything else is ignored silently.
 */
export function acceptHostMessage(
  event: Pick<MessageEvent, 'origin' | 'source' | 'data'>,
  parent: unknown,
  origins: readonly string[],
): HostMessage | null {
  if (event.source !== parent || !origins.includes(event.origin)) return null
  const data = event.data as { type?: unknown; jwt?: unknown } | null
  if (typeof data !== 'object' || data === null) return null
  if (data.type === 'votepit:init') return { type: 'votepit:init' }
  if (data.type === 'votepit:logout') return { type: 'votepit:logout' }
  if (data.type === 'votepit:identify' && typeof data.jwt === 'string' && data.jwt.length <= 4096) {
    return { type: 'votepit:identify', jwt: data.jwt }
  }
  return null
}
