/**
 * RTL tests for LoginPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made.
 * bootstrap() is mocked to return an anonymous session (seeds CSRF token).
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginPage from '../pages/LoginPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

/** Mocks fetch: first call = bootstrap, second = magic-link response. */
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

/** Mocks fetch: bootstrap succeeds, magic-link POST returns 429 rate-limit. */
function mockFetchRateLimit() {
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
}

function renderLoginPage(initialPath = '/login') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('LoginPage', () => {
  it('renders email input and submit button', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderLoginPage()

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /magic-link senden/i })).toBeInTheDocument()
  })

  it('shows "Link gesendet" confirmation after successful submit', async () => {
    mockFetchSuccess()
    renderLoginPage()

    const user = userEvent.setup()
    const input = screen.getByLabelText(/E-Mail-Adresse/i)
    await user.type(input, 'test@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByText('Link gesendet')).toBeInTheDocument())
    // Email shown in the confirmation
    expect(screen.getByText(/test@example.com/)).toBeInTheDocument()
    // Retry link appears
    expect(screen.getByRole('link', { name: /erneut versuchen/i })).toBeInTheDocument()
  })

  it('shows error message when request fails', async () => {
    mockFetchRateLimit()
    renderLoginPage()

    const user = userEvent.setup()
    const input = screen.getByLabelText(/E-Mail-Adresse/i)
    await user.type(input, 'test@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Zu viele Anfragen.')
  })

  it('disables submit button while submitting', async () => {
    // Delay the magic-link POST so we can observe the submitting state.
    let resolvePost!: () => void
    const postPromise = new Promise<void>((resolve) => {
      resolvePost = resolve
    })

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      // Block the login POST until resolved
      await postPromise
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderLoginPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'test@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    // During submission, button text changes
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /wird gesendet/i })).toBeDisabled(),
    )

    resolvePost()
  })

  it('submit button is disabled when email is empty', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderLoginPage()

    expect(screen.getByRole('button', { name: /magic-link senden/i })).toBeDisabled()
  })
})
