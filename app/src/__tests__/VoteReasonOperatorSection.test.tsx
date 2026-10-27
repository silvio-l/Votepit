import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { VoteReasonOperatorSection } from '../components/VoteReasonOperatorSection'
import { fetchVoteReasons, removeVoteReasonNote } from '../lib/api/ideas'

vi.mock('../lib/i18n/context', () => ({ useT: () => (key: string) => key }))
vi.mock('../lib/api/ideas', () => ({
  fetchVoteReasons: vi.fn(),
  removeVoteReasonNote: vi.fn(),
}))

const aggregate = {
  down_count: 2,
  counts: { not_needed: 0, too_expensive: 2, worsens_workflow: 0, other: 0 },
  notes: [
    { reason: 'too_expensive' as const, note: 'Zu teuer' },
    { reason: 'too_expensive' as const, note: 'Viel Aufwand' },
  ],
}

async function openSection() {
  render(<VoteReasonOperatorSection testId="idea-operator" boardSlug="demo" ideaId={5} />)
  fireEvent.click(screen.getByTestId('idea-operator-toggle'))
  await waitFor(() => expect(screen.getByTestId('idea-operator-view')).toBeTruthy())
}

describe('VoteReasonOperatorSection (owner answer 22a)', () => {
  beforeEach(() => {
    vi.mocked(fetchVoteReasons).mockReset().mockResolvedValue(aggregate)
    vi.mocked(removeVoteReasonNote).mockReset().mockResolvedValue(undefined)
  })

  it('removes one free text after confirmation and keeps the counts', async () => {
    await openSection()

    fireEvent.click(screen.getByTestId('idea-operator-note-remove-0'))
    expect(removeVoteReasonNote).not.toHaveBeenCalled()
    fireEvent.click(screen.getByTestId('idea-operator-note-confirm-confirm'))

    await waitFor(() =>
      expect(removeVoteReasonNote).toHaveBeenCalledWith('demo', 5, 'too_expensive', 'Zu teuer'),
    )
    await waitFor(() => expect(screen.queryByText('Zu teuer')).toBeNull())
    expect(screen.getByText('Viel Aufwand')).toBeTruthy()
    expect(screen.getByTestId('idea-operator-view-count-too_expensive').textContent).toBe('2')
  })

  it('cancelling the confirmation sends nothing', async () => {
    await openSection()

    fireEvent.click(screen.getByTestId('idea-operator-note-remove-1'))
    fireEvent.click(screen.getByTestId('idea-operator-note-confirm-cancel'))

    expect(removeVoteReasonNote).not.toHaveBeenCalled()
    expect(screen.getByText('Viel Aufwand')).toBeTruthy()
  })

  it('shows an alert and keeps the text when the removal fails', async () => {
    vi.mocked(removeVoteReasonNote).mockRejectedValueOnce(new Error('boom'))
    await openSection()

    fireEvent.click(screen.getByTestId('idea-operator-note-remove-0'))
    fireEvent.click(screen.getByTestId('idea-operator-note-confirm-confirm'))

    await waitFor(() => expect(screen.getByTestId('idea-operator-remove-error')).toBeTruthy())
    expect(screen.getByText('Zu teuer')).toBeTruthy()
  })
})
