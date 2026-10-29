/**
 * ActivationChecklist — per-board items, the share copy signal, the resume
 * link into the reopened wizard, and hide/show.
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AdminBoardSummary } from '../lib/api'
import { ActivationChecklist } from './ActivationChecklist'

const BOARDS: AdminBoardSummary[] = [
  { id: 1, slug: 'alpha', name: 'Alpha Board', frozen_at: null, idea_count: 0, vote_count: 0 },
  { id: 2, slug: 'beta', name: 'Beta Board', frozen_at: null, idea_count: 4, vote_count: 2 },
] as AdminBoardSummary[]

function mockFetch() {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    if (url.includes('/branding')) {
      return new Response(
        JSON.stringify({ primary_color: '#102F28', logo_url: null, secondary_color: null }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      )
    }
    return new Response(
      JSON.stringify({ members: [{ id: 1 }], invites: [], viewer_role: 'owner' }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    )
  })
}

function renderChecklist(boards: AdminBoardSummary[] = BOARDS) {
  return render(
    <MemoryRouter>
      <ActivationChecklist boards={boards} />
    </MemoryRouter>,
  )
}

describe('ActivationChecklist', () => {
  beforeEach(() => {
    localStorage.clear()
    mockFetch()
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('lists the six per-board items with stable test ids', async () => {
    renderChecklist()
    for (const id of ['board', 'appearance', 'share', 'team', 'idea', 'vote']) {
      expect(screen.getByTestId(`activation-checklist-item-${id}`)).toBeInTheDocument()
    }
    expect(screen.getByTestId('activation-checklist-resume')).toBeInTheDocument()
  })

  it('derives completion from the selected board and the members list', async () => {
    renderChecklist()
    await waitFor(() =>
      expect(screen.getByTestId('activation-checklist-item-appearance')).toHaveTextContent(
        /Aussehen/,
      ),
    )
    // Appearance is set (accent in branding), the team has two members → done.
    await waitFor(() =>
      expect(
        within(screen.getByTestId('activation-checklist-item-appearance')).queryByTestId(
          'activation-cta-appearance',
        ),
      ).toBeNull(),
    )
    // Alpha has no ideas yet, so the idea item stays open with its call to action.
    expect(screen.getByTestId('activation-cta-idea')).toBeInTheDocument()
  })

  it('offers a board select once the account has more than one board', () => {
    renderChecklist()
    expect(screen.getByTestId('activation-checklist-board-select')).toBeInTheDocument()
  })

  it('omits the board select for a single board', () => {
    renderChecklist([BOARDS[0]])
    expect(screen.queryByTestId('activation-checklist-board-select')).toBeNull()
  })

  it('links the resume action to the reopened wizard for the shown board', () => {
    renderChecklist()
    const resume = screen.getByTestId('activation-checklist-resume')
    expect(resume.getAttribute('href')).toContain('/admin/boards?setup=1&board=alpha')
  })

  it('hides and shows the checklist again, remembered per browser', async () => {
    const user = userEvent.setup()
    const { unmount } = renderChecklist()
    await user.click(screen.getByTestId('activation-checklist-hide'))
    expect(screen.queryByTestId('activation-checklist')).toBeNull()
    expect(screen.getByTestId('activation-checklist-show')).toBeInTheDocument()
    unmount()

    renderChecklist()
    expect(screen.queryByTestId('activation-checklist')).toBeNull()
    await user.click(screen.getByTestId('activation-checklist-show'))
    expect(screen.getByTestId('activation-checklist')).toBeInTheDocument()
  })
})
