import type { Tag } from '../lib/api'

/**
 * Read-only tag pills for an idea (board-scoped tags,
 * migrations/0051_create_idea_tags.sql). Used on both the board idea list
 * cards and the idea detail page — same rendering, different surrounding
 * layout.
 *
 * Colors are dynamic per-tag hex values (server-validated,
 * BrandingValidator::color()) — same inline-style pattern as
 * BrandingPreview's primary/secondary color swatches, since these can't be
 * design tokens.
 */
export function TagBadges({ tags }: { tags: Tag[] | undefined }) {
  if (tags === undefined || tags.length === 0) return null

  return (
    <span className="flex flex-wrap items-center gap-1.5">
      {tags.map((tag) => (
        <span
          key={tag.id}
          className="inline-flex items-center rounded-full border px-2 py-0.5 text-vp-xs font-medium"
          style={{ borderColor: tag.color, color: tag.color }}
        >
          {tag.name}
        </span>
      ))}
    </span>
  )
}
