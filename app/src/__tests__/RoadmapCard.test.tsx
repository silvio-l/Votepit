/**
 * Tests for RoadmapCard — read-only Kanban-Karte (Issue 04).
 *
 * AC: kein VoteWidget; Score read-only; Karte verlinkt zur Idee.
 */

import { render, screen } from '@testing-library/react'
import { RoadmapCard } from '@votepit/ui'
import { describe, expect, it } from 'vitest'

describe('RoadmapCard', () => {
  it('renders title and read-only score with "Stimmen" label', () => {
    render(<RoadmapCard id={1} title="Dark mode" score={128} consensusPercent={75} />)

    expect(screen.getByText('Dark mode')).toBeInTheDocument()
    expect(screen.getByText('128')).toBeInTheDocument()
    expect(screen.getByText('Stimmen')).toBeInTheDocument()
  })

  it('does not render VoteWidget — no up/down vote buttons', () => {
    render(<RoadmapCard id={1} title="Dark mode" score={42} consensusPercent={75} />)

    expect(screen.queryByRole('button', { name: /upvote/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /downvote/i })).not.toBeInTheDocument()
  })

  it('links the entire card to the idea detail view when href is provided', () => {
    render(
      <RoadmapCard id={1} title="Dark mode" score={42} consensusPercent={75} href="/demo/idea/1" />,
    )

    const link = screen.getByRole('link')
    expect(link).toHaveAttribute('href', '/demo/idea/1')
  })

  it('renders without a link element when href is omitted', () => {
    render(<RoadmapCard id={1} title="Dark mode" score={42} consensusPercent={75} />)

    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
