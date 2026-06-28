/**
 * RTL tests for VerifyPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made.
 * useNavigate is mocked to capture redirect calls.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VerifyPage from '../pages/VerifyPage'

// ── Mocks ─────────────────────────────────────────────────────────────────────

const mockNavigate = vi.fn()

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

function mockFetchVerifySuccess(redirect = '/') {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify({ ok: true, redirect }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

function mockFetchVerifyFailure() {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(
      JSON.stringify({
        error: { key: 'invalid_token', message: 'Der Link ist ungültig oder abgelaufen.' },
      }),
      { status: 400, headers: { 'Content-Type': 'application/json' } },
    ),
  )
}

function renderVerifyPage(token?: string, returnTo?: string) {
  const params = new URLSearchParams()
  if (token) params.set('token', token)
  if (returnTo) params.set('r', returnTo)
  const search = params.toString() ? `?${params.toString()}` : ''

  return render(
    <MemoryRouter initialEntries={[`/login/verify${search}`]}>
      <Routes>
        <Route path="/login/verify" element={<VerifyPage />} />
        <Route path="/login" element={<div>Login-Seite</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  mockNavigate.mockReset()
})

describe('VerifyPage', () => {
  it('shows "verifying" state while request is in flight', async () => {
    // Never resolves during this test
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}))
    renderVerifyPage('valid-token-abc')

    expect(screen.getByText(/Link wird überprüft/i)).toBeInTheDocument()
  })

  it('redirects to "/" on successful verification with default redirect', async () => {
    mockFetchVerifySuccess('/')
    renderVerifyPage('valid-token-abc')

    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/', { replace: true }))
  })

  it('redirects to custom return_to on successful verification', async () => {
    mockFetchVerifySuccess('/some/board')
    renderVerifyPage('valid-token-abc', '/some/board')

    await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/some/board', { replace: true }))
  })

  it('shows error message for invalid/expired token (400)', async () => {
    mockFetchVerifyFailure()
    renderVerifyPage('expired-token-xyz')

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent(/ungültig|abgelaufen/i)
    // Should not navigate away
    expect(mockNavigate).not.toHaveBeenCalled()
  })

  it('shows error message when token is missing from URL', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true, redirect: '/' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderVerifyPage() // no token param

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent(/ungültig|abgelaufen/i)
    expect(mockNavigate).not.toHaveBeenCalled()
  })

  it('shows "Anmeldung fehlgeschlagen" heading on error', async () => {
    mockFetchVerifyFailure()
    renderVerifyPage('bad-token')

    await waitFor(() =>
      expect(
        screen.getByRole('heading', { name: /Anmeldung fehlgeschlagen/i }),
      ).toBeInTheDocument(),
    )
  })

  it('offers a link back to /login on error', async () => {
    mockFetchVerifyFailure()
    renderVerifyPage('bad-token')

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /neuen link anfordern/i })).toBeInTheDocument(),
    )
  })
})
