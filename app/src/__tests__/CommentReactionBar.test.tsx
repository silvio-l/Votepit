/**
 * Tests for CommentReactionBar — the fixed-set emoji reaction toggle on a
 * comment. Mirrors useVote.test.tsx's structure: optimistic update +
 * server reconciliation, rollback on error, anon → onRequireLogin instead
 * of an API call.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CommentReactionBar, computeOptimisticReaction } from '../components/CommentReactionBar'
import type { CommentReactionsSummary } from '../lib/api'
import * as api from '../lib/api'

beforeEach(() => {
  vi.restoreAllMocks()
})

const EMPTY: CommentReactionsSummary = { counts: {}, my_reaction: null }

function renderBar(overrides: Partial<React.ComponentProps<typeof CommentReactionBar>> = {}) {
  const onRequireLogin = vi.fn()
  render(
    <CommentReactionBar
      boardSlug="demo"
      ideaId={42}
      commentId={7}
      initialReactions={EMPTY}
      isAuthenticated={true}
      onRequireLogin={onRequireLogin}
      ariaLabelFor={(emoji) => (emoji === null ? 'Reactions' : `React with ${emoji}`)}
      {...overrides}
    />,
  )
  return { onRequireLogin }
}

// ── computeOptimisticReaction (pure logic) ──────────────────────────────────

describe('computeOptimisticReaction', () => {
  it('sets a fresh reaction when none was active', () => {
    const next = computeOptimisticReaction(EMPTY, '👍')
    expect(next).toEqual({ counts: { '👍': 1 }, my_reaction: '👍' })
  })

  it('retracts when reacting with the same emoji again', () => {
    const current: CommentReactionsSummary = { counts: { '👍': 1 }, my_reaction: '👍' }
    const next = computeOptimisticReaction(current, '👍')
    expect(next).toEqual({ counts: {}, my_reaction: null })
  })

  it('switches in place when reacting with a different emoji', () => {
    const current: CommentReactionsSummary = { counts: { '👍': 3 }, my_reaction: '👍' }
    const next = computeOptimisticReaction(current, '❤️')
    expect(next).toEqual({ counts: { '👍': 2, '❤️': 1 }, my_reaction: '❤️' })
  })

  it('does not let a shared count go negative when others also hold it', () => {
    const current: CommentReactionsSummary = { counts: { '👍': 1 }, my_reaction: '👍' }
    const next = computeOptimisticReaction(current, '👍')
    expect(next.counts['👍']).toBeUndefined()
  })
})

// ── Rendering ────────────────────────────────────────────────────────────────

describe('CommentReactionBar — rendering', () => {
  it('renders a button for every fixed reaction', () => {
    renderBar()
    for (const emoji of api.COMMENT_REACTIONS) {
      expect(screen.getByRole('button', { name: `React with ${emoji}` })).toBeInTheDocument()
    }
  })

  it('shows a count only when > 0, and marks the active reaction pressed', () => {
    renderBar({ initialReactions: { counts: { '👍': 3 }, my_reaction: '👍' } })

    const thumbsUp = screen.getByRole('button', { name: 'React with 👍' })
    expect(thumbsUp).toHaveTextContent('3')
    expect(thumbsUp).toHaveAttribute('aria-pressed', 'true')

    const heart = screen.getByRole('button', { name: 'React with ❤️' })
    expect(heart).not.toHaveTextContent(/\d/)
    expect(heart).toHaveAttribute('aria-pressed', 'false')
  })
})

// ── Click behaviour: optimistic + reconcile ───────────────────────────────────

describe('CommentReactionBar — optimistic update + server reconciliation', () => {
  it('applies the optimistic state immediately, then reconciles with the server response', async () => {
    let resolveReact!: (v: CommentReactionsSummary) => void
    vi.spyOn(api, 'reactToComment').mockReturnValue(
      new Promise((r) => {
        resolveReact = r
      }),
    )

    renderBar()
    const button = screen.getByRole('button', { name: 'React with 👍' })

    await userEvent.click(button)

    // Optimistic: pressed + count 1, before the promise resolves.
    expect(button).toHaveAttribute('aria-pressed', 'true')
    expect(button).toHaveTextContent('1')

    resolveReact({ counts: { '👍': 4 }, my_reaction: '👍' })
    await screen.findByText('4')

    expect(button).toHaveAttribute('aria-pressed', 'true')
  })

  it('calls reactToComment with boardSlug/ideaId/commentId/reaction', async () => {
    vi.spyOn(api, 'reactToComment').mockResolvedValue({ counts: { '🎉': 1 }, my_reaction: '🎉' })

    renderBar()
    await userEvent.click(screen.getByRole('button', { name: 'React with 🎉' }))

    expect(api.reactToComment).toHaveBeenCalledWith('demo', 42, 7, '🎉')
  })

  it('rolls back to the pre-click state on API error', async () => {
    vi.spyOn(api, 'reactToComment').mockRejectedValue(new Error('network error'))

    renderBar()
    const button = screen.getByRole('button', { name: 'React with 😄' })

    await userEvent.click(button)

    expect(button).toHaveAttribute('aria-pressed', 'false')
    expect(button).not.toHaveTextContent(/\d/)
  })
})

// ── Anon path ──────────────────────────────────────────────────────────────────

describe('CommentReactionBar — anonymous viewer', () => {
  it('calls onRequireLogin instead of the API when anon', async () => {
    const reactSpy = vi.spyOn(api, 'reactToComment')
    const { onRequireLogin } = renderBar({ isAuthenticated: false })

    await userEvent.click(screen.getByRole('button', { name: 'React with 👍' }))

    expect(onRequireLogin).toHaveBeenCalledTimes(1)
    expect(reactSpy).not.toHaveBeenCalled()
  })
})
