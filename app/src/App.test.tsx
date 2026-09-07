/**
 * RTL tests for App.tsx's cloud multi-tenant routing shape (cloud path
 * routing, SPA half). Verifies that:
 *  - the app shows a loading gate until /api/bootstrap resolves with a
 *    routing_mode,
 *  - global routes (e.g. /login) render identically in both modes,
 *  - scoped routes only pick up the module-level account context (see
 *    accountContext.ts) from the URL in cloud mode.
 *
 * fetch is mocked globally with a generic success stub — most assertions
 * here are about which route tree matches, not about page content, so child
 * pages' own API calls are allowed to resolve to harmless empty payloads.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App'
import { getAccountSlug, setAccountSlug } from './lib/accountContext'

function mockFetchWithRoutingMode(routingMode: 'self-host' | 'cloud') {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : input.toString()

    if (url.includes('/api/bootstrap')) {
      return new Response(
        JSON.stringify({
          csrf_token: 'test-csrf',
          user: null,
          routing_mode: routingMode,
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      )
    }

    // Any other endpoint (child-page data fetches) — generic empty success,
    // just enough for pages not to crash while we assert on routing shape.
    return new Response(JSON.stringify({}), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

describe('App — cloud multi-tenant routing', () => {
  beforeEach(() => {
    setAccountSlug(null)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('shows a loading gate before bootstrap resolves', () => {
    mockFetchWithRoutingMode('self-host')
    render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    expect(screen.getByText(/lädt|loading/i)).toBeInTheDocument()
  })

  it('renders the global /login route in self-host mode', async () => {
    mockFetchWithRoutingMode('self-host')
    render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    await waitFor(() => expect(document.getElementById('login-email')).toBeInTheDocument())
    expect(getAccountSlug()).toBeNull()
  })

  it('renders the global /login route in cloud mode', async () => {
    mockFetchWithRoutingMode('cloud')
    render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    await waitFor(() => expect(document.getElementById('login-email')).toBeInTheDocument())
    expect(getAccountSlug()).toBeNull()
  })

  it('picks up the :accountSlug segment for scoped routes in cloud mode', async () => {
    mockFetchWithRoutingMode('cloud')
    render(
      <MemoryRouter initialEntries={['/acme/admin/members']}>
        <App />
      </MemoryRouter>,
    )
    await waitFor(() => expect(getAccountSlug()).toBe('acme'))
  })

  it('never sets an account slug for scoped routes in self-host mode', async () => {
    mockFetchWithRoutingMode('self-host')
    render(
      <MemoryRouter initialEntries={['/admin/members']}>
        <App />
      </MemoryRouter>,
    )
    // Give the scoped route a tick to mount before asserting the negative.
    await waitFor(() => expect(screen.queryByText(/lädt|loading/i)).not.toBeInTheDocument())
    expect(getAccountSlug()).toBeNull()
  })
})
