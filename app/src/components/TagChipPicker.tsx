import { ChipToggle } from '@votepit/ui/ds'
import type { Tag } from '../lib/api/model'
import { tagTextColor } from '../lib/branding'
import { useResolvedTheme } from '../lib/theme'

/** Most existing tags one idea can carry (P-34, matches IdeaTagInput::MAX_TAGS on the server). */
export const MAX_IDEA_TAGS = 5

export interface TagChipPickerLabels {
  label: string
  hint: string
  limitReached: string
  /** Badge on an AI-suggested tag (submit form only). */
  suggested?: string
}

/**
 * Pick up to MAX_IDEA_TAGS existing board tags. Shared by the submit form and
 * the idea edit page, so both show and limit tags the same way. Test ids use
 * `testIdPrefix` (e.g. `submit` → `submit-tag-picker`, `submit-tag-<id>`).
 */
export function TagChipPicker({
  testIdPrefix,
  tags,
  selectedIds,
  isSuggested,
  disabled = false,
  labels,
  onToggle,
}: {
  testIdPrefix: string
  tags: Tag[]
  selectedIds: number[]
  /** AI-suggested marker per tag; absent = none. */
  isSuggested?: (tagId: number) => boolean
  disabled?: boolean
  labels: TagChipPickerLabels
  onToggle: (tagId: number) => void
}) {
  const atLimit = selectedIds.length >= MAX_IDEA_TAGS
  const resolved = useResolvedTheme()
  return (
    <div
      role="group"
      aria-labelledby={`${testIdPrefix}-tags-label`}
      data-testid={`${testIdPrefix}-tag-picker`}
      className="flex flex-col gap-2"
    >
      <span id={`${testIdPrefix}-tags-label`} className="text-vp-base font-semibold text-vp-title">
        {labels.label}
      </span>
      <span className="text-vp-xs text-vp-text-muted">{labels.hint}</span>
      <div className="flex flex-wrap gap-2">
        {tags.map((tag) => {
          const pressed = selectedIds.includes(tag.id)
          return (
            <ChipToggle
              key={tag.id}
              testId={`${testIdPrefix}-tag-${tag.id}`}
              pressed={pressed}
              color={tagTextColor(tag.color, resolved)}
              disabled={disabled || (!pressed && atLimit)}
              onToggle={() => onToggle(tag.id)}
            >
              {tag.name}
              {pressed && isSuggested?.(tag.id) === true && labels.suggested !== undefined && (
                <span
                  data-testid={`${testIdPrefix}-tag-${tag.id}-suggested`}
                  className="ml-1 text-vp-xs opacity-80"
                >
                  {labels.suggested}
                </span>
              )}
            </ChipToggle>
          )
        })}
      </div>
      {atLimit && (
        <p
          data-testid={`${testIdPrefix}-tag-picker-limit`}
          className="text-vp-xs text-vp-text-muted"
        >
          {labels.limitReached}
        </p>
      )}
    </div>
  )
}
