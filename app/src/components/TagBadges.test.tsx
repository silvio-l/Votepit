/**
 * Unit tests for TagBadges — read-only tag pills shown on the board idea
 * list and the idea detail page.
 */

import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
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
    expect(bug).toHaveStyle({ color: 'rgb(239, 68, 68)' })
    expect(feature).toHaveStyle({ color: 'rgb(59, 130, 246)' })
  })
})
