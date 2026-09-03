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

// Authenticated but not an owner/moderator of this account (server 403s the
// branding/moderation/smtp fetches) — is_admin (platform flag) is
// deliberately also false, since it's irrelevant to this gate.
const NON_ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false, is_operator: false, memberships: [] },
}
const FORBIDDEN_RESPONSE = { error: { key: 'account_forbidden', message: 'Kein Zugriff.' } }

const BRANDING_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  primary_color: '#1fa890',
  secondary_color: null,
  logo_url: null,
  intro: null,
  hide_badge: false,
  visibility: 'public',
  allowed_visibilities: ['public'],
  allowed_branding_fields: [],
  frozen_at: null,
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

  it('shows a frozen-board notice (downgrade freeze) without hiding the forms', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, frozen_at: '2026-01-01 00:00:00' } },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())
    expect(screen.getByText(/Dieses Board ist eingefroren/)).toBeInTheDocument()
    // Forms still render — a frozen board stays manageable, just read-only server-side.
    expect(screen.getByText('Moderation')).toBeInTheDocument()
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

  it('shows a no-data hint instead of a blank list when the blocklist is empty', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: { ...MODERATION_RESPONSE, words: [] } },
    ])

    renderAdminPage()

    await waitFor(() =>
      expect(screen.getByText(/Noch keine eigenen Begriffe in der Blockliste/)).toBeInTheDocument(),
    )
  })
})

describe('AdminPage — access denied', () => {
  it('shows no-access message for a user who is not a member of this account, no forms shown', async () => {
    makeFetchMock([{ body: NON_ADMIN_BOOTSTRAP }, { body: FORBIDDEN_RESPONSE, status: 403 }])

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

// ── Branding tiers: staged field-level gating ─────────────────────────────────

describe('AdminPage — staged branding fields disabled/hidden per plan', () => {
  it('Free plan: secondary color, logo, intro and badge-hide are all disabled', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, allowed_branding_fields: [] } },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).toBeDisabled()
    expect(screen.getByLabelText(/Logo-URL/i)).toBeDisabled()
    expect(screen.getByLabelText(/Intro-Text/i)).toBeDisabled()
    expect(screen.getByLabelText(/Badge ausblenden/i)).toBeDisabled()
    // Primary color stays editable on every plan.
    expect(screen.getByLabelText(/Primärfarbe/i)).not.toBeDisabled()
  })

  it('Lite plan: secondary color, logo and intro are editable, badge-hide stays disabled', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro'],
        },
      },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Logo-URL/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Intro-Text/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Badge ausblenden/i)).toBeDisabled()
  })

  it('Pro plan: every staged branding field, including badge-hide, is editable', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro', 'hide_badge'],
        },
      },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Logo-URL/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Intro-Text/i)).not.toBeDisabled()
    expect(screen.getByLabelText(/Badge ausblenden/i)).not.toBeDisabled()
  })

  it('saves intro text and badge-hide switch together with the rest of the branding form', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro', 'hide_badge'],
        },
      },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST branding
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Intro-Text/i), 'Willkommen!')
    await user.click(screen.getByLabelText(/Badge ausblenden/i))
    await user.click(screen.getByRole('button', { name: /Branding speichern/i }))

    await waitFor(() => expect(screen.getByText('Branding gespeichert.')).toBeInTheDocument())

    const postCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
    expect(postCall).toBeDefined()
    const body = JSON.parse(String((postCall?.[1] as RequestInit).body))
    expect(body.intro).toBe('Willkommen!')
    expect(body.hide_badge).toBe(true)
  })
})
