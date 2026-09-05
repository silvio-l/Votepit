import type { CSSProperties } from 'react'
import type { BoardData } from './api'

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
 * Shared by every board-scoped page (Board, Roadmap, Submit, Edit, IdeaDetail)
 * — each wraps its rendered content in `<div style={brandingStyle(board)}>` so
 * the same board's colors apply no matter which page a voter lands on, not
 * just the board's own idea list.
 */
export function brandingStyle(
  board: Pick<BoardData, 'primary_color' | 'secondary_color'>,
): CSSProperties {
  const style: Record<string, string> = {}
  if (board.primary_color) {
    style['--vp-primary'] = board.primary_color
    style['--color-vp-accent'] = board.primary_color
    style['--color-vp-accent-strong'] = `color-mix(in srgb, ${board.primary_color} 70%, #000000)`
    style['--color-vp-accent-soft'] = `color-mix(in srgb, ${board.primary_color} 12%, #ffffff)`
    style['--color-vp-accent-ring'] = `color-mix(in srgb, ${board.primary_color} 35%, transparent)`
  }
  if (board.secondary_color) {
    style['--vp-ink'] = board.secondary_color
    style['--color-vp-ink'] = board.secondary_color
    style['--color-vp-rule-strong'] = board.secondary_color
  }
  return style
}
