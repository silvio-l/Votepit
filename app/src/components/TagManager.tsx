import { accessibleFill, Button, InlineNotice, TextField } from '@votepit/ui/ds'
import { useEffect, useState } from 'react'
import type { ApiError, Tag } from '../lib/api'
import { assignIdeaTag, createTag, listTags, removeIdeaTag } from '../lib/api'
import { tagTextColor } from '../lib/branding'
import { useT } from '../lib/i18n/context'
import { useResolvedTheme } from '../lib/theme'

/** An assigned tag: its colour as the fill (lightened or darkened only if needed) and the text that passes 4.5:1 on it. */
const assignedFill = (color: string) => accessibleFill(color)

/**
 * Moderator-facing tag control for one idea — inline in the moderation
 * strip of IdeaDetailPage, same accountModerate() gate as pin/status/block
 * (see AppFactory routes for /{board}/tags and /{board}/ideas/{id}/tags).
 *
 * Board tags are fetched once on mount; toggling a chip assigns/removes it
 * on this idea. The inline form creates a brand-new board tag and assigns
 * it in one step, since "create a tag" almost always happens while tagging
 * a specific idea rather than from a separate admin screen.
 */
export function TagManager({
  boardSlug,
  ideaId,
  ideaTags,
  onChange,
}: {
  boardSlug: string
  ideaId: number
  ideaTags: Tag[]
  onChange: (tags: Tag[]) => void
}) {
  const t = useT('ideaDetailPage')

  const [boardTags, setBoardTags] = useState<Tag[]>([])
  const [loadError, setLoadError] = useState(false)
  const [pendingTagId, setPendingTagId] = useState<number | null>(null)
  const [toggleError, setToggleError] = useState<string | null>(null)

  const [newName, setNewName] = useState('')
  // Default for a new tag's colour picker (data, the user picks any colour).
  const [newColor, setNewColor] = useState('#3b82f6')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const resolvedTheme = useResolvedTheme()

  useEffect(() => {
    listTags(boardSlug)
      .then((res) => setBoardTags(Array.isArray(res.tags) ? res.tags : []))
      .catch(() => setLoadError(true))
  }, [boardSlug])

  const assignedIds = new Set(ideaTags.map((tag) => tag.id))

  const handleToggle = async (tag: Tag) => {
    if (pendingTagId !== null) return
    setToggleError(null)
    setPendingTagId(tag.id)
    const assigned = assignedIds.has(tag.id)
    try {
      const res = assigned
        ? await removeIdeaTag(boardSlug, ideaId, tag.id)
        : await assignIdeaTag(boardSlug, ideaId, tag.id)
      onChange(res.tags)
    } catch (err) {
      const apiErr = err as ApiError
      setToggleError(apiErr?.payload?.message ?? t('tagUpdateError'))
    } finally {
      setPendingTagId(null)
    }
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    const trimmed = newName.trim()
    if (trimmed === '' || creating) return

    setCreating(true)
    setCreateError(null)
    try {
      const { tag } = await createTag(boardSlug, { name: trimmed, color: newColor })
      setBoardTags((prev) => [...prev, tag].sort((a, b) => a.name.localeCompare(b.name)))
      const res = await assignIdeaTag(boardSlug, ideaId, tag.id)
      onChange(res.tags)
      setNewName('')
    } catch (err) {
      const apiErr = err as ApiError
      setCreateError(apiErr?.payload?.message ?? t('tagUpdateError'))
    } finally {
      setCreating(false)
    }
  }

  return (
    <div>
      <p className="vp-eyebrow mb-1.5">{t('tagsLabel')}</p>

      {loadError ? (
        <InlineNotice tone="error" testId="tag-manager-load-error">
          {t('tagsLoadError')}
        </InlineNotice>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-1.5">
            {boardTags.length === 0 && (
              <span className="text-vp-xs text-vp-text-muted">{t('noBoardTagsYet')}</span>
            )}
            {boardTags.map((tag) => {
              const assigned = assignedIds.has(tag.id)
              return (
                <button
                  key={tag.id}
                  data-testid={`tag-manager-toggle-${tag.id}`}
                  type="button"
                  aria-pressed={assigned}
                  disabled={pendingTagId === tag.id}
                  onClick={() => void handleToggle(tag)}
                  className="inline-flex items-center rounded-full border px-2 py-0.5 text-vp-xs font-medium disabled:opacity-60"
                  style={
                    assigned
                      ? {
                          borderColor: assignedFill(tag.color).fill,
                          color: assignedFill(tag.color).text,
                          backgroundColor: assignedFill(tag.color).fill,
                        }
                      : { borderColor: tag.color, color: tagTextColor(tag.color, resolvedTheme) }
                  }
                >
                  {tag.name}
                </button>
              )
            })}
          </div>

          {toggleError !== null && (
            <InlineNotice tone="error" testId="tag-manager-toggle-error" className="mt-1.5">
              {toggleError}
            </InlineNotice>
          )}

          <form
            onSubmit={(e) => void handleCreate(e)}
            className="mt-2 flex flex-wrap items-end gap-2"
          >
            <TextField
              testId="tag-manager-new-name"
              label={t('tagsLabel')}
              hideLabel
              id="tag-manager-new-name"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              placeholder={t('tagNamePlaceholder')}
              maxLength={40}
              disabled={creating}
              className="w-40"
            />
            <TextField
              testId="tag-manager-new-color"
              label={t('tagColorLabel')}
              hideLabel
              id="tag-manager-new-color"
              value={newColor}
              onChange={(e) => setNewColor(e.target.value)}
              disabled={creating}
              className="w-24"
            />
            <Button
              testId="tag-manager-add"
              type="submit"
              variant="secondary"
              disabled={creating || newName.trim() === ''}
            >
              {creating ? t('addingTag') : t('addTag')}
            </Button>
          </form>

          {createError !== null && (
            <InlineNotice tone="error" testId="tag-manager-create-error" className="mt-1.5">
              {createError}
            </InlineNotice>
          )}
        </>
      )}
    </div>
  )
}
