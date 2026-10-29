/**
 * The Celebration overlay: confetti for the referral reward, sparkle for board
 * milestones. Both tear down after their lifetime, and neither mounts under
 * prefers-reduced-motion (the banner text still shows the news).
 */

import { act, render, screen } from '@testing-library/react'
import { Celebration } from '@votepit/ui'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

function setReducedMotion(reduce: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: reduce && query.includes('prefers-reduced-motion'),
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  )
}

describe('Celebration overlay', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('plays the confetti rain and removes it after its lifetime', () => {
    setReducedMotion(false)
    const { container } = render(<Celebration effect="confetti" title="Your Pro year is here" />)

    expect(container.querySelectorAll('.animate-vp-confetti-fall').length).toBeGreaterThan(0)
    expect(container.querySelectorAll('.animate-vp-sparkle-pop')).toHaveLength(0)

    act(() => {
      vi.advanceTimersByTime(1600)
    })
    expect(container.querySelectorAll('.animate-vp-confetti-fall')).toHaveLength(0)
    expect(screen.getByText('Your Pro year is here')).toBeInTheDocument()
  })

  it('plays the sparkle pop for milestones and removes it after its lifetime', () => {
    setReducedMotion(false)
    const { container } = render(<Celebration effect="sparkle" title="First idea" />)

    expect(container.querySelectorAll('.animate-vp-sparkle-pop').length).toBeGreaterThan(0)
    expect(container.querySelectorAll('.animate-vp-confetti-fall')).toHaveLength(0)

    act(() => {
      vi.advanceTimersByTime(1100)
    })
    expect(container.querySelectorAll('.animate-vp-sparkle-pop')).toHaveLength(0)
  })

  it('skips the overlay entirely under prefers-reduced-motion and keeps the banner text', () => {
    setReducedMotion(true)
    const { container } = render(<Celebration effect="confetti" title="Your Pro year is here" />)

    expect(container.querySelectorAll('.animate-vp-confetti-fall')).toHaveLength(0)
    expect(screen.getByText('Your Pro year is here')).toBeInTheDocument()
  })
})
