/**
 * Tests for the useVote hook — user-visible behaviour only.
 *
 * AC7 (Issue 11): VoteWidget/vote-flow optimistic switch AND rollback.
 *   (a) api.vote resolves → UI reflects server state
 *   (b) api.vote rejects → UI rolls back to pre-click state
 *   (c) flip/retract produce correct optimistic states
 *   (d) anon path redirects to /login with returnTo
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import * as api from '../lib/api'
import { useVote } from '../hooks/useVote'
import type { UseVoteOptions } from '../hooks/useVote'

// ── Helpers ──────────────────────────────────────────────────────────────────

const BASE_OPTS: UseVoteOptions = {
  boardSlug: 'demo',
  ideaId: 42,
  isAuthenticated: true,
  initialScore: 3,
  initialMyVote: 'none',
  initialUpCount: 4,
  initialDownCount: 1,
}

function wrapper({ children }: { children: React.ReactNode }) {
  return (
    <MemoryRouter initialEntries={['/demo/idea/42']}>
      {children}
    </MemoryRouter>
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── AC7a: optimistic switch + server reconciliation ───────────────────────────

describe('useVote — optimistic switch (AC7a)', () => {
  it('upvote: optimistic score +1 then reconciles with server response', async () => {
    const serverResponse = {
      score: 4,
      my_vote: 'up' as const,
      up_count: 5,
      down_count: 1,
    }

    let resolveVote!: (v: typeof serverResponse) => void
    vi.spyOn(api, 'vote').mockReturnValue(
      new Promise((r) => { resolveVote = r }),
    )

    const { result } = renderHook(() => useVote(BASE_OPTS), { wrapper })

    expect(result.current.score).toBe(3)

    // Trigger click — vote promise stays pending
    act(() => { result.current.onVoteUp() })

    // Optimistic state applied synchronously
    expect(result.current.score).toBe(4) // 3 + 1
    expect(result.current.myVote).toBe('up')
    expect(result.current.upCount).toBe(5) // 4 + 1

    // Resolve promise → reconcile with server
    await act(async () => {
      resolveVote(serverResponse)
      await Promise.resolve()
    })

    expect(result.current.score).toBe(4)
    expect(result.current.myVote).toBe('up')
    expect(result.current.upCount).toBe(5)
    expect(result.current.downCount).toBe(1)
  })

  it('downvote: optimistic score -1 then reconciles', async () => {
    const serverResponse = {
      score: 2,
      my_vote: 'down' as const,
      up_count: 4,
      down_count: 2,
    }
    vi.spyOn(api, 'vote').mockResolvedValue(serverResponse)

    const { result } = renderHook(() => useVote(BASE_OPTS), { wrapper })

    await act(async () => { result.current.onVoteDown() })

    expect(result.current.score).toBe(2)
    expect(result.current.myVote).toBe('down')
  })
})

// ── AC7b: rollback on server error ────────────────────────────────────────────

describe('useVote — rollback on server error (AC7b)', () => {
  it('rolls back score and myVote to pre-click state on API error', async () => {
    vi.spyOn(api, 'vote').mockRejectedValue(new Error('Network error'))

    const { result } = renderHook(() => useVote(BASE_OPTS), { wrapper })

    const scoreBefore = result.current.score
    const myVoteBefore = result.current.myVote

    await act(async () => { result.current.onVoteUp() })

    expect(result.current.score).toBe(scoreBefore)
    expect(result.current.myVote).toBe(myVoteBefore)
    expect(result.current.upCount).toBe(4)
  })

  it('rolls back flip state on API error', async () => {
    vi.spyOn(api, 'vote').mockRejectedValue(new Error('Server error'))

    const opts: UseVoteOptions = {
      ...BASE_OPTS,
      initialMyVote: 'up',
      initialScore: 3,
      initialUpCount: 4,
      initialDownCount: 1,
    }
    const { result } = renderHook(() => useVote(opts), { wrapper })

    // Initial state: voted up
    expect(result.current.myVote).toBe('up')

    // Click down (flip) → then error → should roll back
    await act(async () => { result.current.onVoteDown() })

    expect(result.current.myVote).toBe('up')
    expect(result.current.score).toBe(3)
  })
})

// ── AC7c: flip / retract optimistic states ────────────────────────────────────

describe('useVote — flip and retract optimistic states (AC7c)', () => {
  it('retract: up then up sets myVote to null and decrements score', async () => {
    let resolveVote!: (v: api.VoteResponse) => void
    vi.spyOn(api, 'vote').mockReturnValue(
      new Promise((r) => { resolveVote = r }),
    )

    const opts: UseVoteOptions = {
      ...BASE_OPTS,
      initialMyVote: 'up',
      initialScore: 3,
      initialUpCount: 4,
    }
    const { result } = renderHook(() => useVote(opts), { wrapper })

    act(() => { result.current.onVoteUp() })

    // Optimistic retract
    expect(result.current.myVote).toBeNull()
    expect(result.current.score).toBe(2) // 3 - 1
    expect(result.current.upCount).toBe(3) // 4 - 1

    await act(async () => {
      resolveVote({ score: 2, my_vote: 'none', up_count: 3, down_count: 1 })
      await Promise.resolve()
    })

    expect(result.current.myVote).toBeNull()
  })

  it('flip down→up: increments score by 2, switches myVote', async () => {
    let resolveVote!: (v: api.VoteResponse) => void
    vi.spyOn(api, 'vote').mockReturnValue(
      new Promise((r) => { resolveVote = r }),
    )

    const opts: UseVoteOptions = {
      ...BASE_OPTS,
      initialMyVote: 'down',
      initialScore: 1,
      initialUpCount: 3,
      initialDownCount: 2,
    }
    const { result } = renderHook(() => useVote(opts), { wrapper })

    act(() => { result.current.onVoteUp() })

    // Optimistic flip
    expect(result.current.myVote).toBe('up')
    expect(result.current.score).toBe(3) // 1 + 2
    expect(result.current.upCount).toBe(4) // 3 + 1
    expect(result.current.downCount).toBe(1) // 2 - 1

    await act(async () => {
      resolveVote({ score: 3, my_vote: 'up', up_count: 4, down_count: 1 })
      await Promise.resolve()
    })
  })

  it('flip up→down: decrements score by 2, switches myVote', async () => {
    let resolveVote!: (v: api.VoteResponse) => void
    vi.spyOn(api, 'vote').mockReturnValue(
      new Promise((r) => { resolveVote = r }),
    )

    const opts: UseVoteOptions = {
      ...BASE_OPTS,
      initialMyVote: 'up',
      initialScore: 3,
      initialUpCount: 4,
      initialDownCount: 1,
    }

    const { result } = renderHook(() => useVote(opts), { wrapper })

    act(() => { result.current.onVoteDown() })

    expect(result.current.myVote).toBe('down')
    expect(result.current.score).toBe(1) // 3 - 2
    expect(result.current.upCount).toBe(3) // 4 - 1
    expect(result.current.downCount).toBe(2) // 1 + 1

    await act(async () => {
      resolveVote({ score: 1, my_vote: 'down', up_count: 3, down_count: 2 })
      await Promise.resolve()
    })
  })
})

// ── AC7d: anon redirect ───────────────────────────────────────────────────────

describe('useVote — anon redirect (AC7d)', () => {
  function CurrentPath() {
    const location = useLocation()
    return <div data-testid="current-path">{location.pathname + location.search}</div>
  }

  function AnonVoterFixture({ returnTo }: { returnTo?: string }) {
    const voteResult = useVote({
      boardSlug: 'demo',
      ideaId: 42,
      isAuthenticated: false,
      initialScore: 3,
      initialMyVote: 'none',
      initialUpCount: 4,
      initialDownCount: 1,
      returnTo,
    })
    return <button onClick={voteResult.onVoteUp}>vote up</button>
  }

  it('anon upvote redirects to /login with r=<returnTo> query param', async () => {
    render(
      <MemoryRouter initialEntries={['/demo']}>
        <Routes>
          <Route
            path="/demo"
            element={
              <>
                <AnonVoterFixture returnTo="/demo/idea/42" />
                <CurrentPath />
              </>
            }
          />
          <Route path="/login" element={<CurrentPath />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'vote up' }))

    await waitFor(() => {
      const path = screen.getByTestId('current-path').textContent ?? ''
      expect(path).toContain('/login')
      expect(path).toContain('r=')
      expect(path).toContain(encodeURIComponent('/demo/idea/42'))
    })
  })

  it('anon downvote also redirects to /login', async () => {
    function AnonDownVoter() {
      const voteResult = useVote({
        boardSlug: 'demo',
        ideaId: 42,
        isAuthenticated: false,
        initialScore: 3,
        initialMyVote: 'none',
        initialUpCount: 4,
        initialDownCount: 1,
        returnTo: '/demo/idea/42',
      })
      return <button onClick={voteResult.onVoteDown}>vote down</button>
    }

    render(
      <MemoryRouter initialEntries={['/demo']}>
        <Routes>
          <Route
            path="/demo"
            element={
              <>
                <AnonDownVoter />
                <CurrentPath />
              </>
            }
          />
          <Route path="/login" element={<CurrentPath />} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'vote down' }))

    await waitFor(() => {
      const path = screen.getByTestId('current-path').textContent ?? ''
      expect(path).toContain('/login')
    })
  })

  it('does NOT call api.vote when anon', async () => {
    const voteSpy = vi.spyOn(api, 'vote')

    render(
      <MemoryRouter initialEntries={['/demo']}>
        <Routes>
          <Route path="/demo" element={<AnonVoterFixture returnTo="/demo/idea/42" />} />
          <Route path="/login" element={<div>login</div>} />
        </Routes>
      </MemoryRouter>,
    )

    await userEvent.click(screen.getByRole('button', { name: 'vote up' }))

    expect(voteSpy).not.toHaveBeenCalled()
  })
})
