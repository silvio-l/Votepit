/**
 * RTL tests for TrustedAuthorsPage (trusted authors, broaden-use-cases
 * issue 11) — user-visible behaviour only, fetch mocked globally.
 *
 *  1. Lists the account's trusted authors with their pseudonymous label
 *  2. Revoke asks for confirmation, posts to the revoke route and reloads
 *  3. Empty state explains how trust is granted
 *  4. 403 → access denied
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TrustedAuthorsPage from '../pages/TrustedAuthorsPage'

const BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 1, is_admin: false, memberships: [] } }

const AUTHORS = {
  authors: [
    {
      user_id: 7,
      public_id: 'abc123',
      username: 'lena',
      trusted_at: '2026-10-01 09:00:00',
      state: 'normal',
    },
    {
      user_id: 8,
      public_id: 'def456',
      username: null,
      trusted_at: '2026-09-30 09:00:00',
      state: 'flagged',
    },
  ],
}

interface Call {
  method: string
  url: string
}

function mockApi(options: { status?: number; list?: object } = {}): Call[] {
  const calls: Call[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    const method = init?.method ?? 'GET'
    const json = (body: object, status = 200) =>
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      })

    if (url.includes('/bootstrap')) return json(BOOTSTRAP)
    if (url.startsWith('/notifications')) return json({ notifications: [] })
    if (method !== 'GET') {
      calls.push({ method, url })
      return json({ ok: true })
    }
    if (url.endsWith('/admin/trusted-authors')) {
      if (options.status) {
        return json(
          { error: { key: 'account_forbidden', message: 'Kein Zugriff.' } },
          options.status,
        )
      }
      if (calls.length > 0) return json({ authors: [AUTHORS.authors[1]] })
      return json(options.list ?? AUTHORS)
    }
    return json({}, 404)
  })
  return calls
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/trusted-authors']}>
      <Routes>
        <Route path="/admin/trusted-authors" element={<TrustedAuthorsPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('TrustedAuthorsPage', () => {
  it('lists the trusted authors with their pseudonymous label', async () => {
    mockApi()
    renderPage()

    await waitFor(() => expect(screen.getByText('Person #abc123 (lena)')).toBeInTheDocument())
    expect(screen.getByText('Person #def456')).toBeInTheDocument()
  })

  it('revokes trust after confirmation and reloads the list', async () => {
    const calls = mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Person #abc123 (lena)')).toBeInTheDocument())

    const user = userEvent.setup()
    const row = screen.getByText('Person #abc123 (lena)').closest('tr') as HTMLElement
    await user.click(
      within(row).getByRole('button', { name: 'Vertrauen für Person #abc123 entziehen' }),
    )
    const dialog = await screen.findByRole('alertdialog')
    expect(calls).toHaveLength(0)
    await user.click(within(dialog).getByRole('button', { name: 'Entziehen' }))

    await waitFor(() =>
      expect(calls).toEqual([{ method: 'POST', url: '/admin/trusted-authors/7/revoke' }]),
    )
    await waitFor(() => expect(screen.queryByText('Person #abc123 (lena)')).not.toBeInTheDocument())
    expect(screen.getByText('Vertrauen entzogen.')).toBeInTheDocument()
  })

  it('shows an empty state that explains how trust is granted', async () => {
    mockApi({ list: { authors: [] } })
    renderPage()

    await waitFor(() =>
      expect(
        screen.getByText(/Noch niemand ist als vertrauenswürdig markiert/),
      ).toBeInTheDocument(),
    )
  })

  it('shows access denied without a moderation role', async () => {
    mockApi({ status: 403 })
    renderPage()

    await waitFor(() => expect(screen.getByText('Kein Zugriff')).toBeInTheDocument())
  })
})
