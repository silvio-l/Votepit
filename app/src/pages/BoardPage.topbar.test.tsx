/**
 * The board's branding reaches the top bar on board-scoped pages only:
 * the bar sits inside the page's branding wrapper, so a Lite+ secondary colour
 * takes the whole bar, and a Free board only gets the accent on the active rule.
 * A header rendered outside a board keeps the forest default.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { Header } from '@votepit/ui'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BoardPage from './BoardPage'

const MEMBER = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false, memberships: [] },
}

function boardResponse(colors: { primary_color: string | null; secondary_color: string | null }) {
  return {
    board: {
      id: 1,
      slug: 'acme',
      name: 'Acme Board',
      intro: '',
      show_badge: true,
      ...colors,
    },
    active_status: null,
    active_sort: 'new',
    page: 1,
    total_pages: 1,
    is_authenticated: true,
    ideas: [],
    stats: { weekly_votes: 0, weekly_new_ideas: 0, avg_consensus: 0, total_ideas: 0 },
  }
}

function mockFetch(board: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const body = url.startsWith('/notifications')
      ? { notifications: [] }
      : url.includes('bootstrap')
        ? MEMBER
        : board
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

/** The nearest ancestor (or self) carrying an inline value for a custom property. */
function ancestorSetting(el: HTMLElement, prop: string): HTMLElement | null {
  let current: HTMLElement | null = el
  while (current) {
    if (current.style.getPropertyValue(prop) !== '') return current
    current = current.parentElement
  }
  return null
}

async function renderBoard(colors: {
  primary_color: string | null
  secondary_color: string | null
}) {
  mockFetch(boardResponse(colors))
  render(
    <MemoryRouter initialEntries={['/acme']}>
      <Routes>
        <Route path="/:boardSlug" element={<BoardPage />} />
      </Routes>
    </MemoryRouter>,
  )
  await waitFor(() =>
    expect(screen.getByRole('heading', { name: 'Acme Board' })).toBeInTheDocument(),
  )
  return screen.getByTestId('topbar')
}

beforeEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('BoardPage top bar branding', () => {
  it('a Lite+ board with a secondary colour takes the whole bar', async () => {
    const topbar = await renderBoard({ primary_color: '#312E81', secondary_color: '#0B6B4C' })

    const branded = ancestorSetting(topbar, '--vpds-c-topbar-background')
    expect(branded).not.toBeNull()
    expect(branded?.style.getPropertyValue('--vpds-c-topbar-background')).toBe('#0B6B4C')
    expect(branded?.style.getPropertyValue('--vpds-c-topbar-foreground')).toBe('#FFFFFF')
  })

  it('a Free board keeps the forest bar and only gets the accent on the active rule', async () => {
    const topbar = await renderBoard({ primary_color: '#312E81', secondary_color: null })

    expect(ancestorSetting(topbar, '--vpds-c-topbar-background')).toBeNull()
    const accented = ancestorSetting(topbar, '--vpds-c-topbar-accent')
    expect(accented).not.toBeNull()
    expect(accented?.style.getPropertyValue('--vpds-c-topbar-accent')).not.toBe('#7DDBB3')
  })

  it('a board without colours leaves the bar at its default', async () => {
    const topbar = await renderBoard({ primary_color: null, secondary_color: null })

    expect(ancestorSetting(topbar, '--vpds-c-topbar-background')).toBeNull()
    expect(ancestorSetting(topbar, '--vpds-c-topbar-accent')).toBeNull()
  })
})

describe('top bar outside a board', () => {
  it('the header keeps the forest default when no board wrapper is present', () => {
    render(
      <MemoryRouter>
        <Header edition="cloud" />
      </MemoryRouter>,
    )

    const topbar = screen.getByTestId('topbar')
    expect(ancestorSetting(topbar, '--vpds-c-topbar-background')).toBeNull()
    expect(ancestorSetting(topbar, '--vpds-c-topbar-accent')).toBeNull()
  })
})
