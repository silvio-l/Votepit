/**
 * RTL tests for OperatorPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Operator sees usage counters, accounts, boards, and reports
 *  3. Non-operator (is_operator: false) → access denied message, no data rendered
 *  4. Anon (user: null from bootstrap) → redirect to /login
 *  5. Account lock: click triggers POST and reloads all four lists
 *  6. Report review: click triggers POST and reloads
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OperatorPage from '../pages/OperatorPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OPERATOR = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: true },
}

const BOOTSTRAP_NON_OPERATOR = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: true, is_operator: false },
}

const USAGE_RESPONSE = {
  accounts_total: 3,
  accounts_by_plan: { 'self-host': 1, free: 1, pro: 1 },
  boards_total: 2,
  ideas_total: 5,
  signups_last_7_days: 1,
  open_reports: 1,
  open_support_requests: 0,
}

const ACCOUNTS_RESPONSE = {
  accounts: [
    {
      id: 10,
      slug: 'acme',
      name: 'Acme Inc.',
      plan: 'pro',
      is_default: false,
      confirmed_at: '2026-01-01T00:00:00Z',
      locked_at: null,
      created_at: '2026-01-01T00:00:00Z',
    },
  ],
}

const BOARDS_RESPONSE = {
  boards: [
    {
      id: 20,
      account_id: 10,
      account_slug: 'acme',
      slug: 'feedback',
      name: 'Feedback',
      status: 'active',
      visibility: 'public',
      locked_at: null,
      created_at: '2026-01-01T00:00:00Z',
    },
  ],
}

const REPORTS_RESPONSE = {
  reports: [
    {
      id: 30,
      account_id: 10,
      board_id: 20,
      idea_id: null,
      target_url: '/acme/feedback/idea/1',
      reason: 'Spam content',
      reporter_email: 'reporter@example.com',
      status: 'open',
      reviewed_by: null,
      reviewed_at: null,
      created_at: '2026-01-01T00:00:00Z',
    },
  ],
}

const SUPPORT_RESPONSE = { requests: [] }
const FAQ_RESPONSE = { entries: [] }
const ANNOUNCEMENTS_RESPONSE = { announcements: [] }

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

function renderOperatorPage() {
  return render(
    <MemoryRouter initialEntries={['/operator']}>
      <Routes>
        <Route path="/operator" element={<OperatorPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('OperatorPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: USAGE_RESPONSE },
      { body: ACCOUNTS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: REPORTS_RESPONSE },
      { body: SUPPORT_RESPONSE },
      { body: FAQ_RESPONSE },
      { body: ANNOUNCEMENTS_RESPONSE },
    ])

    renderOperatorPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('OperatorPage — operator view', () => {
  it('shows usage counters, accounts, boards, and reports', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: USAGE_RESPONSE },
      { body: ACCOUNTS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: REPORTS_RESPONSE },
      { body: SUPPORT_RESPONSE },
      { body: FAQ_RESPONSE },
      { body: ANNOUNCEMENTS_RESPONSE },
    ])

    renderOperatorPage()

    await waitFor(() => expect(screen.getByText('Acme Inc.', { exact: false })).toBeInTheDocument())
    expect(screen.getByText(/Feedback/)).toBeInTheDocument()
    expect(screen.getByText(/Spam content/)).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument() // accounts_total
    expect(screen.getByLabelText(/Account Acme Inc\. sperren/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Board Feedback sperren/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Meldung #30 als geprüft markieren/i)).toBeInTheDocument()
  })
})

describe('OperatorPage — empty lists', () => {
  it('shows empty-state copy instead of a blank list when there are no accounts or boards', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: { ...USAGE_RESPONSE, accounts_total: 0, boards_total: 0 } },
      { body: { accounts: [] } },
      { body: { boards: [] } },
      { body: { reports: [] } },
      { body: SUPPORT_RESPONSE },
      { body: FAQ_RESPONSE },
      { body: ANNOUNCEMENTS_RESPONSE },
    ])

    renderOperatorPage()

    await waitFor(() =>
      expect(screen.getByText('Noch keine Accounts vorhanden.')).toBeInTheDocument(),
    )
    expect(screen.getByText('Noch keine Boards vorhanden.')).toBeInTheDocument()
    expect(screen.getByText('Keine Meldungen.')).toBeInTheDocument()
  })
})

describe('OperatorPage — access denied', () => {
  it('shows no-access message for a non-operator user (installation admin included)', async () => {
    makeFetchMock([{ body: BOOTSTRAP_NON_OPERATOR }])

    renderOperatorPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
    expect(screen.queryByText(/Acme Inc\./)).not.toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderOperatorPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('OperatorPage — account actions', () => {
  it('locking an account sends POST and reloads all lists', async () => {
    const lockedAccountsResponse = {
      accounts: [{ ...ACCOUNTS_RESPONSE.accounts[0], locked_at: '2026-01-02T00:00:00Z' }],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: USAGE_RESPONSE },
      { body: ACCOUNTS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: REPORTS_RESPONSE },
      { body: SUPPORT_RESPONSE },
      { body: FAQ_RESPONSE },
      { body: ANNOUNCEMENTS_RESPONSE },
      { body: { ok: true } },
      { body: USAGE_RESPONSE },
      { body: lockedAccountsResponse },
      { body: BOARDS_RESPONSE },
      { body: REPORTS_RESPONSE },
    ])

    renderOperatorPage()
    await waitFor(() =>
      expect(screen.getByLabelText(/Account Acme Inc\. sperren/i)).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/Account Acme Inc\. sperren/i))

    await waitFor(() =>
      expect(screen.getByLabelText(/Account Acme Inc\. entsperren/i)).toBeInTheDocument(),
    )
  })
})

describe('OperatorPage — report review', () => {
  it('marking a report reviewed sends POST and removes the review buttons', async () => {
    const reviewedReportsResponse = {
      reports: [{ ...REPORTS_RESPONSE.reports[0], status: 'reviewed', reviewed_by: 1 }],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: USAGE_RESPONSE },
      { body: ACCOUNTS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: REPORTS_RESPONSE },
      { body: SUPPORT_RESPONSE },
      { body: FAQ_RESPONSE },
      { body: ANNOUNCEMENTS_RESPONSE },
      { body: { ok: true, status: 'reviewed' } },
      { body: USAGE_RESPONSE },
      { body: ACCOUNTS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: reviewedReportsResponse },
    ])

    renderOperatorPage()
    await waitFor(() =>
      expect(screen.getByLabelText(/Meldung #30 als geprüft markieren/i)).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/Meldung #30 als geprüft markieren/i))

    await waitFor(() =>
      expect(screen.queryByLabelText(/Meldung #30 als geprüft markieren/i)).not.toBeInTheDocument(),
    )
  })
})
