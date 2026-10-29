/**
 * RTL tests for the DS IdeaRow (G-09): the title is the only link, the vote buttons
 * are siblings that report their direction, readonly rows block votes, net and
 * approval come from the shared vote arithmetic, and a custom status label wins.
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { IdeaRow } from '@votepit/ui/ds'
import { describe, expect, it, vi } from 'vitest'

const base = {
  title: 'Dark mode for the dashboard',
  href: '/demo-product/idea/7',
  status: 'open' as const,
  statusLabel: 'Open',
  up: 146,
  down: 12,
  commentCount: 3,
  commentLabel: (n: number) => `${n} comments`,
  voteLabels: {
    up: { verb: 'Vote for', label: 'For' },
    down: { verb: 'Vote against', label: 'Against' },
    votesSingular: 'vote',
    votesPlural: 'votes',
    error: 'Could not save your vote',
    readonly: 'Voting is closed',
  },
  summaryLabels: {
    up: 'For',
    down: 'Against',
    net: 'Net',
    approval: 'Approval',
    empty: 'No votes yet',
    explanation: 'Approval is the share of votes for.',
    fewVotes: 'few votes',
  },
  locale: 'en-US',
  testId: 'board-scale-idea-7',
}

describe('DS IdeaRow', () => {
  it('links the title to the detail route and nowhere else', () => {
    render(<IdeaRow {...base} />)

    const link = screen.getByTestId('board-scale-idea-7-link')
    expect(link).toHaveAttribute('href', '/demo-product/idea/7')
    expect(link).toHaveTextContent('Dark mode for the dashboard')
    expect(screen.getAllByRole('link')).toHaveLength(1)
  })

  it('gives the one-line title its full text as a title attribute, since it is ellipsised', () => {
    render(<IdeaRow {...base} />)

    expect(screen.getByTestId('board-scale-idea-7-link').closest('h3')).toHaveAttribute(
      'title',
      'Dark mode for the dashboard',
    )
  })

  it('shows absolute For and Against counts on separate buttons', async () => {
    const onVote = vi.fn()
    render(<IdeaRow {...base} onVote={onVote} />)

    const up = screen.getByTestId('board-scale-idea-7-vote-up')
    const down = screen.getByTestId('board-scale-idea-7-vote-down')
    expect(up).toHaveTextContent('146')
    expect(down).toHaveTextContent('12')

    await userEvent.click(up)
    expect(onVote).toHaveBeenLastCalledWith('up')
    await userEvent.click(down)
    expect(onVote).toHaveBeenLastCalledWith('down')
  })

  it('reports the committed vote through aria-pressed', () => {
    render(<IdeaRow {...base} votedUp onVote={vi.fn()} />)

    expect(screen.getByTestId('board-scale-idea-7-vote-up')).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('board-scale-idea-7-vote-down')).toHaveAttribute(
      'aria-pressed',
      'false',
    )
  })

  it('blocks votes on a readonly row and when no handler is given', async () => {
    const onVote = vi.fn()
    const { unmount } = render(<IdeaRow {...base} readonlyVotes onVote={onVote} />)
    await userEvent.click(screen.getByTestId('board-scale-idea-7-vote-up'))
    expect(onVote).not.toHaveBeenCalled()
    expect(screen.getByTestId('board-scale-idea-7-vote-up')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
    unmount()

    render(<IdeaRow {...base} />)
    expect(screen.getByTestId('board-scale-idea-7-vote-down')).toHaveAttribute(
      'aria-disabled',
      'true',
    )
  })

  it('shows net and approval from the shared arithmetic', () => {
    render(<IdeaRow {...base} />)

    expect(screen.getByTestId('board-scale-idea-7-summary-net')).toHaveTextContent('+134')
    expect(screen.getByTestId('board-scale-idea-7-summary-approval')).toHaveTextContent('92')
  })

  it('shows an empty idea as no votes yet, without a percentage', () => {
    render(<IdeaRow {...base} up={0} down={0} commentCount={0} />)

    expect(screen.getByTestId('board-scale-idea-7-summary')).toHaveTextContent('No votes yet')
    expect(screen.queryByTestId('board-scale-idea-7-summary-approval')).not.toBeInTheDocument()
  })

  it('uses a custom status label and keeps the status word', () => {
    render(<IdeaRow {...base} status="planned" statusLabel="Shipping next" />)

    expect(screen.getByTestId('board-scale-idea-7')).toHaveAttribute('data-status', 'planned')
    expect(screen.getByText('Shipping next')).toBeInTheDocument()
  })

  it('names the comment count with the caller text', () => {
    render(<IdeaRow {...base} commentLabel={(n) => `${n} comments`} />)

    expect(screen.getByTestId('board-scale-idea-7-comments')).toHaveTextContent('3 comments')
  })

  it('renders the phone variant with the same title, votes and comments', () => {
    render(<IdeaRow {...base} variant="phone" excerpt="Long sessions in the evening" />)

    const row = screen.getByTestId('board-scale-idea-7')
    expect(row).toHaveAttribute('data-variant', 'phone')
    expect(screen.getByText('Long sessions in the evening')).toBeInTheDocument()
    expect(screen.getByTestId('board-scale-idea-7-vote-up')).toBeInTheDocument()
    expect(screen.getByTestId('board-scale-idea-7-comments')).toBeInTheDocument()
  })

  it('shows the author line under the title on desktop and in the status line on phones', () => {
    const { unmount } = render(<IdeaRow {...base} authorLine="Mia · vor 2 Tagen" />)
    const desktopLine = screen.getByText('Mia · vor 2 Tagen')
    expect(desktopLine.closest('.ds-idea-row__author')?.parentElement).toHaveClass(
      'ds-idea-row__main',
    )
    unmount()

    render(<IdeaRow {...base} variant="phone" authorLine="Mia · vor 2 Tagen" />)
    expect(screen.getByText('Mia · vor 2 Tagen').closest('.ds-idea-row__meta')).not.toBeNull()
  })

  it('renders the translate control next to the comment count', () => {
    render(
      <IdeaRow
        {...base}
        translateControl={<button type="button" data-testid="board-scale-idea-7-translate" />}
      />,
    )

    const toggle = screen.getByTestId('board-scale-idea-7-translate')
    expect(toggle.parentElement).toBe(
      screen.getByTestId('board-scale-idea-7-comments').parentElement,
    )
  })

  it('shows the few-votes hint when every vote is a guest vote (P-02, 17a)', () => {
    // Guest votes are ordinary vote rows, so three guest votes read like three voters.
    render(<IdeaRow {...base} up={3} down={0} />)

    expect(screen.getByTestId('board-scale-idea-7-summary-few')).toHaveTextContent('few votes')
  })

  it('shows no few-votes hint for a row with no votes', () => {
    render(<IdeaRow {...base} up={0} down={0} />)

    expect(screen.queryByTestId('board-scale-idea-7-summary-few')).toBeNull()
  })
})

describe('DS IdeaRow pinned edge', () => {
  it('marks a pinned row so a board accent can replace its status edge', () => {
    const { rerender } = render(<IdeaRow {...base} />)
    expect(screen.getByTestId('board-scale-idea-7')).not.toHaveAttribute('data-pinned')

    rerender(<IdeaRow {...base} pinnedLabel="Pinned" />)
    expect(screen.getByTestId('board-scale-idea-7')).toHaveAttribute('data-pinned', 'true')
  })
})
