import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { VoteWidget } from '../components/VoteWidget'

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
})
