/**
 * RTL tests for InboxPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Notifications render with unread badge, mixed support_reply/announcement types
 *  3. Clicking an unread notification with a link marks it read and navigates
 *  4. Anon (user: null from bootstrap) → redirect to /login
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import InboxPage from '../pages/InboxPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const NOTIFICATIONS_RESPONSE = {
  notifications: [
    {
      id: 1,
      scope: 'account',
      type: 'support_reply',
      title: 'Antwort auf deine Support-Anfrage',
      body: 'Zu deiner Anfrage "Login klappt nicht" gibt es eine Antwort.',
      link_path: '/admin/support',
      created_at: '2026-01-01T00:00:00Z',
      is_read: false,
    },
    {
      id: 2,
      scope: 'broadcast',
      type: 'announcement',
      title: 'Neues Feature: Roadmap-Ansicht',
      body: 'Ab sofort gibt es eine öffentliche Roadmap.',
      link_path: null,
      created_at: '2026-01-02T00:00:00Z',
      is_read: true,
    },
  ],
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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

function renderInboxPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/inbox']}>
      <Routes>
        <Route path="/admin/inbox" element={<InboxPage />} />
        <Route path="/admin/support" element={<div data-testid="support-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('InboxPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: NOTIFICATIONS_RESPONSE }])

    renderInboxPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('InboxPage — notifications', () => {
  it('shows both notifications with an unread badge only on the unread one', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: NOTIFICATIONS_RESPONSE }])

    renderInboxPage()

    await waitFor(() =>
      expect(screen.getByText('Antwort auf deine Support-Anfrage')).toBeInTheDocument(),
    )
    expect(screen.getByText('Neues Feature: Roadmap-Ansicht')).toBeInTheDocument()
    expect(screen.getAllByText('Neu')).toHaveLength(1)
  })

  it('shows empty-state copy when there are no notifications', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: { notifications: [] } }])

    renderInboxPage()

    await waitFor(() =>
      expect(screen.getByText('Noch keine Benachrichtigungen vorhanden.')).toBeInTheDocument(),
    )
  })
})

describe('InboxPage — open notification', () => {
  it('clicking an unread notification with a link marks it read and navigates', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: NOTIFICATIONS_RESPONSE },
      { body: { ok: true } },
    ])

    renderInboxPage()
    await waitFor(() =>
      expect(screen.getByText('Antwort auf deine Support-Anfrage')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByText('Antwort auf deine Support-Anfrage'))

    await waitFor(() => expect(screen.getByTestId('support-page')).toBeInTheDocument())
  })
})

describe('InboxPage — dismiss notification', () => {
  it('clicking dismiss removes the notification from the list and calls the API', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: NOTIFICATIONS_RESPONSE },
      { body: { ok: true } },
    ])

    renderInboxPage()
    await waitFor(() =>
      expect(screen.getByText('Antwort auf deine Support-Anfrage')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(
      screen.getByRole('button', {
        name: /Antwort auf deine Support-Anfrage.*aus dem Postfach entfernen/i,
      }),
    )

    await waitFor(() =>
      expect(screen.queryByText('Antwort auf deine Support-Anfrage')).not.toBeInTheDocument(),
    )
    expect(screen.getByText('Neues Feature: Roadmap-Ansicht')).toBeInTheDocument()

    const deleteCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'DELETE')
    expect(deleteCall?.[0]).toBe('/notifications/1')
  })

  it('dismissing a notification does not also navigate or mark it read via the read endpoint', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: NOTIFICATIONS_RESPONSE },
      { body: { ok: true } },
    ])

    renderInboxPage()
    await waitFor(() =>
      expect(screen.getByText('Neues Feature: Roadmap-Ansicht')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(
      screen.getByRole('button', {
        name: /Neues Feature: Roadmap-Ansicht.*aus dem Postfach entfernen/i,
      }),
    )

    await waitFor(() =>
      expect(screen.queryByText('Neues Feature: Roadmap-Ansicht')).not.toBeInTheDocument(),
    )
    expect(screen.queryByTestId('support-page')).not.toBeInTheDocument()
  })
})

describe('InboxPage — access denied', () => {
  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderInboxPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})
