/**
 * RTL tests for MergeIdeaDialog (issue 10 — moderators merge a duplicate
 * into another idea): suggestions on open, title search, pick + confirm,
 * server rejection surfaced. The api module is mocked directly.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MergeIdeaDialog } from '../components/MergeIdeaDialog'
import * as api from '../lib/api'
import { ApiError } from '../lib/api'
import type { User } from '../lib/api/model'
import { setFeatures } from '../lib/features'
import { setViewer } from '../lib/viewer'

vi.mock('../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return {
    ...actual,
    getMergeCandidates: vi.fn(),
    mergeIdea: vi.fn(),
  }
})

const suggestion = {
  id: 7,
  title: 'Dark mode',
  status: 'open' as const,
  up_count: 4,
  down_count: 1,
  similarity: 0.93,
}

/** Signed-in viewer with one membership; the upgrade link is owner-only. */
function viewerWithRole(role: 'owner' | 'admin' | 'moderator' | 'member'): void {
  setViewer({ memberships: [{ account_slug: 'demo', role }] } as unknown as User)
}

beforeEach(() => {
  vi.clearAllMocks()
  setFeatures(undefined)
  setViewer(null)
})

function renderDialog() {
  const onMerged = vi.fn()
  const onClose = vi.fn()
  render(<MergeIdeaDialog open boardSlug="demo" ideaId={3} onClose={onClose} onMerged={onMerged} />)
  return { onMerged, onClose }
}

describe('MergeIdeaDialog', () => {
  it('suggests similar ideas and merges into the picked one', async () => {
    vi.mocked(api.getMergeCandidates).mockResolvedValue({ candidates: [suggestion] })
    vi.mocked(api.mergeIdea).mockResolvedValue({
      ok: true,
      idea_id: 3,
      merged_into: { id: 7, title: 'Dark mode' },
    })
    const { onMerged } = renderDialog()
    const user = userEvent.setup()

    const confirm = screen.getByRole('button', { name: 'Zusammenführen' })
    expect(confirm).toBeDisabled()

    await user.click(await screen.findByRole('radio', { name: /dark mode/i }))
    await user.click(confirm)

    expect(api.getMergeCandidates).toHaveBeenCalledWith('demo', 3, '')
    expect(api.mergeIdea).toHaveBeenCalledWith('demo', 3, 7)
    await waitFor(() => expect(onMerged).toHaveBeenCalledWith(7))
  })

  it('searches by title', async () => {
    vi.mocked(api.getMergeCandidates)
      .mockResolvedValueOnce({ candidates: [] })
      .mockResolvedValue({
        candidates: [{ ...suggestion, id: 9, title: 'CSV export', similarity: null }],
      })
    renderDialog()
    const user = userEvent.setup()

    expect(await screen.findByText('Keine passenden Ideen gefunden.')).toBeInTheDocument()
    await user.type(screen.getByLabelText('Ideen durchsuchen'), 'csv')

    expect(await screen.findByRole('radio', { name: /csv export/i })).toBeInTheDocument()
    expect(api.getMergeCandidates).toHaveBeenLastCalledWith('demo', 3, 'csv')
  })

  it('shows why the server refused the merge', async () => {
    vi.mocked(api.getMergeCandidates).mockResolvedValue({ candidates: [suggestion] })
    vi.mocked(api.mergeIdea).mockRejectedValue(
      new ApiError(422, { key: 'merge_has_merged_children', message: 'x' }),
    )
    const { onMerged } = renderDialog()
    const user = userEvent.setup()

    await user.click(await screen.findByRole('radio', { name: /dark mode/i }))
    await user.click(screen.getByRole('button', { name: 'Zusammenführen' }))

    expect(
      await screen.findByText(/In diese Idee wurden schon andere Ideen zusammengeführt/),
    ).toBeInTheDocument()
    expect(onMerged).not.toHaveBeenCalled()
  })

  it('marks AI-confirmed suggestions with a badge', async () => {
    setFeatures({ ai_duplicate_confirmation: true })
    vi.mocked(api.getMergeCandidates).mockResolvedValue({
      candidates: [
        { ...suggestion, confirmed: true },
        { ...suggestion, id: 8, title: 'Dark theme toggle', confirmed: false },
      ],
      confirmation: 'available',
    })
    renderDialog()

    expect(await screen.findByRole('radio', { name: /dark mode.*ki-treffer/i })).toBeInTheDocument()
    expect(screen.getAllByText('KI-Treffer')).toHaveLength(1)
    expect(screen.queryByText(/Teil von Pro/)).not.toBeInTheDocument()
  })

  it('shows a factual plan hint with the upgrade link when confirmation needs an upgrade', async () => {
    setFeatures({ billing: true, ai_duplicate_confirmation: true })
    viewerWithRole('owner')
    vi.mocked(api.getMergeCandidates).mockResolvedValue({
      candidates: [{ ...suggestion, confirmed: null }],
      confirmation: 'upgrade_required',
    })
    render(
      <MemoryRouter>
        <MergeIdeaDialog open boardSlug="demo" ideaId={3} onClose={vi.fn()} onMerged={vi.fn()} />
      </MemoryRouter>,
    )

    expect(
      await screen.findByText(/KI-gestützte Duplikaterkennung ist Teil von Pro\./),
    ).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Tarife ansehen/ })).toBeInTheDocument()
    expect(screen.queryByText('KI-Treffer')).not.toBeInTheDocument()
  })

  it('shows the factual hint with the owner-only note instead of a link for a moderator', async () => {
    setFeatures({ billing: true, ai_duplicate_confirmation: true })
    viewerWithRole('moderator')
    vi.mocked(api.getMergeCandidates).mockResolvedValue({
      candidates: [{ ...suggestion, confirmed: null }],
      confirmation: 'upgrade_required',
    })
    render(
      <MemoryRouter>
        <MergeIdeaDialog open boardSlug="demo" ideaId={3} onClose={vi.fn()} onMerged={vi.fn()} />
      </MemoryRouter>,
    )

    expect(
      await screen.findByText(/KI-gestützte Duplikaterkennung ist Teil von Pro\./),
    ).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Tarife ansehen/ })).toBeNull()
    expect(screen.getByText('Den Tarif kann nur der Konto-Owner ändern.')).toBeInTheDocument()
  })

  it('shows no AI hint at all when no confirmer is installed', async () => {
    vi.mocked(api.getMergeCandidates).mockResolvedValue({ candidates: [suggestion] })
    renderDialog()

    expect(await screen.findByRole('radio', { name: /dark mode/i })).toBeInTheDocument()
    expect(screen.queryByText(/Teil von Pro/)).not.toBeInTheDocument()
    expect(screen.queryByText('KI-Treffer')).not.toBeInTheDocument()
  })

  it('shows no AI badge or plan hint on a Community install, even if a confirmation arrives', async () => {
    setFeatures({ billing: true })
    vi.mocked(api.getMergeCandidates).mockResolvedValue({
      candidates: [{ ...suggestion, confirmed: true }],
      confirmation: 'upgrade_required',
    })
    renderDialog()

    expect(await screen.findByRole('radio', { name: /dark mode/i })).toBeInTheDocument()
    expect(screen.queryByText('KI-Treffer')).not.toBeInTheDocument()
    expect(screen.queryByText(/Teil von Pro/)).not.toBeInTheDocument()
  })
})
