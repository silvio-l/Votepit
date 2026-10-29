import { COVER_PATTERNS, type CoverPattern } from '@votepit/ui/ds'
import { boardAssetUrl } from './api/boardAssets'
import { chooseTopbarWordmark, topbarBarColor, type WordmarkChoice } from './brandPlate'

/** The logo fields a board payload can carry (uploaded logo first, legacy link second). */
export interface BoardLogoFields {
  logo_public_id?: string | null
  logo_url?: string | null
  logo_allowed?: boolean
  primary_color?: string | null
  name?: string | null
  /** Uploaded topbar wordmark (plan-gated by the server), or null. */
  wordmark_public_id?: string | null
  /** Mean relative luminance of the wordmark (contrast metadata, see brandPlate.ts). */
  wordmark_ink?: number | null
  /** Optional light wordmark for dark topbars (phase 4), or null. */
  wordmark_light_public_id?: string | null
  wordmark_light_ink?: number | null
  secondary_color?: string | null
}

/**
 * The one logo source for every surface (topbar, avatar, pages): the uploaded logo
 * when the board has one, otherwise the legacy logo link. Undefined means no logo.
 */
export function boardLogoSrc(
  slug: string | null | undefined,
  board: BoardLogoFields | null | undefined,
): string | undefined {
  if (!board) return undefined
  if (slug && board.logo_public_id) return boardAssetUrl(slug, board.logo_public_id)
  return board.logo_url ? board.logo_url : undefined
}

/**
 * Paid plans without a logo show the board name's initials on the accent (Free keeps
 * the Votepit mark). Undefined when the plan does not allow a logo or a name is missing.
 */
export function boardInitialsName(board: BoardLogoFields | null | undefined): string | undefined {
  if (board?.logo_allowed !== true) return undefined
  return board.name && board.name.trim() !== '' ? board.name : undefined
}

/** The stored pattern key as a known CoverPattern, or null (default cover). */
export function coverPatternOf(value: string | null | undefined): CoverPattern | null {
  return value !== undefined &&
    value !== null &&
    (COVER_PATTERNS as readonly string[]).includes(value)
    ? (value as CoverPattern)
    : null
}

/**
 * The topbar wordmark for this board (phase 4): the light variant when the bar
 * is dark enough for it, else the normal wordmark, with the plate rule applied.
 * Null when the board has no wordmark image at all.
 */
export function boardTopbarWordmark(
  board: BoardLogoFields | null | undefined,
): WordmarkChoice<string> | null {
  if (!board) return null
  const normal =
    board.wordmark_public_id !== null && board.wordmark_public_id !== undefined
      ? { value: board.wordmark_public_id, ink: board.wordmark_ink }
      : null
  const light =
    board.wordmark_light_public_id !== null && board.wordmark_light_public_id !== undefined
      ? { value: board.wordmark_light_public_id, ink: board.wordmark_light_ink }
      : null
  return chooseTopbarWordmark(topbarBarColor(board.secondary_color), normal, light)
}

/** The topbar wordmark (wide logo) the board shows; undefined when it has none. */
export function boardWordmarkSrc(
  slug: string | null | undefined,
  board: BoardLogoFields | null | undefined,
): string | undefined {
  const choice = boardTopbarWordmark(board)
  if (!slug || choice === null) return undefined
  return boardAssetUrl(slug, choice.value)
}

/** True when the shown wordmark needs a light plate on this board's topbar colour. */
export function boardWordmarkPlated(board: BoardLogoFields | null | undefined): boolean {
  return boardTopbarWordmark(board)?.plated ?? false
}
