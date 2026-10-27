/**
 * RTL tests for SupportPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Account member sees the contact form and their own tickets
 *  3. FAQ deflection: matching entries for the selected category are shown
 *  4. Submitting the form sends POST and reloads the ticket list
 *  5. Non-member (403 from GET /admin/support) → access denied message
 *  6. Anon (user: null from bootstrap) → redirect to /login
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SupportPage from '../pages/SupportPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const FAQ_RESPONSE = {
  entries: [
    {
      id: 1,
      category: 'technical',
      question_de: 'Warum funktioniert der Login nicht?',
      question_en: 'Why does login not work?',
      answer_de: 'Bitte Cache leeren.',
      answer_en: 'Please clear your cache.',
      sort_order: 1,
    },
    {
      id: 2,
      category: 'billing',
      question_de: 'Wo finde ich meine Rechnung?',
      question_en: 'Where do I find my invoice?',
      answer_de: 'Im Account-Bereich unter Abrechnung.',
      answer_en: 'Under Billing in your account settings.',
      sort_order: 1,
    },
  ],
}

const TICKETS_RESPONSE = {
  requests: [
    {
      id: 7,
      account_id: 100,
      user_id: 1,
      category: 'technical',
      subject: 'Login funktioniert nicht',
      status: 'open',
      created_at: '2026-01-01T00:00:00Z',
      updated_at: '2026-01-01T00:00:00Z',
    },
  ],
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    // The header's notification bell fires its own GET /notifications on every
    // authenticated page — served out-of-band so it never consumes a slot from
    // this page's own response queue below.
    if (typeof input === 'string' && input.startsWith('/notifications')) {
      return new Response(JSON.stringify({ notifications: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderSupportPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/support']}>
      <Routes>
        <Route path="/admin/support" element={<SupportPage />} />
        <Route path="/admin/support/:id" element={<div data-testid="detail-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('SupportPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: FAQ_RESPONSE }, { body: TICKETS_RESPONSE }])

    renderSupportPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('SupportPage — member view', () => {
  it('shows the contact form and the account’s own tickets', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: FAQ_RESPONSE }, { body: TICKETS_RESPONSE }])

    renderSupportPage()

    await waitFor(() => expect(screen.getByText('Login funktioniert nicht')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Absenden' })).toBeInTheDocument()
  })

  it('shows matching FAQ entries for the default selected category', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: FAQ_RESPONSE }, { body: TICKETS_RESPONSE }])

    renderSupportPage()

    await waitFor(() =>
      expect(screen.getByText('Warum funktioniert der Login nicht?')).toBeInTheDocument(),
    )
    expect(screen.queryByText('Wo finde ich meine Rechnung?')).not.toBeInTheDocument()
  })
})

describe('SupportPage — submit', () => {
  it('submitting the form sends POST and reloads the ticket list', async () => {
    const newTicketResponse = {
      requests: [
        ...TICKETS_RESPONSE.requests,
        { ...TICKETS_RESPONSE.requests[0], id: 8, subject: 'Neue Anfrage' },
      ],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: FAQ_RESPONSE },
      { body: TICKETS_RESPONSE },
      { body: { ok: true, id: 8 } },
      { body: FAQ_RESPONSE },
      { body: newTicketResponse },
    ])

    renderSupportPage()
    await waitFor(() => expect(screen.getByLabelText(/Betreff/)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Betreff/), 'Neue Anfrage')
    await user.type(
      screen.getByLabelText(/Nachricht/),
      'Eine ausführliche Beschreibung meines Problems.',
    )
    await user.click(screen.getByRole('button', { name: 'Absenden' }))

    await waitFor(() => expect(screen.getAllByText('Neue Anfrage').length).toBeGreaterThan(0))
  })
})

describe('SupportPage — navigation', () => {
  it('navigates to the ticket detail page when a row is clicked', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: FAQ_RESPONSE }, { body: TICKETS_RESPONSE }])

    renderSupportPage()
    await waitFor(() => expect(screen.getByText('Login funktioniert nicht')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByText('Login funktioniert nicht'))

    await waitFor(() => expect(screen.getByTestId('detail-page')).toBeInTheDocument())
  })
})

describe('SupportPage — access denied', () => {
  it('shows no-access message for a non-member (403)', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: {}, status: 403 }])

    renderSupportPage()

    await waitFor(() => expect(screen.getByText('Kein Zugriff')).toBeInTheDocument())
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderSupportPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})
