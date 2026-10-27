import { Link } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import type { Tag } from '../lib/api'
import { tagTextColor } from '../lib/branding'
import { useResolvedTheme } from '../lib/theme'

export interface TagBadgesProps {
  tags: Tag[] | undefined
  /**
   * When set, each tag becomes a link that opens this board filtered by the
   * tag (`?tag_id=`), so a tap on a row or the detail page filters the list.
   */
  boardSlug?: string
  /**
   * Prefix for the tag links' data-testid, `<prefix>-tag-<id>`. Must be unique
   * on the page, so list rows pass their idea id in the prefix.
   */
  testIdPrefix?: string
}

/**
 * Tag pills for an idea (board-scoped tags, migrations/0051_create_idea_tags.sql).
 * Used on the board idea list cards and the idea detail page — same rendering,
 * different surrounding layout. Without a board slug the pills are read-only.
 *
 * Colors are dynamic per-tag hex values (server-validated,
 * BrandingValidator::color()) — same inline-style pattern as
 * BrandingPreview's primary/secondary color swatches, since these can't be
 * design tokens.
 */
export function TagBadges({ tags, boardSlug, testIdPrefix = 'tag' }: TagBadgesProps) {
  const resolved = useResolvedTheme()
  if (tags === undefined || tags.length === 0) return null

  return (
    <span className="flex flex-wrap items-center gap-1.5">
      {tags.map((tag) =>
        boardSlug === undefined ? (
          <span
            key={tag.id}
            className="ds-tag"
            style={{ color: tagTextColor(tag.color, resolved) }}
          >
            {tag.name}
          </span>
        ) : (
          <Link
            key={tag.id}
            to={accountPath(`/${boardSlug}?tag_id=${tag.id}`)}
            data-testid={`${testIdPrefix}-tag-${tag.id}`}
            className="ds-tag ds-focusable max-md:min-h-11 hover:underline"
            style={{ color: tagTextColor(tag.color, resolved) }}
          >
            {tag.name}
          </Link>
        ),
      )}
    </span>
  )
}
