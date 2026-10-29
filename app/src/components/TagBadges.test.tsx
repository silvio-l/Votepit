/**
 * Unit tests for TagBadges — read-only tag pills shown on the board idea
 * list and the idea detail page.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { tagTextColor } from '../lib/branding'
import { TagBadges } from './TagBadges'

describe('TagBadges', () => {
  it('renders nothing when there are no tags', () => {
    const { container } = render(<TagBadges tags={[]} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('renders one pill per tag with its name and color', () => {
    render(
      <TagBadges
        tags={[
          { id: 1, name: 'Bug', color: '#ef4444' },
          { id: 2, name: 'Feature', color: '#3b82f6' },
        ]}
      />,
    )

    const bug = screen.getByText('Bug')
    const feature = screen.getByText('Feature')
    expect(bug).toBeInTheDocument()
    expect(feature).toBeInTheDocument()
    // Light theme: the text is the tag colour darkened until it reaches 4.5:1 on the card.
    expect(bug).toHaveStyle({ color: tagTextColor('#ef4444', 'light') })
    expect(feature).toHaveStyle({ color: tagTextColor('#3b82f6', 'light') })
  })

  it('renders each tag as a filter link to the board when a board slug is given (P-34)', () => {
    render(
      <MemoryRouter>
        <TagBadges
          boardSlug="demo"
          testIdPrefix="board-row"
          tags={[
            { id: 1, name: 'Bug', color: '#ef4444' },
            { id: 2, name: 'UX', color: '#22c55e' },
          ]}
        />
      </MemoryRouter>,
    )

    const bug = screen.getByTestId('board-row-tag-1')
    expect(bug.tagName).toBe('A')
    expect(bug).toHaveAttribute('href', '/demo?tag_id=1')
    expect(bug).toHaveTextContent('Bug')
    expect(screen.getByTestId('board-row-tag-2')).toHaveAttribute('href', '/demo?tag_id=2')
  })
})
