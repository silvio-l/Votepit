import { useCallback, useState } from 'react'
import type { CommentReaction, CommentReactionsSummary } from '../lib/api'
import { COMMENT_REACTIONS, reactToComment } from '../lib/api'

/**
 * Computes the optimistic next reactions summary for a click on `reaction`.
 * Same emoji as the current my_reaction = retract; a different one =
 * switch (decrement the old one, increment the new one) — mirrors
 * useVote's computeOptimistic, and the server's toggle semantics
 * (CommentReactionRepository::toggle, one active reaction per user).
 */
export function computeOptimisticReaction(
  current: CommentReactionsSummary,
  reaction: CommentReaction,
): CommentReactionsSummary {
  const counts = { ...current.counts }

  const decrement = (key: CommentReaction) => {
    const next = (counts[key] ?? 0) - 1
    if (next > 0) counts[key] = next
    else delete counts[key]
  }

  if (current.my_reaction === reaction) {
    decrement(reaction)
    return { counts, my_reaction: null }
  }

  if (current.my_reaction !== null) {
    decrement(current.my_reaction)
  }
  counts[reaction] = (counts[reaction] ?? 0) + 1

  return { counts, my_reaction: reaction }
}

export interface CommentReactionBarProps {
  boardSlug: string
  ideaId: number
  commentId: number
  initialReactions: CommentReactionsSummary
  isAuthenticated: boolean
  /** Called instead of the API call when the viewer is anonymous (redirect to login). */
  onRequireLogin: () => void
  /** `emoji === null` asks for the group's own aria-label. */
  ariaLabelFor: (emoji: string | null) => string
}

/**
 * A small, fixed-set emoji reaction bar for one comment — analogous to
 * GitHub's comment reactions, deliberately kept to a small predefined
 * palette (no free-text emoji picker, no per-tenant content — see
 * CLAUDE.md "Geteilte-Origin-Invariante"). Always shows all
 * COMMENT_REACTIONS as toggle buttons; a count only renders once > 0.
 *
 * Optimistic like useVote: updates immediately, reconciles with the
 * server response, and rolls back on error (silently — a failed reaction
 * toggle is low-stakes enough not to need a toast, unlike a failed vote).
 */
export function CommentReactionBar({
  boardSlug,
  ideaId,
  commentId,
  initialReactions,
  isAuthenticated,
  onRequireLogin,
  ariaLabelFor,
}: CommentReactionBarProps) {
  const [reactions, setReactions] = useState(initialReactions)
  const [pending, setPending] = useState(false)

  const handleClick = useCallback(
    (reaction: CommentReaction) => {
      if (!isAuthenticated) {
        onRequireLogin()
        return
      }
      if (pending) return

      const before = reactions
      setReactions(computeOptimisticReaction(before, reaction))
      setPending(true)

      reactToComment(boardSlug, ideaId, commentId, reaction)
        .then(setReactions)
        .catch(() => setReactions(before))
        .finally(() => setPending(false))
    },
    [boardSlug, ideaId, commentId, isAuthenticated, onRequireLogin, pending, reactions],
  )

  return (
    <div
      className="mt-2 flex flex-wrap items-center gap-1"
      role="group"
      aria-label={ariaLabelFor(null)}
    >
      {COMMENT_REACTIONS.map((emoji) => {
        const count = reactions.counts[emoji] ?? 0
        const active = reactions.my_reaction === emoji

        return (
          <button
            key={emoji}
            type="button"
            onClick={() => handleClick(emoji)}
            aria-pressed={active}
            aria-label={ariaLabelFor(emoji)}
            disabled={pending}
            className={`inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-vp-xs leading-none transition-colors ${
              active
                ? 'border-vp-ink bg-vp-surface-frost text-vp-ink'
                : 'border-vp-border-subtle text-vp-text-muted hover:border-vp-ink hover:text-vp-ink'
            }`}
          >
            <span aria-hidden="true">{emoji}</span>
            {count > 0 && <span className="font-mono-num">{count}</span>}
          </button>
        )
      })}
    </div>
  )
}
