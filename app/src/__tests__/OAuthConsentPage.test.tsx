/**
 * RTL tests for OAuthConsentPage (MCP OAuth, issue 21) — user-visible
 * behaviour: the client name is rendered as text only, approve sends the
 * chosen account/boards/levels and follows redirect_to, deny follows the
 * access_denied redirect, anon visitors are sent through login with the
 * request parked, and verified-client errors go back to the client.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OAuthConsentPage from '../pages/OAuthConsentPage'

const QUERY =
  '?response_type=code&client_id=vpc_1&redirect_uri=http%3A%2F%2F127.0.0.1%3A3000%2Fcb' +
  '&code_challenge=abc&code_challenge_method=S256&state=st&scope=read%20write'

const BOOTSTRAP_USER = { csrf_token: 'csrf', user: { id: 1, is_admin: false } }
const BOOTSTRAP_ANON = { csrf_token: 'csrf', user: null }

const CONTEXT = {
  client: {
    client_id: 'vpc_1',
    name: '<img src=x onerror=alert(1)>',
    redirect_uri: 'http://127.0.0.1:3000/cb',
  },
  max_scope: 'write',
  accounts: [
    {
      slug: 'acme',
      name: 'Acme',
      reason: null,
      boards: [
        { slug: 'feedback', name: 'Feedback' },
        { slug: 'roadmap', name: 'Roadmap' },
      ],
    },
    { slug: 'small', name: 'Small Co', reason: 'plan_limit_agent_api', boards: [] },
  ],
}

type Handler = (url: string, init?: RequestInit) => { status?: number; body: object }

let assign: ReturnType<typeof vi.fn>
const originalLocation = window.location

function mockFetch(handler: Handler) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const { status = 200, body } = handler(url, init)
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderPage(query = QUERY) {
  return render(
    <MemoryRouter initialEntries={[`/oauth/authorize${query}`]}>
      <Routes>
        <Route path="/oauth/authorize" element={<OAuthConsentPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
  assign = vi.fn()
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...originalLocation, assign },
  })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
})

describe('OAuthConsentPage', () => {
  it('renders the client name as plain text and only eligible accounts as choices', async () => {
    mockFetch((url) => (url.includes('bootstrap') ? { body: BOOTSTRAP_USER } : { body: CONTEXT }))
    const { container } = renderPage()

    expect(
      await screen.findByText('<img src=x onerror=alert(1)> möchte auf Votepit zugreifen'),
    ).toBeInTheDocument()
    expect(container.querySelector('img')).toBeNull()
    expect(screen.getByText('http://127.0.0.1:3000/cb')).toBeInTheDocument()
    expect(
      screen.getByRole('option', {
        name: 'Small Co (Agent-API im aktuellen Tarif nicht enthalten)',
      }),
    ).toBeDisabled()
    // Nothing selected yet → approve disabled.
    expect(screen.getByRole('button', { name: 'Zugriff erlauben' })).toBeDisabled()
  })

  it('approves with the chosen boards and levels and follows redirect_to', async () => {
    const fetchSpy = mockFetch((url, init) => {
      if (url.includes('bootstrap')) return { body: BOOTSTRAP_USER }
      if (init?.method === 'POST')
        return { body: { redirect_to: 'http://127.0.0.1:3000/cb?code=c&state=st' } }
      return { body: CONTEXT }
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('checkbox', { name: 'Feedback' }))
    await user.selectOptions(screen.getByLabelText('Berechtigung für Feedback'), 'write')
    // A level above the client's request is never offered.
    expect(screen.queryByRole('option', { name: 'Moderieren' })).toBeNull()
    await user.click(screen.getByRole('button', { name: 'Zugriff erlauben' }))

    await waitFor(() =>
      expect(assign).toHaveBeenCalledWith('http://127.0.0.1:3000/cb?code=c&state=st'),
    )
    const post = fetchSpy.mock.calls.find(([, init]) => init?.method === 'POST')
    const body = JSON.parse(String(post?.[1]?.body))
    expect(body).toMatchObject({
      client_id: 'vpc_1',
      state: 'st',
      decision: 'approve',
      account: 'acme',
      boards: [{ slug: 'feedback', scope: 'write' }],
    })
    expect(localStorage.getItem('vp_oauth_pending')).toBeNull()
  })

  it('deny follows the access_denied redirect', async () => {
    mockFetch((url, init) => {
      if (url.includes('bootstrap')) return { body: BOOTSTRAP_USER }
      if (init?.method === 'POST')
        return { body: { redirect_to: 'http://127.0.0.1:3000/cb?error=access_denied' } }
      return { body: CONTEXT }
    })
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Ablehnen' }))

    await waitFor(() =>
      expect(assign).toHaveBeenCalledWith('http://127.0.0.1:3000/cb?error=access_denied'),
    )
  })

  it('sends an anonymous visitor through login and resumes the parked request', async () => {
    mockFetch(() => ({ body: BOOTSTRAP_ANON }))
    renderPage()

    expect(await screen.findByTestId('login-page')).toBeInTheDocument()
    const parked = JSON.parse(localStorage.getItem('vp_oauth_pending') ?? '{}')
    expect(parked.params.client_id).toBe('vpc_1')

    // After login the page comes back with ?resume=1 and replays the parked request.
    const fetchSpy = mockFetch((url) =>
      url.includes('bootstrap') ? { body: BOOTSTRAP_USER } : { body: CONTEXT },
    )
    renderPage('?resume=1')

    expect(await screen.findAllByText(/möchte auf Votepit zugreifen/)).not.toHaveLength(0)
    const contextCall = fetchSpy.mock.calls.find(([input]) =>
      String(input).includes('/api/oauth/authorize'),
    )
    expect(String(contextCall?.[0])).toContain('client_id=vpc_1')
  })

  it('returns a verified-client error to the client', async () => {
    mockFetch((url) =>
      url.includes('bootstrap')
        ? { body: BOOTSTRAP_USER }
        : {
            status: 400,
            body: {
              error: {
                key: 'invalid_scope',
                message: 'bad scope',
                redirect_to: 'http://127.0.0.1:3000/cb?error=invalid_scope',
              },
            },
          },
    )
    renderPage()

    await waitFor(() =>
      expect(assign).toHaveBeenCalledWith('http://127.0.0.1:3000/cb?error=invalid_scope'),
    )
  })

  it('shows an error for an unknown client instead of redirecting', async () => {
    mockFetch((url) =>
      url.includes('bootstrap')
        ? { body: BOOTSTRAP_USER }
        : { status: 400, body: { error: { key: 'invalid_client', message: 'Unknown client.' } } },
    )
    renderPage()

    expect(await screen.findByText('Anfrage ungültig')).toBeInTheDocument()
    expect(assign).not.toHaveBeenCalled()
  })
})
