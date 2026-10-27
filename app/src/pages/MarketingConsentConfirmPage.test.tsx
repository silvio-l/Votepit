/**
 * RTL tests for the product-news confirm page: a repeat click on an already
 * confirmed link shows the success state, a rejected link shows the error state.
 */

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MarketingConsentConfirmPage from './MarketingConsentConfirmPage'

function mockConfirmResponse(status: number, body: unknown) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/account/marketing-consent/confirm?token=abc123']}>
      <MarketingConsentConfirmPage />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('MarketingConsentConfirmPage', () => {
  it('shows success when the link is confirmed', async () => {
    mockConfirmResponse(200, { ok: true, status: 'confirmed' })

    renderPage()

    expect(await screen.findByTestId('marketing-consent-confirm-done-link')).toBeTruthy()
    expect(screen.queryByTestId('marketing-consent-confirm-error-link')).toBeNull()
  })

  it('shows success on a second click, when the consent was already confirmed', async () => {
    // The server answers 200 for a repeat click (consent already confirmed for the session owner).
    mockConfirmResponse(200, { ok: true, status: 'confirmed' })

    renderPage()

    expect(await screen.findByTestId('marketing-consent-confirm-done-link')).toBeTruthy()
  })

  it('shows the error state for a rejected link', async () => {
    mockConfirmResponse(400, { error: { key: 'invalid_token', message: 'x' } })

    renderPage()

    expect(await screen.findByTestId('marketing-consent-confirm-error-link')).toBeTruthy()
    expect(screen.queryByTestId('marketing-consent-confirm-done-link')).toBeNull()
  })
})
