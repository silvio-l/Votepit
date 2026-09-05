import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { UserVote } from '@votepit/ui'
import { VoteWidget } from '@votepit/ui'
import { describe, expect, it, vi } from 'vitest'

describe('VoteWidget', () => {
  it('renders the score', () => {
    render(<VoteWidget score={42} />)
    expect(screen.getByText('42')).toBeInTheDocument()
  })

  it('calls onVoteUp when up button is clicked', async () => {
    const onVoteUp = vi.fn()
    render(<VoteWidget score={5} onVoteUp={onVoteUp} />)
    await userEvent.click(screen.getByRole('button', { name: /upvote/i }))
    expect(onVoteUp).toHaveBeenCalledTimes(1)
  })

  it('calls onVoteDown when down button is clicked', async () => {
    const onVoteDown = vi.fn()
    render(<VoteWidget score={5} onVoteDown={onVoteDown} />)
    await userEvent.click(screen.getByRole('button', { name: /downvote/i }))
    expect(onVoteDown).toHaveBeenCalledTimes(1)
  })

  it('shows green up button class when tone=leading', () => {
    render(<VoteWidget score={10} tone="leading" />)
    const upButton = screen.getByRole('button', { name: /upvote/i })
    // bg-vp-vote-up is applied via Tailwind, check the class string
    expect(upButton.className).toContain('bg-vp-vote-up')
  })

  it('does not call onVoteUp when disabled', async () => {
    const onVoteUp = vi.fn()
    render(<VoteWidget score={5} onVoteUp={onVoteUp} disabled />)
    const upButton = screen.getByRole('button', { name: /upvote/i })
    await userEvent.click(upButton)
    expect(onVoteUp).not.toHaveBeenCalled()
  })

  function Controlled({ score, userVote }: { score: number; userVote: UserVote }) {
    return <VoteWidget score={score} userVote={userVote} />
  }

  it('does not play any vote animation on mount, even when already voted', () => {
    const { container } = render(<Controlled score={5} userVote="up" />)
    expect(container.querySelector('.animate-vp-particle-burst')).not.toBeInTheDocument()
    expect(container.querySelector('.animate-vp-confetti-piece')).not.toBeInTheDocument()
  })

  it('plays a small particle burst on an ordinary upvote', () => {
    const { container, rerender } = render(<Controlled score={4} userVote={null} />)
    rerender(<Controlled score={5} userVote="up" />)
    expect(container.querySelector('.animate-vp-particle-burst')).toBeInTheDocument()
    expect(container.querySelector('.animate-vp-confetti-piece')).not.toBeInTheDocument()
  })

  it("plays confetti instead of the small burst on the idea's first-ever vote", () => {
    const { container, rerender } = render(<Controlled score={0} userVote={null} />)
    rerender(<Controlled score={1} userVote="up" />)
    expect(container.querySelector('.animate-vp-confetti-piece')).toBeInTheDocument()
    expect(container.querySelector('.animate-vp-particle-burst')).not.toBeInTheDocument()
  })

  it('plays confetti when an upvote crosses a 10/50/100 net-vote milestone', () => {
    const { container, rerender } = render(<Controlled score={9} userVote={null} />)
    rerender(<Controlled score={10} userVote="up" />)
    expect(container.querySelector('.animate-vp-confetti-piece')).toBeInTheDocument()
  })

  it('does not play confetti for an upvote that does not cross a milestone', () => {
    const { container, rerender } = render(<Controlled score={11} userVote={null} />)
    rerender(<Controlled score={12} userVote="up" />)
    expect(container.querySelector('.animate-vp-confetti-piece')).not.toBeInTheDocument()
  })

  it('settles a flat ink-stamp ring on a downvote', () => {
    const { container, rerender } = render(<Controlled score={4} userVote={null} />)
    rerender(<Controlled score={3} userVote="down" />)
    expect(container.querySelector('.animate-vp-ink-settle')).toBeInTheDocument()
  })

  // The `.vp-vote-fx` marker is what tokens.css's `.vp-vote-stack:has(...)`
  // rule keys off to lift the whole vote column above the neighbouring rows
  // while an effect plays. Nothing in the component reads the class, so a
  // rename here would silently put the particles back under the next row.
  it('marks every spawned effect with the class the z-index lift keys off', () => {
    const { container, rerender } = render(<Controlled score={4} userVote={null} />)
    rerender(<Controlled score={5} userVote="up" />)
    expect(container.querySelector('.vp-vote-fx .animate-vp-particle-burst')).toBeInTheDocument()
    rerender(<Controlled score={4} userVote="down" />)
    expect(container.querySelector('.vp-vote-fx .animate-vp-ink-settle')).toBeInTheDocument()
    expect(container.querySelector('.vp-vote-fx .animate-vp-down-drop')).toBeInTheDocument()
  })
})
