/**
 * Unit tests for StarFeedbackPrompt — the account-admin "how's it going +
 * star us" nudge. Covers the time-gate (nothing before the 3-day threshold),
 * the rating step, and the edition gate on the star ask (community only).
 */

import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setEdition } from '../lib/edition'
import { StarFeedbackPrompt } from './StarFeedbackPrompt'

const STORAGE_KEY = 'vp_star_feedback_prompt_v1'
const THREE_DAYS_MS = 3 * 24 * 60 * 60 * 1000

afterEach(() => {
  localStorage.removeItem(STORAGE_KEY)
  setEdition('self-host') // reset to Community defaults between tests
  vi.useRealTimers()
})

describe('StarFeedbackPrompt', () => {
  it('renders nothing on a brand-new visitor (before the 3-day threshold)', () => {
    const { container } = render(<StarFeedbackPrompt />)
    expect(container).toBeEmptyDOMElement()
  })

  it('renders the rating prompt once the 3-day threshold has passed', () => {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        firstSeenAt: Date.now() - THREE_DAYS_MS - 1000,
        dismissedUntil: null,
        done: false,
      }),
    )

    render(<StarFeedbackPrompt />)

    expect(screen.getByText(/Wie zufrieden bist du mit Votepit\?/)).toBeInTheDocument()
  })

  it('offers the GitHub-star ask after a positive rating on a community-edition instance', async () => {
    setEdition('self-host')
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        firstSeenAt: Date.now() - THREE_DAYS_MS - 1000,
        dismissedUntil: null,
        done: false,
      }),
    )
    const user = userEvent.setup()

    render(<StarFeedbackPrompt />)
    // The button's emoji and label are separate text nodes (`🙂 {label}`,
    // no wrapping element around just the label) — getByText('Gut') can
    // never exact-match either node alone. Match by accessible name
    // instead, which is computed from the whole button's text content.
    await user.click(screen.getByRole('button', { name: /Gut/ }))

    expect(screen.getByRole('link', { name: /GitHub/ })).toHaveAttribute(
      'href',
      'https://github.com/silvio-l/Votepit',
    )
  })

  it('never shows the GitHub-star ask on a cloud instance, even after a positive rating', async () => {
    setEdition('cloud')
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        firstSeenAt: Date.now() - THREE_DAYS_MS - 1000,
        dismissedUntil: null,
        done: false,
      }),
    )
    const user = userEvent.setup()

    const { container } = render(<StarFeedbackPrompt />)
    await user.click(screen.getByRole('button', { name: /Gut/ }))

    expect(container).toBeEmptyDOMElement()
  })
})
