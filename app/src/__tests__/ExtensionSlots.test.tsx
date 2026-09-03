/**
 * RTL tests for the SPA extension slots (`appExtensions.slots`): core's
 * fixed mount points render whatever the extension registry provides, and
 * nothing when it provides nothing. The registry module is mocked in place
 * of the alias so the test does not depend on a downstream build.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import LoginPage from '../pages/LoginPage'

const slots: { appBanner?: React.ReactElement; loginFooter?: React.ReactElement } = {}

vi.mock('@votepit/app-extensions', () => ({
  appExtensions: {
    scopedRoutes: [],
    adminNavLinks: [],
    globalRoutes: [],
    dictionaries: {},
    // Read lazily so each test can set the slots before rendering.
    get slots() {
      return slots
    },
  },
}))

function mockFetch() {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : input.toString()
    const body = url.includes('/api/bootstrap')
      ? { csrf_token: 'test-csrf', user: null, routing_mode: 'self-host', features: {} }
      : {}
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

describe('extension slots', () => {
  beforeEach(() => {
    mockFetch()
    slots.appBanner = undefined
    slots.loginFooter = undefined
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the appBanner slot above every page', async () => {
    slots.appBanner = <div data-testid="ext-banner">Installation notice</div>
    render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByTestId('ext-banner')).toBeInTheDocument())
  })

  it('renders nothing for an empty appBanner slot', async () => {
    render(
      <MemoryRouter initialEntries={['/login']}>
        <App />
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument())
    expect(screen.queryByTestId('ext-banner')).not.toBeInTheDocument()
  })

  it('renders the loginFooter slot below the sign-in forms', async () => {
    slots.loginFooter = <button type="button">Alternative sign-in</button>
    render(
      <MemoryRouter initialEntries={['/login']}>
        <LoginPage />
      </MemoryRouter>,
    )
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Alternative sign-in' })).toBeInTheDocument(),
    )
  })

  it('renders no footer when the slot is empty', async () => {
    render(
      <MemoryRouter initialEntries={['/login']}>
        <LoginPage />
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Alternative sign-in' })).not.toBeInTheDocument()
  })
})
