import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { accountPath } from '../lib/accountContext'
import { trackEvent } from '../lib/analytics'
import type { Idea } from '../lib/api'
import { vote as apiVote } from '../lib/api'
import { getEdition } from '../lib/edition'
import { computeOptimistic, fromServerResponse, toUserVote, type VoteState } from './useVote'

export interface UseBoardVotesResult {
  /** Current (possibly optimistic) vote state for an idea, live-reordering key. */
  get: (idea: Idea) => VoteState
  castVote: (idea: Idea, direction: 'up' | 'down') => void
  /**
   * Merges freshly-polled server data (other voters' activity) into the
   * shared vote map — skips any idea with an in-flight local vote so a
   * background poll can never clobber an optimistic update mid-flight.
   */
  mergeFromPoll: (freshIdeas: Idea[]) => void
}

function seed(ideas: Idea[]): Map<number, VoteState> {
  const map = new Map<number, VoteState>()
  for (const idea of ideas) {
    map.set(idea.id, {
      score: idea.score_cache,
      myVote: toUserVote(idea.my_vote),
      upCount: idea.up_count,
      downCount: idea.down_count,
    })
  }
  return map
}

/**
 * Board-wide shared vote state — lifted out of individual rows (unlike
 * useVote, used standalone on IdeaDetailPage) so that BoardPage can re-sort
 * the list by score as votes come in, live, from either the current viewer's
 * own click or a background poll of other voters' activity.
 *
 * `onBeforeChange` is called synchronously right before every state update
 * that might change sort order — the caller uses it to snapshot current DOM
 * row positions (see useFlipReorder) while they still reflect the OLD order.
 */
export function useBoardVotes(
  boardSlug: string,
  ideas: Idea[],
  isAuthenticated: boolean,
  onBeforeChange: () => void,
  onError: () => void,
): UseBoardVotesResult {
  const navigate = useNavigate()
  const [votes, setVotes] = useState<Map<number, VoteState>>(() => seed(ideas))
  const pendingRef = useRef<Set<number>>(new Set())

  // Re-seed whenever the underlying query result changes (new sort/status/
  // page/board) — a fresh `ideas` array identity from a new fetch.
  useEffect(() => {
    setVotes(seed(ideas))
    pendingRef.current.clear()
  }, [ideas])

  const get = useCallback(
    (idea: Idea): VoteState =>
      votes.get(idea.id) ?? {
        score: idea.score_cache,
        myVote: toUserVote(idea.my_vote),
        upCount: idea.up_count,
        downCount: idea.down_count,
      },
    [votes],
  )

  const castVote = useCallback(
    (idea: Idea, direction: 'up' | 'down') => {
      if (!isAuthenticated) {
        const target = accountPath(`/${boardSlug}/idea/${idea.id}`)
        navigate(`/login?r=${encodeURIComponent(target)}`)
        return
      }

      const before = get(idea)
      const optimistic = computeOptimistic(before, direction)
      pendingRef.current.add(idea.id)
      onBeforeChange()
      setVotes((prev) => new Map(prev).set(idea.id, optimistic))

      apiVote(boardSlug, idea.id, direction)
        .then((result) => {
          onBeforeChange()
          setVotes((prev) => new Map(prev).set(idea.id, fromServerResponse(result)))
          if (before.myVote === null) {
            trackEvent(getEdition() === 'cloud' ? 'Cloud' : 'Community', 'first_vote_cast')
          }
        })
        .catch(() => {
          onBeforeChange()
          setVotes((prev) => new Map(prev).set(idea.id, before))
          onError()
        })
        .finally(() => {
          pendingRef.current.delete(idea.id)
        })
    },
    [boardSlug, isAuthenticated, navigate, get, onBeforeChange, onError],
  )

  const mergeFromPoll = useCallback(
    (freshIdeas: Idea[]) => {
      onBeforeChange()
      setVotes((prev) => {
        const next = new Map(prev)
        for (const fresh of freshIdeas) {
          if (pendingRef.current.has(fresh.id)) continue
          next.set(fresh.id, {
            score: fresh.score_cache,
            myVote: toUserVote(fresh.my_vote),
            upCount: fresh.up_count,
            downCount: fresh.down_count,
          })
        }
        return next
      })
    },
    [onBeforeChange],
  )

  return { get, castVote, mergeFromPoll }
}
