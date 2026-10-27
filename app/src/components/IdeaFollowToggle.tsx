import { Bell } from 'lucide-react'
import { useEffect, useState } from 'react'
import { followIdea, getIdeaFollow, unfollowIdea } from '../lib/api/participantMail'
import { useT } from '../lib/i18n/context'

/**
 * Follow toggle on the idea detail page (close-the-loop re-engagement, slice C).
 * Only rendered for signed-in viewers by its parent. It reads the current state
 * on mount and renders nothing if that read fails (e.g. a private board), so an
 * unavailable follow never shows a broken control.
 */
export function IdeaFollowToggle({ boardSlug, ideaId }: { boardSlug: string; ideaId: number }) {
  const t = useT('ideaFollow')
  const [following, setFollowing] = useState<boolean | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setFollowing(null)
    getIdeaFollow(boardSlug, ideaId)
      .then((data) => {
        if (!cancelled) setFollowing(data.following === true)
      })
      .catch(() => {
        if (!cancelled) setFollowing(null)
      })
    return () => {
      cancelled = true
    }
  }, [boardSlug, ideaId])

  if (following === null) return null

  const toggle = async () => {
    setBusy(true)
    setError(null)
    try {
      const data = following
        ? await unfollowIdea(boardSlug, ideaId)
        : await followIdea(boardSlug, ideaId)
      setFollowing(data.following === true)
    } catch {
      setError(t('error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
      <button
        type="button"
        data-testid="idea-follow-toggle"
        aria-pressed={following}
        title={t('hint')}
        disabled={busy}
        onClick={() => void toggle()}
        className={`inline-flex items-center gap-1 max-sm:min-h-11 underline decoration-2 underline-offset-[0.2em] hover:text-vp-ink disabled:opacity-60 ${following ? 'text-vp-ink font-semibold' : 'text-vp-text-muted'}`}
      >
        <Bell size={12} strokeWidth={2} aria-hidden="true" />
        {following ? t('following') : t('follow')}
      </button>
      {error !== null && (
        <span role="alert" className="text-vp-xs text-vp-vote-down-strong">
          {error}
        </span>
      )}
    </span>
  )
}
