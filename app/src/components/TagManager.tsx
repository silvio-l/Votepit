import { Alert, Button, TextInput } from '@votepit/ui'
import { useEffect, useState } from 'react'
import type { ApiError, Tag } from '../lib/api'
import { assignIdeaTag, createTag, listTags, removeIdeaTag } from '../lib/api'
import { useT } from '../lib/i18n/context'

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
  const [newColor, setNewColor] = useState('#3b82f6')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)

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
        <Alert tone="error">{t('tagsLoadError')}</Alert>
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
                  type="button"
                  aria-pressed={assigned}
                  disabled={pendingTagId === tag.id}
                  onClick={() => void handleToggle(tag)}
                  className="inline-flex items-center rounded-full border px-2 py-0.5 text-vp-xs font-medium disabled:opacity-60"
                  style={
                    assigned
                      ? { borderColor: tag.color, color: '#fff', backgroundColor: tag.color }
                      : { borderColor: tag.color, color: tag.color }
                  }
                >
                  {tag.name}
                </button>
              )
            })}
          </div>

          {toggleError !== null && (
            <Alert tone="error" className="mt-1.5">
              {toggleError}
            </Alert>
          )}

          <form
            onSubmit={(e) => void handleCreate(e)}
            className="mt-2 flex flex-wrap items-end gap-2"
          >
            <TextInput
              label={t('tagsLabel')}
              hideLabel
              id="tag-manager-new-name"
              value={newName}
              onChange={setNewName}
              placeholder={t('tagNamePlaceholder')}
              maxLength={40}
              disabled={creating}
              className="w-40"
            />
            <TextInput
              label={t('tagColorLabel')}
              hideLabel
              id="tag-manager-new-color"
              value={newColor}
              onChange={setNewColor}
              disabled={creating}
              className="w-24"
            />
            <Button
              type="submit"
              variant="secondary"
              size="sm"
              disabled={creating || newName.trim() === ''}
            >
              {creating ? t('addingTag') : t('addTag')}
            </Button>
          </form>

          {createError !== null && (
            <Alert tone="error" className="mt-1.5">
              {createError}
            </Alert>
          )}
        </>
      )}
    </div>
  )
}
