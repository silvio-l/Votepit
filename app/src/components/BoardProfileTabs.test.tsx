/**
 * Unit tests for BoardProfileTabs (G-12): two navigating links under the profile
 * header, aria-current on the current one, hidden when the roadmap is not offered.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { BoardProfileTabs } from './BoardProfileTabs'
import { boardRoadmapVisible } from './LocalizedHeader'

const renderTabs = (current: 'ideas' | 'roadmap', showRoadmap = true) =>
  render(
    <MemoryRouter>
      <BoardProfileTabs basePath="/acme/feedback" current={current} showRoadmap={showRoadmap} />
    </MemoryRouter>,
  )

describe('BoardProfileTabs', () => {
  it('links to the ideas and roadmap pages of the board', () => {
    renderTabs('ideas')
    expect(screen.getByTestId('board-profile-tab-ideas')).toHaveAttribute('href', '/acme/feedback')
    expect(screen.getByTestId('board-profile-tab-roadmap')).toHaveAttribute(
      'href',
      '/acme/feedback/roadmap',
    )
  })

  it('marks only the current tab with aria-current="page"', () => {
    renderTabs('roadmap')
    expect(screen.getByTestId('board-profile-tab-roadmap')).toHaveAttribute('aria-current', 'page')
    expect(screen.getByTestId('board-profile-tab-ideas')).not.toHaveAttribute('aria-current')
  })

  it('is a navigation landmark, not a tablist', () => {
    renderTabs('ideas')
    expect(screen.getByRole('navigation')).toBe(screen.getByTestId('board-profile-tabs'))
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument()
  })

  it('renders nothing when the roadmap is not offered to the viewer', () => {
    renderTabs('ideas', false)
    expect(screen.queryByTestId('board-profile-tabs')).not.toBeInTheDocument()
  })
})

describe('boardRoadmapVisible', () => {
  const member = { is_admin: false } as never
  const admin = { is_admin: true } as never

  it('shows the roadmap unless the board hides it', () => {
    expect(boardRoadmapVisible(null, false, false)).toBe(true)
    expect(boardRoadmapVisible(null, false, undefined)).toBe(true)
  })

  it('hides a hidden roadmap from visitors and members', () => {
    expect(boardRoadmapVisible(null, false, true)).toBe(false)
    expect(boardRoadmapVisible(member, false, true)).toBe(false)
  })

  it('keeps a hidden roadmap for admins, but not in voter preview', () => {
    expect(boardRoadmapVisible(admin, false, true)).toBe(true)
    expect(boardRoadmapVisible(admin, true, true)).toBe(false)
  })
})
