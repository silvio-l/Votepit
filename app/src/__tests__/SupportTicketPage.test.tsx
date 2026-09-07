/**
 * RTL tests for SupportTicketPage — user-visible behaviour only.
 * Same pattern as OperatorSupportTicketPage.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SupportTicketPage from '../pages/SupportTicketPage'

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const THREAD_RESPONSE = {
  request: {
    id: 42,
    account_id: 10,
    user_id: 1,
    category: 'technical',
    subject: 'Login funktioniert nicht',
    status: 'open',
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-02 00:00:00',
  },
  messages: [
    {
      id: 1,
      request_id: 42,
      author_type: 'customer',
      author_user_id: 1,
      body: 'Ich kann mich nicht mehr einloggen.',
      created_at: '2026-01-01 00:00:00',
    },
  ],
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
    <MemoryRouter initialEntries={[`/admin/support/${id}`]}>
      <Routes>
        <Route path="/admin/support/:id" element={<SupportTicketPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('SupportTicketPage', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}))
    renderPage()
    expect(screen.getByRole('status')).toBeInTheDocument()
  })

  it('shows the thread', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: THREAD_RESPONSE }])

    renderPage()

    await waitFor(() =>
      expect(screen.getByText('Ich kann mich nicht mehr einloggen.')).toBeInTheDocument(),
    )
    expect(screen.getAllByText('Login funktioniert nicht').length).toBeGreaterThan(0)
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })

  it('shows a not-found state for a missing ticket', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'not_found', message: 'Ticket not found.' } }, status: 404 },
    ])

    renderPage('999')

    await waitFor(() =>
      expect(screen.getAllByText('Ticket nicht gefunden').length).toBeGreaterThan(0),
    )
  })

  it('submits a reply and reloads the thread', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: THREAD_RESPONSE },
      { body: { ok: true } },
      {
        body: {
          ...THREAD_RESPONSE,
          messages: [
            ...THREAD_RESPONSE.messages,
            {
              id: 2,
              request_id: 42,
              author_type: 'customer',
              author_user_id: 1,
              body: 'Noch ein Hinweis dazu.',
              created_at: '2026-01-03 00:00:00',
            },
          ],
        },
      },
    ])

    renderPage()
    await waitFor(() =>
      expect(screen.getByText('Ich kann mich nicht mehr einloggen.')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.type(screen.getByLabelText('Deine Antwort'), 'Noch ein Hinweis dazu.')
    await user.click(screen.getByRole('button', { name: 'Senden' }))

    await waitFor(() => expect(screen.getByText('Noch ein Hinweis dazu.')).toBeInTheDocument())
  })
})
