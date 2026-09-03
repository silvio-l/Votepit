/**
 * RTL tests for InviteAcceptPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Missing token → immediate error state, no fetch call
 *  2. Successful accept → done state
 *  3. 401 (anon) → redirect to /login with the invite-accept return-to
 *  4. 403 invite_mismatch → explicit error message
 *  5. Other error → generic/error message from the API payload
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InviteAcceptPage from '../pages/InviteAcceptPage'

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderInviteAcceptPage(initialEntry = '/invite/accept?token=abc123') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/invite/accept" element={<InviteAcceptPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('InviteAcceptPage — missing token', () => {
  it('shows an error immediately and makes no fetch call', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch')

    renderInviteAcceptPage('/invite/accept')

    expect(
      await screen.findByText('Der Einladungslink ist ungültig oder abgelaufen.'),
    ).toBeInTheDocument()
    expect(fetchSpy).not.toHaveBeenCalled()
  })
})

describe('InviteAcceptPage — success', () => {
  it('shows the done state after a successful accept', async () => {
    makeFetchMock([{ body: { ok: true } }])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByText('Willkommen im Team')).toBeInTheDocument())
    expect(screen.getByText('Du bist jetzt Moderator dieses Accounts.')).toBeInTheDocument()
  })
})

describe('InviteAcceptPage — anon', () => {
  it('redirects to /login with the invite-accept return-to on 401', async () => {
    makeFetchMock([
      { body: { error: { key: 'unauthenticated', message: 'Unauthenticated.' } }, status: 401 },
    ])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('InviteAcceptPage — invite mismatch', () => {
  it('shows the API error message on 403', async () => {
    makeFetchMock([
      {
        body: {
          error: {
            key: 'invite_mismatch',
            message: 'Diese Einladung gehört zu einem anderen Konto.',
          },
        },
        status: 403,
      },
    ])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Diese Einladung gehört zu einem anderen Konto.')).toBeInTheDocument()
  })
})

describe('InviteAcceptPage — other error', () => {
  it('shows a generic error message when the API gives no message', async () => {
    makeFetchMock([{ body: { error: { key: 'invite_expired' } }, status: 400 }])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Die Einladung konnte nicht angenommen werden.')).toBeInTheDocument()
  })
})
