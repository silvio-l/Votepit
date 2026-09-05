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
import { setRoutingMode } from '../lib/accountContext'
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
  // Mirrors App.tsx's bootstrap effect, which resolves routing_mode before
  // any route (including this one) renders — see accountContext.ts.
  setRoutingMode('cloud')
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
  it('shows the role-specific done state after a successful accept', async () => {
    makeFetchMock([{ body: { ok: true, account_id: 1, account_slug: 'acme', role: 'moderator' } }])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByText('Willkommen im Team')).toBeInTheDocument())
    expect(screen.getByText('Du bist jetzt Moderator dieses Accounts.')).toBeInTheDocument()
    expect(screen.getByText('Zur Mitgliederübersicht')).toBeInTheDocument()
  })

  it("sends a plain 'member' to the board instead of the admin-only members page", async () => {
    makeFetchMock([{ body: { ok: true, account_id: 1, account_slug: 'acme', role: 'member' } }])

    renderInviteAcceptPage()

    await waitFor(() =>
      expect(
        screen.getByText('Du hast jetzt Zugriff auf die privaten Boards dieses Accounts.'),
      ).toBeInTheDocument(),
    )
    const cta = screen.getByText('Zum Board') as HTMLAnchorElement
    expect(cta.closest('a')).toHaveAttribute('href', '/acme')
  })

  it('in self-host mode, never links to a /{accountSlug}-prefixed path (there is no such route)', async () => {
    setRoutingMode('self-host')
    makeFetchMock([{ body: { ok: true, account_id: 1, account_slug: 'acme', role: 'member' } }])

    renderInviteAcceptPage()

    await waitFor(() =>
      expect(
        screen.getByText('Du hast jetzt Zugriff auf die privaten Boards dieses Accounts.'),
      ).toBeInTheDocument(),
    )
    const cta = screen.getByText('Zum Board') as HTMLAnchorElement
    expect(cta.closest('a')).toHaveAttribute('href', '/')
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
  it('shows our own localized message on 403, ignoring the API-contract English message', async () => {
    // The real backend always sends this in English (API-contract text, not
    // UI copy) — the page must show its own German string regardless, never
    // leak the raw backend message into an otherwise German page.
    makeFetchMock([
      {
        body: {
          error: {
            key: 'invite_mismatch',
            message:
              'This invitation is intended for a different email address. Please log in with the invited account.',
          },
        },
        status: 403,
      },
    ])

    renderInviteAcceptPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(
      screen.getByText(
        'Diese Einladung ist für eine andere E-Mail-Adresse bestimmt. Melde dich mit dem eingeladenen Konto an, um sie anzunehmen.',
      ),
    ).toBeInTheDocument()
    expect(screen.queryByText(/This invitation is intended/)).not.toBeInTheDocument()
  })

  it('shows which account is currently signed in, fetched best-effort via bootstrap', async () => {
    makeFetchMock([
      {
        body: { error: { key: 'invite_mismatch', message: 'This invitation is intended for...' } },
        status: 403,
      },
      {
        body: {
          csrf_token: 'x',
          user: { id: 1, public_id: 'usr_abc123', memberships: [] },
          routing_mode: 'self-host',
        },
        status: 200,
      },
    ])

    renderInviteAcceptPage()

    await waitFor(() =>
      expect(screen.getByText('Aktuell angemeldet als: usr_abc123')).toBeInTheDocument(),
    )
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
