import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { getVoterPreview, setVoterPreview } from '../lib/voterPreview'

/**
 * Shared "view as voter" state for every board-scoped page (BoardPage,
 * RoadmapPage, IdeaDetailPage, EditPage, SubmitPage). Seeds from the
 * `?view=voter` URL param (entry from AdminPage's "View board" link, or a
 * bookmarked/shared link) OR from the module-level store (client-side
 * navigation from another board-scoped page, which doesn't reload and so
 * doesn't repeat the query param) — either turns it on. Toggling updates all
 * three: the returned state, the URL (so reload/bookmark/share stay
 * consistent), and the module store (so the next board-scoped page a voter
 * navigates to picks it up without a URL param of its own).
 */
export function useVoterPreview(): [boolean, (checked: boolean) => void] {
  const [searchParams, setSearchParams] = useSearchParams()
  const [active, setActive] = useState(() => {
    const initial = searchParams.get('view') === 'voter' || getVoterPreview()
    setVoterPreview(initial)
    return initial
  })

  const setActivePersisted = useCallback(
    (checked: boolean) => {
      setActive(checked)
      setVoterPreview(checked)
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          if (checked) {
            next.set('view', 'voter')
          } else {
            next.delete('view')
          }
          return next
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  return [active, setActivePersisted]
}
