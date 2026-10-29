/**
 * RTL tests for SignupPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made. This page reuses the
 * magic-link mechanism verbatim (bootstrap + POST /login with a fixed
 * `r=/signup/account` return-to), so the test shape mirrors LoginPage.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SignupPage from '../pages/SignupPage'

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null, oauth_providers: [] }

function mockFetchSuccess() {
  const responses = [
    new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
    new Response(JSON.stringify({ ok: true }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
  ]
  let idx = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    return responses[idx++] ?? responses[responses.length - 1]
  })
}

function renderSignupPage() {
  return render(
    <MemoryRouter initialEntries={['/signup']}>
      <Routes>
        <Route path="/signup" element={<SignupPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
        <Route path="/" element={<div data-testid="root-page" />} />
        <Route path="/signup/account" element={<div data-testid="signup-account-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('SignupPage', () => {
  it('renders email input and submit button', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderSignupPage()

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /magic-link senden/i })).toBeInTheDocument()
  })

  it('shows "Link gesendet" confirmation after successful submit, using the r=/signup/account return-to', async () => {
    mockFetchSuccess()
    renderSignupPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'new-owner@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByText('Link gesendet')).toBeInTheDocument())
    expect(screen.getByText(/new-owner@example.com/)).toBeInTheDocument()

    // The POST body must carry the signup return-to path, not a board path.
    const loginCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(
        ([input]) => String(input).includes('/login') && !String(input).includes('verify'),
      )
    expect(loginCall).toBeDefined()
    const body = JSON.parse(String((loginCall?.[1] as RequestInit)?.body))
    expect(body.r).toBe('/signup/account')
  })

  it('links to plain /login for existing accounts (no r=/signup/account — that would bounce a normal login back into the "already has an account" dead-end)', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderSignupPage()

    const link = screen.getByRole('link', { name: /anmelden/i })
    expect(link).toHaveAttribute('href', '/login')
  })

  it('shows error message when the request fails', async () => {
    const responses = [
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
      new Response(
        JSON.stringify({ error: { key: 'rate_limited', message: 'Zu viele Anfragen.' } }),
        { status: 429, headers: { 'Content-Type': 'application/json' } },
      ),
    ]
    let idx = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      return responses[idx++] ?? responses[responses.length - 1]
    })
    renderSignupPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'test@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Zu viele Anfragen.')
  })

  it('renders no OAuth buttons when the server reports no configured providers', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderSignupPage()

    expect(screen.queryByRole('link', { name: /google/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /github/i })).not.toBeInTheDocument()
  })

  /**
   * Regression (2026-09-10): a visitor reaching /signup while already
   * authenticated but not yet owning any account (ProfilePage's opt-in
   * "create your own board" empty-state CTA, see RootRedirectPage's class
   * doc) used to be bounced to '/' unconditionally — RootRedirectPage then
   * sends a no-membership user straight back to /profile, silently undoing
   * the button they just clicked (reproduced in production:
   * lindstedt.online@gmail.com, freshly stripped of its erroneous default-
   * account ownership, could no longer reach the account-creation wizard
   * at all).
   */
  it('sends an authenticated visitor with no account yet straight to the account picker instead of bouncing via /', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          ...BOOTSTRAP_RESPONSE,
          user: { id: 1, memberships: [] },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    renderSignupPage()

    expect(await screen.findByTestId('signup-account-page')).toBeInTheDocument()
  })

  it('sends an authenticated visitor who already owns an account to / instead of the account picker', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          ...BOOTSTRAP_RESPONSE,
          user: { id: 1, memberships: [{ account_slug: 'acme', role: 'owner' }] },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    renderSignupPage()

    expect(await screen.findByTestId('root-page')).toBeInTheDocument()
  })

  it('renders OAuth buttons for configured providers, carrying r=/signup/account so a new OAuth user lands on the account picker', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({ ...BOOTSTRAP_RESPONSE, oauth_providers: ['google', 'github'] }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    renderSignupPage()

    const google = await screen.findByRole('link', { name: /google/i })
    const github = screen.getByRole('link', { name: /github/i })
    expect(google).toHaveAttribute('href', '/login/oauth/google/start?r=%2Fsignup%2Faccount')
    expect(github).toHaveAttribute('href', '/login/oauth/github/start?r=%2Fsignup%2Faccount')
  })
})
