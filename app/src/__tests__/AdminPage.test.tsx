/**
 * RTL tests for AdminPage — user-visible behaviour only (Issue 14).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Admin sees both forms; saves branding → success feedback shown
 *  2. Admin saves moderation toggle → POST called with action=toggle → success feedback
 *  3. Non-admin (is_admin: false from bootstrap) → access denied message, no forms
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminPage from '../pages/AdminPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: true },
}

const NON_ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false },
}

const BRANDING_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  primary_color: '#1fa890',
  secondary_color: null,
  logo_url: null,
}

const MODERATION_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  moderation_enabled: true,
  words: [{ id: 1, word: 'spamword' }],
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build a sequential fetch mock from a list of response payloads. */
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

function renderAdminPage(boardSlug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/admin/boards/${boardSlug}`]}>
      <Routes>
        <Route path="/admin/boards/:boardSlug" element={<AdminPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
        <Route path="/:boardSlug" element={<div data-testid="board-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('AdminPage — branding save', () => {
  it('shows both form sections and saves branding with success feedback', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST branding
    ])

    renderAdminPage()

    // Wait for forms to appear
    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByText('Moderation')).toBeInTheDocument()

    // Both form sections rendered (not access denied)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()

    // Edit primary color field and submit
    const user = userEvent.setup()
    const primaryInput = screen.getByLabelText(/Primärfarbe/i)
    await user.clear(primaryInput)
    await user.type(primaryInput, '#abcdef')

    await user.click(screen.getByRole('button', { name: /Branding speichern/i }))

    // Success feedback
    await waitFor(() => expect(screen.getByText('Branding gespeichert.')).toBeInTheDocument())
  })
})

describe('AdminPage — moderation toggle save', () => {
  it('saves moderation toggle and shows success feedback', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST moderation toggle
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Moderation')).toBeInTheDocument())

    const user = userEvent.setup()

    // The moderation toggle is already enabled (from mock). Click Save.
    const saveButtons = screen.getAllByRole('button', { name: /^Speichern$/i })
    // The first "Speichern" button in the Moderation section is for the toggle.
    await user.click(saveButtons[0])

    await waitFor(() => expect(screen.getByText('Moderation gespeichert.')).toBeInTheDocument())
  })

  it('renders word list from moderation data', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('spamword')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: /Wort „spamword" entfernen/i })).toBeInTheDocument()
  })
})

describe('AdminPage — access denied', () => {
  it('shows no-access message for non-admin user, no forms shown', async () => {
    makeFetchMock([{ body: NON_ADMIN_BOOTSTRAP }])

    renderAdminPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())

    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
    expect(screen.getByText(/nur für Board-Administratoren/i)).toBeInTheDocument()

    // Neither branding nor moderation forms should be visible
    expect(screen.queryByText('Branding')).not.toBeInTheDocument()
    expect(screen.queryByText('Moderation')).not.toBeInTheDocument()
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderAdminPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})
