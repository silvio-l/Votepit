/**
 * RTL tests for OperatorSupportTicketPage — user-visible behaviour only.
 * Same pattern as OperatorSupportPage.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OperatorSupportTicketPage from '../pages/OperatorSupportTicketPage'

const BOOTSTRAP_OPERATOR = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: true },
}

const THREAD_RESPONSE = {
  request: {
    id: 42,
    account_id: 10,
    user_id: 5,
    category: 'technical',
    subject: 'Login is broken',
    status: 'open',
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-02 00:00:00',
  },
  messages: [
    {
      id: 1,
      request_id: 42,
      author_type: 'customer',
      author_user_id: 5,
      body: 'I cannot log in anymore.',
      created_at: '2026-01-01 00:00:00',
    },
  ],
  account: {
    id: 10,
    slug: 'acme',
    name: 'Acme Inc.',
    plan: 'pro',
    created_at: '2025-06-01 00:00:00',
  },
  requester: {
    id: 5,
    public_id: 'usr_abc123',
    username: 'jane',
    created_at: '2025-06-02 00:00:00',
  },
}

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    if (url.startsWith('/notifications')) {
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

function renderPage(id = '42') {
  return render(
    <MemoryRouter initialEntries={[`/operator/support/${id}`]}>
      <Routes>
        <Route path="/operator/support/:id" element={<OperatorSupportTicketPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('OperatorSupportTicketPage', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}))
    renderPage()
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('shows the thread plus account and requester context', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OPERATOR }, { body: THREAD_RESPONSE }])

    renderPage()

    await waitFor(() => expect(screen.getByText('I cannot log in anymore.')).toBeInTheDocument())
    expect(screen.getAllByText('Login is broken').length).toBeGreaterThan(0)
    expect(screen.getByText('acme')).toBeInTheDocument()
    expect(screen.getByText('jane')).toBeInTheDocument()
    expect(screen.getByText(/usr_abc123/)).toBeInTheDocument()
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

  it('shows a not-found state for a missing ticket', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: { error: { key: 'not_found', message: 'Ticket not found.' } }, status: 404 },
    ])

    renderPage('999')

    await waitFor(() =>
      expect(screen.getAllByText('Ticket nicht gefunden').length).toBeGreaterThan(0),
    )
  })

  it('submits a reply and reloads the thread', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OPERATOR },
      { body: THREAD_RESPONSE },
      { body: { ok: true, status: 'answered' } },
      {
        body: {
          ...THREAD_RESPONSE,
          messages: [
            ...THREAD_RESPONSE.messages,
            {
              id: 2,
              request_id: 42,
              author_type: 'operator',
              author_user_id: 1,
              body: 'Please try resetting your password.',
              created_at: '2026-01-03 00:00:00',
            },
          ],
        },
      },
    ])

    renderPage()
    await waitFor(() => expect(screen.getByText('I cannot log in anymore.')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Antwort'), 'Please try resetting your password.')
    await user.click(screen.getByRole('button', { name: 'Antworten' }))

    await waitFor(() =>
      expect(screen.getByText('Please try resetting your password.')).toBeInTheDocument(),
    )
  })
})
