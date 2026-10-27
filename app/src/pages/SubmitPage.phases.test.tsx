/**
 * SubmitPage + submission window (broaden-use-cases issue 04): a member sees
 * the server's reason and a disabled submit button; a 423 window key from
 * the server (e.g. the window closed while the form was open) is shown as
 * its localized reason, never as the generic error.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SubmitPage from './SubmitPage'

const MEMBER = { csrf_token: 'test-csrf', user: { id: 2, is_admin: false, memberships: [] } }

function form(state: 'open' | 'closed') {
  return {
    board: {
      id: 1,
      slug: 'acme',
      name: 'Acme Board',
      phases: {
        submissions:
          state === 'open'
            ? { state: 'open', opens_at: null, closes_at: '2026-05-31T22:00:00Z' }
            : { state: 'closed', opens_at: null, closes_at: '2026-03-31T22:00:00Z' },
        voting: { state: 'open', opens_at: null, closes_at: null },
      },
    },
    is_authenticated: true,
    form_at: 'stamp',
  }
}

function mockFetch(formBody: object, postStatus = 201, postBody: object = { ok: true, id: 7 }) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const [status, body] =
      init?.method === 'POST'
        ? [postStatus, postBody]
        : url.includes('bootstrap')
          ? [200, MEMBER]
          : url.includes('/ideas/new')
            ? [200, formBody]
            : [200, { notifications: [] }]
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderSubmitPage() {
  return render(
    <MemoryRouter initialEntries={['/acme/submit']}>
      <Routes>
        <Route path="/:boardSlug/submit" element={<SubmitPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('SubmitPage — submission window', () => {
  it('locks the form for a member while submissions are closed', async () => {
    mockFetch(form('closed'))
    renderSubmitPage()

    await waitFor(() =>
      expect(screen.getByText('Einreichungen sind geschlossen')).toBeInTheDocument(),
    )
    fireEvent.change(screen.getByLabelText(/Titel/), { target: { value: 'An idea' } })
    expect(screen.getByRole('button', { name: /Idee einreichen|Einreichen/ })).toBeDisabled()
  })

  it('maps a 423 window key to its localized reason', async () => {
    mockFetch(form('open'), 423, {
      error: { key: 'submissions_closed', message: 'Submissions are closed for this board.' },
    })
    renderSubmitPage()

    const title = await screen.findByLabelText(/Titel/)
    fireEvent.change(title, { target: { value: 'An idea' } })
    fireEvent.click(screen.getByRole('button', { name: /Idee einreichen|Einreichen/ }))

    await waitFor(() =>
      expect(screen.getByText('Einreichungen sind geschlossen')).toBeInTheDocument(),
    )
  })
})
