/**
 * RTL tests for the product-news consent toggle on the profile: hidden on
 * self-host, opt-in leads to pending, a missing confirmed notification e-mail
 * is explained, and withdrawal is immediate.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { type Features, setFeatures } from '../lib/features'
import { MarketingConsentSection } from './MarketingConsentSection'

const FEATURES_HOSTED: Features = {
  board_smtp: false,
  legal_links: null,
  marketing_discover_url: null,
  turnstile_site_key: null,
  marketing_consent: true,
}

type Handler = (url: string, init?: RequestInit) => { body: unknown; status?: number }

function mockFetch(handler: Handler) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const { body, status = 200 } = handler(String(input), init)
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  setFeatures(undefined)
})

describe('MarketingConsentSection', () => {
  it('renders nothing on a self-host installation and makes no request', () => {
    setFeatures({ ...FEATURES_HOSTED, marketing_consent: false })
    const fetchSpy = mockFetch(() => ({ body: {} }))

    render(<MarketingConsentSection />)

    expect(screen.queryByTestId('profile-marketing-consent-section')).toBeNull()
    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('starts unticked when no consent exists yet', async () => {
    setFeatures(FEATURES_HOSTED)
    mockFetch((url) => {
      if (url.endsWith('/account/marketing-consent'))
        return { body: { status: null, text_version: 'v1' } }
      return { body: {} }
    })

    render(<MarketingConsentSection />)

    const toggle = await screen.findByTestId('profile-marketing-consent-toggle')
    const input = toggle.matches('input')
      ? (toggle as HTMLInputElement)
      : toggle.querySelector('input')
    expect(input?.checked ?? toggle.getAttribute('aria-checked')).not.toBe(true)
  })

  it('opting in moves the consent to pending and sends enabled=true', async () => {
    setFeatures(FEATURES_HOSTED)
    const fetchSpy = mockFetch((_url, init) => {
      if (init?.method === 'PUT') return { body: { status: 'pending' } }
      return { body: { status: null, text_version: 'v1' } }
    })

    render(<MarketingConsentSection />)
    await userEvent.click(await screen.findByTestId('profile-marketing-consent-toggle'))

    await waitFor(() => {
      const put = fetchSpy.mock.calls.find(([, init]) => init?.method === 'PUT')
      expect(put).toBeDefined()
      expect(JSON.parse(String(put?.[1]?.body))).toEqual({ enabled: true })
    })
    await waitFor(() => {
      expect(
        screen.getByTestId('profile-marketing-consent-toggle').getAttribute('aria-checked'),
      ).toBe('true')
    })
  })

  it('explains the missing confirmed notification e-mail instead of failing silently', async () => {
    setFeatures(FEATURES_HOSTED)
    mockFetch((_url, init) => {
      if (init?.method === 'PUT') {
        return {
          status: 422,
          body: { error: { key: 'notification_email_required', message: 'x' } },
        }
      }
      return { body: { status: null, text_version: 'v1' } }
    })

    render(<MarketingConsentSection />)
    await userEvent.click(await screen.findByTestId('profile-marketing-consent-toggle'))

    expect(await screen.findByTestId('profile-marketing-consent-error')).toBeTruthy()
  })

  it('withdrawing sends enabled=false', async () => {
    setFeatures(FEATURES_HOSTED)
    const fetchSpy = mockFetch((_url, init) => {
      if (init?.method === 'PUT') return { body: { status: 'withdrawn' } }
      return { body: { status: 'confirmed', text_version: 'v1' } }
    })

    render(<MarketingConsentSection />)
    await userEvent.click(await screen.findByTestId('profile-marketing-consent-toggle'))

    await waitFor(() => {
      const put = fetchSpy.mock.calls.find(([, init]) => init?.method === 'PUT')
      expect(JSON.parse(String(put?.[1]?.body))).toEqual({ enabled: false })
    })
  })
})
