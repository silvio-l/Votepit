/**
 * BoardCoverBand — the board's cover band (G-12), shared by the board page and the
 * roadmap so both open with the same header. An uploaded cover (plan-gated server-side,
 * `assets.cover_wide` / `cover_phone`) wins; otherwise the generated default cover is
 * drawn from our own shapes, the board accent and a pattern (`cover_pattern`, Lite+).
 * The band uses the shared `.ds-board-cover--bleed` height classes.
 */

import { BoardCover } from '@votepit/ui/ds'
import { boardAssetUrl } from '../lib/api/boardAssets'
import { coverPatternOf } from '../lib/boardLogo'

export interface BoardCoverBandProps {
  slug: string
  /** Stable seed for the default cover family (the board id). */
  seed: string | number
  accent?: string | null
  coverPattern?: string | null
  assets?: { cover_wide?: string; cover_phone?: string }
}

export function BoardCoverBand({ slug, seed, accent, coverPattern, assets }: BoardCoverBandProps) {
  if (assets?.cover_wide !== undefined) {
    return (
      <picture
        className="ds-board-cover--bleed relative -top-6 md:-top-10 mb-4 block overflow-hidden"
        data-testid="board-cover-image"
      >
        {assets.cover_phone !== undefined && (
          <source media="(max-width: 639px)" srcSet={boardAssetUrl(slug, assets.cover_phone)} />
        )}
        <img
          src={boardAssetUrl(slug, assets.cover_wide)}
          alt=""
          width={2400}
          height={400}
          className="ds-board-cover ds-board-cover--bleed"
        />
      </picture>
    )
  }

  return (
    <BoardCover
      accent={accent}
      seed={seed}
      pattern={coverPatternOf(coverPattern)}
      testId="board-cover"
      className="ds-board-cover--bleed relative -top-6 md:-top-10"
    />
  )
}
