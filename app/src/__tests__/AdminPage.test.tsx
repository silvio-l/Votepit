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
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setFeatures } from '../lib/features'
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

// Account owner (self-host: accountRoleFor falls back to memberships[0] when
// no account slug is in context, see lib/api.ts's accountRoleFor) — the
// role the board-deletion danger zone requires.
const OWNER_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 3,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo-account', role: 'owner' }],
  },
}

// Moderator — accountAdmin-level (sees branding/moderation), but must NOT
// see the owner-only deletion danger zone.
const MODERATOR_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 4,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo-account', role: 'moderator' }],
  },
}

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
        <Route path="/admin/boards" element={<div data-testid="board-list-page" />} />
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

  it('shows the public board link and copies it to the clipboard', async () => {
    // userEvent.setup() installs its own navigator.clipboard stub, so it
    // must run BEFORE vi.stubGlobal() overrides navigator, or setup()
    // clobbers our mock.
    const user = userEvent.setup()
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } })
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    const boardLink = `${window.location.origin}/demo`
    expect(screen.getByText(boardLink)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Kopieren/i }))

    expect(writeText).toHaveBeenCalledWith(boardLink)
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Kopiert!/i })).toBeInTheDocument(),
    )
  })
})

describe('AdminPage — general (title/slug rename)', () => {
  it('saves a title-only rename without navigating away', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp
      { body: {} }, // GET notifications
      { body: { ok: true, slug: 'demo', name: 'Renamed Board' } }, // PUT rename
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    const nameInput = screen.getByLabelText(/Board-Name/i)
    await user.clear(nameInput)
    await user.type(nameInput, 'Renamed Board')
    await user.click(screen.getByRole('button', { name: /Titel & Slug speichern/i }))

    await waitFor(() => expect(screen.getByText('Gespeichert.')).toBeInTheDocument())

    const putCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'PUT')
    expect(putCall).toBeDefined()
    const body = JSON.parse(String((putCall?.[1] as RequestInit).body))
    expect(body).toEqual({ name: 'Renamed Board' })
    // Still on the same board — no navigation triggered by a title-only rename.
    expect(screen.getAllByText(/Renamed Board/).length).toBeGreaterThan(0)
  })

  it('saves a slug rename and navigates to the new board URL', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp
      { body: {} }, // GET notifications
      { body: { ok: true, slug: 'new-slug', name: 'Demo Board' } }, // PUT rename
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, board_slug: 'new-slug' } },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp (reload)
      { body: {} }, // GET notifications (reload)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    const slugInput = screen.getByLabelText(/Board-Slug/i)
    await user.clear(slugInput)
    await user.type(slugInput, 'new-slug')
    await user.click(screen.getByRole('button', { name: /Titel & Slug speichern/i }))

    // Re-navigated to the new slug's admin URL → the load effect re-fetches
    // branding for it (proves the SPA followed the rename, not just the API).
    await waitFor(() => {
      const calls = vi.mocked(globalThis.fetch).mock.calls
      expect(calls.some(([url]) => String(url).includes('/admin/boards/new-slug/branding'))).toBe(
        true,
      )
    })
  })

  it('shows a field-level error when the new slug is already taken', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp
      { body: {} }, // GET notifications
      {
        body: {
          error: {
            key: 'validation_error',
            message: 'Validation failed.',
            fields: { slug: 'This slug is already taken in your account.' },
          },
        },
        status: 422,
      },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    const slugInput = screen.getByLabelText(/Board-Slug/i)
    await user.clear(slugInput)
    await user.type(slugInput, 'taken-slug')
    await user.click(screen.getByRole('button', { name: /Titel & Slug speichern/i }))

    await waitFor(() =>
      expect(screen.getByText('This slug is already taken in your account.')).toBeInTheDocument(),
    )
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

// ── Danger zone: board deletion ──────────────────────────────────────────────

describe('AdminPage — board deletion (owner-only danger zone)', () => {
  it('owner can delete the board after typing its slug to confirm, then lands on the board list', async () => {
    makeFetchMock([
      { body: OWNER_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST delete
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Gefahrenzone')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Board löschen' }))

    const confirmInput = screen.getByLabelText('Zur Bestätigung "demo" eingeben')
    const submitButton = screen.getByRole('button', { name: 'Endgültig löschen' })
    expect(submitButton).toBeDisabled()

    await user.type(confirmInput, 'wrong-slug')
    expect(submitButton).toBeDisabled()

    await user.clear(confirmInput)
    await user.type(confirmInput, 'demo')
    expect(submitButton).toBeEnabled()

    await user.click(submitButton)

    await waitFor(() => expect(screen.getByTestId('board-list-page')).toBeInTheDocument())
  })

  it('moderator (accountAdmin, not owner) does not see the danger zone', async () => {
    makeFetchMock([
      { body: MODERATOR_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.queryByText('Gefahrenzone')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Board löschen' })).not.toBeInTheDocument()
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

// ── Branding tiers: upgrade link for gated fields ─────────────────────────────

describe('AdminPage — upgrade link next to plan-gated branding fields', () => {
  afterEach(() => {
    setFeatures(undefined) // reset to Community defaults between tests
  })

  it('Cloud (billing extension present): shows an upgrade link next to the disabled badge-hide field', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          // All visibilities unlocked so this test isolates the badge-hide
          // field's own upgrade link, not the (separately tested) one next
          // to the visibility field.
          allowed_visibilities: ['public', 'unlisted', 'private'],
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro'],
        },
      },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Badge ausblenden/i)).toBeDisabled()
    const link = screen.getByRole('link', { name: /Jetzt upgraden/i })
    expect(link).toHaveAttribute('href', '/admin/billing')
  })

  it('Community/self-host (no billing extension): no upgrade link is rendered for the disabled badge-hide field', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          allowed_visibilities: ['public', 'unlisted', 'private'],
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro'],
        },
      },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Badge ausblenden/i)).toBeDisabled()
    expect(screen.queryByRole('link', { name: /Jetzt upgraden/i })).not.toBeInTheDocument()
  })

  it('Cloud, Pro plan (badge-hide allowed): no upgrade link is rendered, field stays enabled', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          allowed_visibilities: ['public', 'unlisted', 'private'],
          allowed_branding_fields: ['secondary_color', 'logo_url', 'intro', 'hide_badge'],
        },
      },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    expect(screen.getByLabelText(/Badge ausblenden/i)).not.toBeDisabled()
    expect(screen.queryByRole('link', { name: /Jetzt upgraden/i })).not.toBeInTheDocument()
  })

  it('Cloud (billing extension present): shows an upgrade link next to the locked visibility field', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE }, // allowed_visibilities: ['public'] — locked
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())

    const links = screen.getAllByRole('link', { name: /Jetzt upgraden/i })
    expect(links.some((link) => link.getAttribute('href') === '/admin/billing')).toBe(true)
  })
})
