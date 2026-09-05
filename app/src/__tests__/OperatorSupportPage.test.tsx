/**
 * RTL tests for OperatorSupportPage — user-visible behaviour only.
 * Same pattern as OperatorPage.test.tsx / cloud's AdminTenantsPage.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OperatorSupportPage from '../pages/OperatorSupportPage'

const BOOTSTRAP_OPERATOR = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: true },
}

const TICKET = {
  id: 42,
  account_id: 10,
  account_slug: 'acme',
  user_id: 5,
  category: 'technical',
  subject: 'Login is broken',
  status: 'open',
  created_at: '2026-01-01 00:00:00',
  updated_at: '2026-01-02 00:00:00',
}

const TICKETS_RESPONSE = { requests: [TICKET] }

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  const calls: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    if (url.startsWith('/notifications')) {
      return new Response(JSON.stringify({ notifications: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    calls.push(url)
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return calls
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/operator/support']}>
      <Routes>
        <Route path="/operator/support" element={<OperatorSupportPage />} />
        <Route path="/operator/support/:id" element={<div data-testid="detail-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('OperatorSupportPage', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}))
    renderPage()
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('lists tickets after bootstrap + the list resolve', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OPERATOR }, { body: TICKETS_RESPONSE }])

    renderPage()

    await waitFor(() => expect(screen.getByText('Login is broken')).toBeInTheDocument())
    expect(screen.getByText('acme')).toBeInTheDocument()
  })

  it('shows access_denied for a non-operator user', async () => {
    makeFetchMock([
      { body: { ...BOOTSTRAP_OPERATOR, user: { id: 2, is_admin: false, is_operator: false } } },
    ])

    renderPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })

  it('shows empty-state copy when there are no tickets', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OPERATOR }, { body: { requests: [] } }])

    renderPage()

    await waitFor(() => expect(screen.getByText('Keine Support-Anfragen.')).toBeInTheDocument())
  })

  it('re-fetches with the search term as a query param', async () => {
    const calls = makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: TICKETS_RESPONSE },
      { body: TICKETS_RESPONSE },
    ])

    renderPage()
    await waitFor(() => expect(screen.getByText('Login is broken')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByPlaceholderText('Betreff oder Nachricht durchsuchen…'), 'login')

    await waitFor(() =>
      expect(calls.some((c) => c.includes('/operator/support?q=login'))).toBe(true),
    )
  })

  it('navigates to the detail page when a row is clicked', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OPERATOR }, { body: TICKETS_RESPONSE }])

    renderPage()
    await waitFor(() => expect(screen.getByText('Login is broken')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByText('Login is broken'))

    await waitFor(() => expect(screen.getByTestId('detail-page')).toBeInTheDocument())
  })
})
