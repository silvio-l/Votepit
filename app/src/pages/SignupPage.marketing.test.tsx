/**
 * RTL tests for the signup product-news checkbox: hidden on self-host, unticked
 * by default, and the magic-link request carries the tick only when it is set.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { type Features, setFeatures } from '../lib/features'
import SignupPage from './SignupPage'

const FEATURES_HOSTED: Features = {
  board_smtp: false,
  legal_links: null,
  marketing_discover_url: null,
  turnstile_site_key: null,
  marketing_consent: true,
}

function mockFetch() {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    const body = url.includes('/bootstrap') ? { user: null, oauth_providers: [] } : { ok: true }
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderPage() {
  return render(
    <MemoryRouter>
      <SignupPage />
    </MemoryRouter>,
  )
}

async function submitEmail(address: string) {
  await userEvent.type(await screen.findByTestId('signup-email-input'), address)
  await userEvent.click(screen.getByTestId('signup-submit'))
}

function loginBody(fetchSpy: ReturnType<typeof mockFetch>): Record<string, unknown> {
  const call = fetchSpy.mock.calls.find(([input]) => String(input).endsWith('/login'))
  expect(call).toBeDefined()
  return JSON.parse(String(call?.[1]?.body))
}

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  setFeatures(undefined)
})

describe('SignupPage product-news checkbox', () => {
  it('is not shown on a self-host installation', async () => {
    setFeatures({ ...FEATURES_HOSTED, marketing_consent: false })
    mockFetch()

    renderPage()

    await screen.findByTestId('signup-email-input')
    expect(screen.queryByTestId('signup-product-news')).toBeNull()
    expect(screen.queryByTestId('signup-product-news-notice')).toBeNull()
  })

  it('is shown unticked with the § 7 Abs. 3 notice on a hosted installation', async () => {
    setFeatures(FEATURES_HOSTED)
    mockFetch()

    renderPage()

    await screen.findByTestId('signup-product-news')
    expect(screen.getByTestId('signup-product-news-notice').textContent ?? '').not.toBe('')
    expect(screen.getByTestId('signup-product-news').getAttribute('aria-checked')).not.toBe('true')
  })

  it('sends product_news=false when the box is left unticked', async () => {
    setFeatures(FEATURES_HOSTED)
    const fetchSpy = mockFetch()

    renderPage()
    await submitEmail('plain@example.com')

    await waitFor(() =>
      expect(fetchSpy.mock.calls.some(([input]) => String(input).endsWith('/login'))).toBe(true),
    )
    expect(loginBody(fetchSpy)).toMatchObject({
      email: 'plain@example.com',
      r: '/signup/account',
      product_news: false,
    })
  })

  it('sends product_news=true when the box is ticked', async () => {
    setFeatures(FEATURES_HOSTED)
    const fetchSpy = mockFetch()

    renderPage()
    await userEvent.click(await screen.findByTestId('signup-product-news'))
    await submitEmail('ticked@example.com')

    await waitFor(() =>
      expect(fetchSpy.mock.calls.some(([input]) => String(input).endsWith('/login'))).toBe(true),
    )
    expect(loginBody(fetchSpy)).toMatchObject({ email: 'ticked@example.com', product_news: true })
  })

  it('sends no product_news field on a self-host installation', async () => {
    setFeatures({ ...FEATURES_HOSTED, marketing_consent: false })
    const fetchSpy = mockFetch()

    renderPage()
    await submitEmail('selfhost@example.com')

    await waitFor(() =>
      expect(fetchSpy.mock.calls.some(([input]) => String(input).endsWith('/login'))).toBe(true),
    )
    expect(loginBody(fetchSpy)).not.toHaveProperty('product_news')
  })
})
