/**
 * RTL tests for AppealsAdminPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Moderator sees the appeal list with reason/message/status columns
 *  3. Non-moderator (403 from GET {accountPrefix}/moderation/appeals) → access denied
 *  4. Anon (user: null from bootstrap) → redirect to /login
 *  5. Resolving an appeal (uphold/overturn) reloads the list
 *  6. Empty state when there are no appeals
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AppealsAdminPage from '../pages/AppealsAdminPage'

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const APPEALS_RESPONSE = {
  appeals: [
    {
      id: 1,
      moderation_action_id: 10,
      submitted_by_user_id: 2,
      message: 'This was clearly not against the rules.',
      status: 'pending',
      resolved_by_user_id: null,
      resolved_at: null,
      created_at: '2026-01-01T00:00:00Z',
      entity_type: 'idea',
      entity_id: 5,
      board_id: 1,
      reason_code: 'community_rule',
      content_snapshot: 'My idea title\n\nMy idea body.',
    },
  ],
}

const EMPTY_APPEALS_RESPONSE = { appeals: [] }

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

function renderAppealsAdminPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/appeals']}>
      <Routes>
        <Route path="/admin/appeals" element={<AppealsAdminPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('AppealsAdminPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: APPEALS_RESPONSE }])
    renderAppealsAdminPage()
    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('AppealsAdminPage — moderator view', () => {
  it('shows the pending appeal with its reason, message and status', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: APPEALS_RESPONSE }])

    renderAppealsAdminPage()

    await waitFor(() =>
      expect(screen.getByText(/This was clearly not against the rules\./)).toBeInTheDocument(),
    )
    expect(screen.getByText('Verstoß gegen die Board-Regeln')).toBeInTheDocument()
    expect(screen.getByText('Offen')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Bestätigen' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Aufheben' })).toBeInTheDocument()
  })

  it('shows the empty state when there are no appeals', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: EMPTY_APPEALS_RESPONSE }])

    renderAppealsAdminPage()

    await waitFor(() =>
      expect(screen.getByText('Es liegen keine Einsprüche vor.')).toBeInTheDocument(),
    )
  })
})

describe('AppealsAdminPage — access denied', () => {
  it('shows no-access message when GET {accountPrefix}/moderation/appeals returns 403', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'forbidden', message: 'Forbidden' } }, status: 403 },
    ])

    renderAppealsAdminPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderAppealsAdminPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('AppealsAdminPage — resolve', () => {
  it('reloads the list after upholding an appeal', async () => {
    const resolvedList = {
      appeals: [{ ...APPEALS_RESPONSE.appeals[0], status: 'upheld', resolved_by_user_id: 1 }],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: APPEALS_RESPONSE },
      { body: { ok: true, status: 'upheld' } },
      { body: resolvedList },
    ])

    renderAppealsAdminPage()
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Bestätigen' })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Bestätigen' }))

    await waitFor(() => expect(screen.getByText('Bestätigt')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Bestätigen' })).not.toBeInTheDocument()
  })

  it('reloads the list after overturning an appeal', async () => {
    const resolvedList = {
      appeals: [{ ...APPEALS_RESPONSE.appeals[0], status: 'overturned', resolved_by_user_id: 1 }],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: APPEALS_RESPONSE },
      { body: { ok: true, status: 'overturned' } },
      { body: resolvedList },
    ])

    renderAppealsAdminPage()
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Aufheben' })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Aufheben' }))

    await waitFor(() => expect(screen.getByText('Aufgehoben')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Aufheben' })).not.toBeInTheDocument()
  })
})
