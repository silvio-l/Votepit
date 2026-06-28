import { useCallback, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import type { UserVote } from '../components/VoteWidget'
import type { VoteResponse } from '../lib/api'
import { vote as apiVote } from '../lib/api'

interface VoteState {
  score: number
  myVote: UserVote
  upCount: number
  downCount: number
}

export interface UseVoteOptions {
  boardSlug: string
  ideaId: number | string
  isAuthenticated: boolean
  initialScore: number
  initialMyVote?: 'up' | 'down' | 'none'
  initialUpCount: number
  initialDownCount: number
  /**
   * Override the path the user is sent back to after login.
   * Defaults to location.pathname.
   */
  returnTo?: string
}

export interface UseVoteResult {
  score: number
  myVote: UserVote
  upCount: number
  downCount: number
  onVoteUp: () => void
  onVoteDown: () => void
}

function toUserVote(v: 'up' | 'down' | 'none' | undefined): UserVote {
  if (v === 'up') return 'up'
  if (v === 'down') return 'down'
  return null
}

function fromServerResponse(r: VoteResponse): VoteState {
  return {
    score: r.score,
    myVote: toUserVote(r.my_vote),
    upCount: r.up_count,
    downCount: r.down_count,
  }
}

/**
 * Computes the optimistic next state for a vote click.
 * Same direction = retract; opposite = flip; none = fresh cast.
 */
function computeOptimistic(current: VoteState, direction: 'up' | 'down'): VoteState {
  const { score, myVote, upCount, downCount } = current

  if (direction === 'up') {
    if (myVote === 'up') {
      return { score: score - 1, myVote: null, upCount: upCount - 1, downCount }
    }
    if (myVote === 'down') {
      return { score: score + 2, myVote: 'up', upCount: upCount + 1, downCount: downCount - 1 }
    }
    return { score: score + 1, myVote: 'up', upCount: upCount + 1, downCount }
  }

  if (myVote === 'down') {
    return { score: score + 1, myVote: null, upCount, downCount: downCount - 1 }
  }
  if (myVote === 'up') {
    return { score: score - 2, myVote: 'down', upCount: upCount - 1, downCount: downCount + 1 }
  }
  return { score: score - 1, myVote: 'down', upCount, downCount: downCount + 1 }
}

/**
 * Shared optimistic-vote hook used by IdeaListRow-in-BoardPage and IdeaDetailPage.
 *
 * On click it:
 *   1. Computes the optimistic next state and updates UI immediately.
 *   2. Calls api.vote(...)
 *   3. Reconciles against the server response {score, my_vote, up_count, down_count}.
 *   4. ROLLS BACK to the pre-click state on any error.
 *
 * For anon (isAuthenticated=false), redirects to /login?r=<returnTo> instead.
 */
export function useVote({
  boardSlug,
  ideaId,
  isAuthenticated,
  initialScore,
  initialMyVote,
  initialUpCount,
  initialDownCount,
  returnTo,
}: UseVoteOptions): UseVoteResult {
  const navigate = useNavigate()
  const location = useLocation()

  const [voteState, setVoteState] = useState<VoteState>(() => ({
    score: initialScore,
    myVote: toUserVote(initialMyVote),
    upCount: initialUpCount,
    downCount: initialDownCount,
  }))

  const stateRef = useRef(voteState)
  stateRef.current = voteState

  const handleVote = useCallback(
    async (direction: 'up' | 'down') => {
      if (!isAuthenticated) {
        const target = returnTo ?? location.pathname
        navigate(`/login?r=${encodeURIComponent(target)}`)
        return
      }

      const before = stateRef.current
      const optimistic = computeOptimistic(before, direction)
      setVoteState(optimistic)

      try {
        const result = await apiVote(boardSlug, ideaId, direction)
        setVoteState(fromServerResponse(result))
      } catch {
        setVoteState(before)
      }
    },
    [boardSlug, ideaId, isAuthenticated, location.pathname, navigate, returnTo],
  )

  const onVoteUp = useCallback(() => void handleVote('up'), [handleVote])
  const onVoteDown = useCallback(() => void handleVote('down'), [handleVote])

  return {
    score: voteState.score,
    myVote: voteState.myVote,
    upCount: voteState.upCount,
    downCount: voteState.downCount,
    onVoteUp,
    onVoteDown,
  }
}
