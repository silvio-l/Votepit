/**
 * RTL tests for AdminPage — user-visible behaviour only (Issue 14).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Admin sees both forms; saves branding → success feedback shown
 *  2. Admin saves moderation toggle → POST called with action=toggle → success feedback
 *  3. Non-admin (is_admin: false from bootstrap) → access denied message, no forms
 */

// The design editor loads image and Pro state on its own endpoints; mock them
// so the fetch-sequence mocks in this file keep matching the calls they expect.
vi.mock('../lib/api/boardAssets', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardAssets')>()),
  getBoardAssets: vi.fn().mockResolvedValue({
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true },
    max_upload_bytes: 4194304,
    link: { back_link_url: '', back_link_label: '', footer_note: '' },
  }),
}))
vi.mock('../lib/api/boardBranding', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardBranding')>()),
  getBoardBranding: vi.fn().mockResolvedValue({
    board_slug: 'demo',
    board_name: 'Demo Board',
    frozen_at: null,
    typography_preset: 'default',
    shape_style: 'rounded',
    density: 'comfortable',
    wording: {},
    header_links: [],
    roadmap_hidden: false,
    applied: {
      typography: 'default',
      shape: 'rounded',
      density: 'comfortable',
      wording: false,
      header_links: false,
      roadmap_hidden: false,
    },
    entitlements: {
      current_plan: 'lite',
      can_change_plan: true,
      features: {
        typography_presets: { allowed: true, required_plan: null },
        custom_wording: { allowed: true, required_plan: null },
        header_links_sections: { allowed: true, required_plan: null },
      },
    },
  }),
}))

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setEdition } from '../lib/edition'
import { setFeatures, setTranslationAvailable } from '../lib/features'
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
  translation_enabled: false,
  anonymous_voting_enabled: false,
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
  base_lists: { de: 66, en: 403 },
}

// getAdminBoardWebhook is fetched concurrently with branding/moderation
// (Promise.all) on every page load — needs its own fixture so a test that
// mocks calls *after* the initial load doesn't have its response consumed
// by this unrelated fourth concurrent fetch.
const WEBHOOK_RESPONSE = {
  configured: false,
  url: null,
  active: false,
  deliveries: [],
}

// getAdminBoardSmtp is ALSO fetched concurrently on every page load —
// board_smtp defaults to true in features.ts's DEFAULT_FEATURES (self-host
// default), so it's part of the same Promise.all in every test unless a
// test explicitly turns it off via setFeatures.
const SMTP_RESPONSE = {
  host: '',
  port: 587,
  user: '',
  encryption: 'tls',
  from_email: '',
  from_name: '',
  password_set: false,
  verify_peer: true,
  uses_global_default: true,
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

/** `design` renders the full-screen branding editor route; `settings` the board settings page. */
function renderAdminPage(
  boardSlug = 'demo',
  mode: 'settings' | 'design' = 'settings',
  search = '',
) {
  return render(
    <MemoryRouter
      initialEntries={[
        mode === 'design'
          ? `/admin/boards/${boardSlug}/design${search}`
          : `/admin/boards/${boardSlug}`,
      ]}
    >
      <Routes>
        <Route path="/admin/boards/:boardSlug" element={<AdminPage />} />
        <Route path="/admin/boards/:boardSlug/design" element={<AdminPage mode="design" />} />
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

    renderAdminPage('demo', 'design')

    // Wait for forms to appear
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByTestId('branding-editor-panel')).toBeInTheDocument()

    // Both form sections rendered (not access denied). Scoped to the branding
    // section: unrelated widgets may report their own load errors asynchronously.
    expect(
      within(await screen.findByTestId('branding-editor')).queryByRole('alert'),
    ).not.toBeInTheDocument()

    // Edit primary color field and submit
    const user = userEvent.setup()
    const primaryInput = screen.getByLabelText(/Primärfarbe/i)
    await user.clear(primaryInput)
    await user.type(primaryInput, '#abcdef')

    await user.click(screen.getByTestId('branding-editor-save'))

    // Success feedback
    await waitFor(() => expect(screen.getByText('Branding gespeichert.')).toBeInTheDocument())
  })

  it('opens the editor group named in the deep link', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage('demo', 'design', '?group=images')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())
    expect(screen.getByTestId('branding-editor-group-images')).toHaveAttribute('open')
    expect(screen.getByTestId('branding-editor-group-colors')).not.toHaveAttribute('open')
  })

  it('keeps only the failed group unsaved when one save step is refused', async () => {
    // Page-load GETs in order; the Pro and link-footer saves are refused, the core save is accepted.
    const gets: object[] = [ADMIN_BOOTSTRAP, BRANDING_RESPONSE, MODERATION_RESPONSE]
    let getIndex = 0
    const json = (body: object, status = 200) =>
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      })
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      if ((init?.method ?? 'GET') === 'GET') return json(gets[getIndex++] ?? {})
      if (url.includes('/branding/pro') || url.includes('/link-footer')) {
        return json({ key: 'server_error', message: 'boom' }, 500)
      }
      return json({ ok: true })
    })

    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const user = userEvent.setup()
    const primaryInput = screen.getByLabelText(/Primärfarbe/i)
    await user.clear(primaryInput)
    await user.type(primaryInput, '#abcdef')
    await user.type(await screen.findByTestId('branding-pro-wording-thank_you'), '!')

    await user.click(screen.getByTestId('branding-editor-save'))

    expect(await screen.findByTestId('branding-editor-group-texts-error')).toBeInTheDocument()
    expect(screen.queryByTestId('branding-editor-group-colors-error')).not.toBeInTheDocument()
    expect(screen.queryByTestId('branding-editor-group-colors-dirty')).not.toBeInTheDocument()
    expect(screen.getByTestId('branding-editor-group-texts-dirty')).toBeInTheDocument()
    expect(screen.getByTestId('admin-branding-error')).toBeInTheDocument()
  })

  it('shows a frozen-board notice (downgrade freeze) without hiding the forms', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, frozen_at: '2026-01-01 00:00:00' } },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } },
    ])

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())
    expect(
      screen.getByText(/Board eingefroren – nur privat schalten oder löschen möglich/),
    ).toBeInTheDocument()
    // Forms still render — a frozen board stays manageable, just read-only server-side.
    expect(screen.getByTestId('branding-editor-panel')).toBeInTheDocument()
  })

  it('locks the settings on a frozen board and offers only "make private"', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, frozen_at: '2026-01-01 00:00:00' } },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST branding {visibility: 'private'}
    ])

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('admin-frozen-notice')).toBeInTheDocument())
    expect(screen.getByLabelText(/Primärfarbe/i)).toBeDisabled()
    expect(screen.getByTestId('branding-editor-save')).toBeDisabled()

    const user = userEvent.setup()
    await user.click(screen.getByTestId('admin-frozen-make-private'))

    await waitFor(() => {
      const posts = vi
        .mocked(globalThis.fetch)
        .mock.calls.filter(
          ([url, init]) => String(url).includes('/branding') && init?.method === 'POST',
        )
      expect(posts).toHaveLength(1)
      expect(JSON.parse(String(posts[0]?.[1]?.body))).toEqual({ visibility: 'private' })
    })
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

    renderAdminPage('demo')

    await waitFor(() => expect(screen.getByTestId('admin-branding-section')).toBeInTheDocument())

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
      { body: {} }, // GET webhook
      { body: {} }, // GET notifications
      { body: {} }, // GET chat notifications
      { body: { ok: true } }, // POST branding (toggles, part of the single General save)
      { body: { ok: true, slug: 'demo', name: 'Renamed Board' } }, // PUT rename
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    const nameInput = screen.getByLabelText(/Board-Name/i)
    await user.clear(nameInput)
    await user.type(nameInput, 'Renamed Board')
    await user.click(screen.getByRole('button', { name: /Allgemein speichern/i }))

    await waitFor(() => expect(screen.getByText('Einstellungen gespeichert.')).toBeInTheDocument())

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
      { body: {} }, // GET webhook
      { body: {} }, // GET notifications
      { body: {} }, // GET chat notifications
      { body: {} }, // GET widget (WidgetSettings section)
      { body: { ok: true } }, // POST branding (toggles, part of the single General save)
      { body: { ok: true, slug: 'new-slug', name: 'Demo Board' } }, // PUT rename
      { body: {} }, // GET chat notifications (new slug, refetched before the page reload)
      { body: {} }, // GET widget (WidgetSettings follows the new slug)
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, board_slug: 'new-slug' } },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp (reload)
      { body: {} }, // GET webhook (reload)
      { body: {} }, // GET notifications (reload)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    const slugInput = screen.getByLabelText(/Adresse \(Kurzname\)/i)
    await user.clear(slugInput)
    await user.type(slugInput, 'new-slug')
    await user.click(screen.getByRole('button', { name: /Allgemein speichern/i }))

    // A new address makes the old one stop working: confirm before anything is saved.
    const dialog = await screen.findByRole('alertdialog')
    expect(within(dialog).getByText(/funktioniert danach nicht mehr/i)).toBeInTheDocument()
    await user.click(screen.getByTestId('admin-slug-confirm-confirm'))

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
      { body: {} }, // GET webhook
      { body: {} }, // GET notifications
      { body: {} }, // GET chat notifications
      { body: { ok: true } }, // POST branding (toggles, part of the single General save)
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
    const slugInput = screen.getByLabelText(/Adresse \(Kurzname\)/i)
    await user.clear(slugInput)
    await user.type(slugInput, 'taken-slug')
    await user.click(screen.getByRole('button', { name: /Allgemein speichern/i }))
    await user.click(await screen.findByTestId('admin-slug-confirm-confirm'))

    await waitFor(() =>
      expect(screen.getByText('This slug is already taken in your account.')).toBeInTheDocument(),
    )
  })
})

describe('AdminPage — moderation toggle save', () => {
  it('saves the content filter toggle on click and shows success feedback', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST moderation toggle (saved on click)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Moderation')).toBeInTheDocument())

    const user = userEvent.setup()
    const moderationSection = screen.getByText('Moderation').closest('section') ?? document.body
    // Moderation has no Speichern button any more: every switch saves on click.
    expect(within(moderationSection).queryByRole('button', { name: /^Speichern$/i })).toBeNull()

    // The content filter is enabled in the mock; switching it off saves right away.
    await user.click(screen.getByTestId('admin-mod-toggle'))

    await waitFor(() => expect(screen.getByText('Moderation gespeichert.')).toBeInTheDocument())
    const post = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(
        ([url, init]) => String(url).includes('/moderation') && init?.method === 'POST',
      )
    expect(post).toBeDefined()
    expect(String(post?.[1]?.body)).toContain('"moderation_enabled":"0"')
  })

  it('reverts the content filter toggle when saving fails', async () => {
    // Only the moderation POST fails; the page loads with the normal fixtures first.
    const loads = [ADMIN_BOOTSTRAP, BRANDING_RESPONSE, MODERATION_RESPONSE]
    let loadIndex = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      if (init?.method === 'POST' && url.includes('/moderation')) {
        return new Response(
          JSON.stringify({ error: { key: 'server_error', message: 'Speichern fehlgeschlagen.' } }),
          { status: 500, headers: { 'Content-Type': 'application/json' } },
        )
      }
      const body = loadIndex < loads.length ? loads[loadIndex] : {}
      loadIndex++
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Moderation')).toBeInTheDocument())
    const toggle = screen.getByTestId('admin-mod-toggle')
    expect(toggle).toBeChecked()

    const user = userEvent.setup()
    await user.click(toggle)

    await waitFor(() => expect(screen.getByTestId('admin-mod-toggle')).toBeChecked())
    expect(screen.getByTestId('admin-mod-error')).toBeInTheDocument()
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

  it('discloses the active base blocklist word counts', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText(/Deutsch \(66 Begriffe\)/)).toBeInTheDocument())
    expect(screen.getByText(/Englisch \(403 Begriffe\)/)).toBeInTheDocument()
  })

  it('bulk-adds a comma/newline-separated word list and reports the result', async () => {
    // URL-keyed (not call-order-keyed) mock: this page loads several other
    // concurrent resources (SMTP settings, webhook config, notifications)
    // whose exact call order/count isn't this test's concern.
    const reloadedModeration = {
      ...MODERATION_RESPONSE,
      words: [...MODERATION_RESPONSE.words, { id: 2, word: 'foo' }, { id: 3, word: 'bar' }],
    }
    let moderationGetCount = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      const json = (body: unknown, status = 200) =>
        new Response(JSON.stringify(body), {
          status,
          headers: { 'Content-Type': 'application/json' },
        })

      if (url.includes('/api/bootstrap')) return json(ADMIN_BOOTSTRAP)
      if (url.endsWith('/branding')) return json(BRANDING_RESPONSE)
      if (url.endsWith('/moderation') && method === 'POST') {
        return json({ ok: true, added: 2, skipped: 1 })
      }
      if (url.endsWith('/moderation')) {
        moderationGetCount++
        return json(moderationGetCount === 1 ? MODERATION_RESPONSE : reloadedModeration)
      }
      if (url.endsWith('/smtp')) return json({ configured: false })
      if (url.endsWith('/webhook')) return json(WEBHOOK_RESPONSE)
      if (url.includes('/notifications')) return json({ notifications: [] })
      return json({})
    })

    renderAdminPage()

    const user = userEvent.setup()

    await waitFor(() => expect(screen.getByText('Moderation')).toBeInTheDocument())
    await user.click(screen.getByRole('button', { name: 'Stattdessen eine ganze Liste einfügen' }))

    const textarea = await screen.findByLabelText('Wortliste')
    await user.type(textarea, 'foo, bar{enter}spamword')
    await user.click(screen.getByRole('button', { name: 'Liste hinzufügen' }))

    await waitFor(() =>
      expect(
        screen.getByText('2 Wörter hinzugefügt (1 bereits vorhanden oder leer).'),
      ).toBeInTheDocument(),
    )
    expect(screen.getByText('foo')).toBeInTheDocument()
    expect(screen.getByText('bar')).toBeInTheDocument()
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

    await waitFor(() => expect(screen.getByTestId('admin-branding-section')).toBeInTheDocument())

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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).toBeDisabled()
    expect(screen.getByTestId('admin-logo-locked')).toBeInTheDocument()
    expect(screen.queryByTestId('admin-logo-input')).not.toBeInTheDocument()
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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).not.toBeDisabled()
    expect(screen.getByTestId('admin-logo-input')).not.toBeDisabled()
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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByLabelText(/Sekundärfarbe/i)).not.toBeDisabled()
    expect(screen.getByTestId('admin-logo-input')).not.toBeDisabled()
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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Intro-Text/i), 'Willkommen!')
    await user.click(screen.getByLabelText(/Badge ausblenden/i))
    await user.click(screen.getByTestId('branding-editor-save'))

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

// ── Per-board translation opt-in (ticket 11) ──────────────────────────────────

describe('AdminPage — translation opt-in toggle', () => {
  afterEach(() => {
    setTranslationAvailable(false) // module-level global — reset between tests
  })

  it('hides the toggle when the installation has no translation provider configured', async () => {
    setTranslationAvailable(false)
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())
    expect(screen.queryByLabelText(/automatisch übersetzen/i)).not.toBeInTheDocument()
  })

  it('shows the toggle in the General section, unchecked by default, and saves it independently of the branding form', async () => {
    setTranslationAvailable(true)
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST branding (shared toggle-settings save)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const toggle = screen.getByLabelText(/automatisch übersetzen/i)
    expect(toggle).not.toBeChecked()

    const user = userEvent.setup()
    await user.click(toggle)
    // Translation and anonymous-voting share the ONE "General" section save
    // button (not the Branding form's own submit, nor Moderation's own) —
    // see AdminPage.tsx.
    await user.click(screen.getByRole('button', { name: 'Allgemein speichern' }))

    await waitFor(() => expect(screen.getByText('Einstellungen gespeichert.')).toBeInTheDocument())

    const postCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
    expect(postCall).toBeDefined()
    const body = JSON.parse(String((postCall?.[1] as RequestInit).body))
    expect(body.translation_enabled).toBe(true)
    expect(body.anonymous_voting_enabled).toBe(false)
    // Not a Branding-form submission — visibility is never sent by this save.
    expect(body.visibility).toBeUndefined()
  })

  it('shows the toggle pre-checked when the board already opted in', async () => {
    setTranslationAvailable(true)
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, translation_enabled: true } },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())
    expect(screen.getByLabelText(/automatisch übersetzen/i)).toBeChecked()
  })
})

// ── Per-board anonymous-voting opt-in (ADR 0004) ──────────────────────────────

describe('AdminPage — anonymous-voting opt-in toggle', () => {
  it('shows the toggle in the General section, unchecked by default, and saves it independently of the branding form', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } }, // POST branding (shared toggle-settings save)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const toggle = screen.getByLabelText(/Abstimmen ohne Account erlauben/i)
    expect(toggle).not.toBeChecked()

    const user = userEvent.setup()
    await user.click(toggle)
    await user.click(screen.getByRole('button', { name: 'Allgemein speichern' }))

    await waitFor(() => expect(screen.getByText('Einstellungen gespeichert.')).toBeInTheDocument())

    const postCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
    expect(postCall).toBeDefined()
    const body = JSON.parse(String((postCall?.[1] as RequestInit).body))
    expect(body.anonymous_voting_enabled).toBe(true)
    expect(body.translation_enabled).toBe(false)
    // Not a Branding-form submission — visibility is never sent by this save.
    expect(body.visibility).toBeUndefined()
  })

  it('shows the toggle pre-checked when the board already opted in', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, anonymous_voting_enabled: true } },
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())
    expect(screen.getByLabelText(/Abstimmen ohne Account erlauben/i)).toBeChecked()
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
      { body: OWNER_BOOTSTRAP },
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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

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

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByLabelText(/Badge ausblenden/i)).not.toBeDisabled()
    expect(screen.queryByRole('link', { name: /Jetzt upgraden/i })).not.toBeInTheDocument()
  })

  it('Cloud (billing extension present): shows an upgrade link next to the locked visibility field', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock([
      { body: OWNER_BOOTSTRAP },
      { body: BRANDING_RESPONSE }, // allowed_visibilities: ['public'] — locked
      { body: MODERATION_RESPONSE },
    ])

    renderAdminPage('demo', 'design')

    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const links = screen.getAllByRole('link', { name: /Jetzt upgraden/i })
    expect(links.some((link) => link.getAttribute('href') === '/admin/billing')).toBe(true)
  })
})

describe('AdminPage — board-operator acknowledgment (Cloud-only, ticket 05 follow-up)', () => {
  afterEach(() => {
    setEdition('self-host') // reset to Community defaults between tests
  })

  it('Community/self-host: the section is not rendered at all', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: SMTP_RESPONSE },
      { body: WEBHOOK_RESPONSE },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByTestId('admin-branding-section')).toBeInTheDocument())

    expect(screen.queryByText('Betreiber-Verantwortung')).not.toBeInTheDocument()
  })

  it('Cloud, not yet acknowledged: shows the confirm button; clicking it persists and shows success', async () => {
    setEdition('cloud')
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: SMTP_RESPONSE },
      { body: WEBHOOK_RESPONSE },
      { body: { acknowledged: false, acknowledged_at: null, acknowledged_by_user_id: null } },
      {
        body: {
          acknowledged: true,
          acknowledged_at: '2026-09-09T10:00:00+00:00',
          acknowledged_by_user_id: 1,
        },
      },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Betreiber-Verantwortung')).toBeInTheDocument())

    const button = screen.getByRole('button', { name: 'Jetzt bestätigen' })
    await userEvent.click(button)

    await waitFor(() => expect(screen.getByText(/hast am .* bestätigt/i)).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Jetzt bestätigen' })).not.toBeInTheDocument()
  })

  it('Cloud, already acknowledged: shows the confirmation notice, no button', async () => {
    setEdition('cloud')
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: MODERATION_RESPONSE },
      { body: SMTP_RESPONSE },
      { body: WEBHOOK_RESPONSE },
      {
        body: {
          acknowledged: true,
          acknowledged_at: '2026-09-01T08:00:00+00:00',
          acknowledged_by_user_id: 1,
        },
      },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Betreiber-Verantwortung')).toBeInTheDocument())

    expect(screen.getByText(/hast am/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Jetzt bestätigen' })).not.toBeInTheDocument()
  })
})

// ── Pre-moderation switch (broaden-use-cases issue 05) ───────────────────────

describe('AdminPage — pre-moderation switch', () => {
  it('shows the queue link with the pending count and saves the switch on change', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BRANDING_RESPONSE },
      { body: { ...MODERATION_RESPONSE, premoderation: 'off', pending_count: 3 } },
      { body: { ok: true } },
    ])

    renderAdminPage()

    await waitFor(() =>
      expect(
        screen.getByLabelText('Neue Ideen vor der Veröffentlichung freigeben'),
      ).toBeInTheDocument(),
    )
    expect(screen.getByRole('link', { name: /Freigabe-Warteschlange öffnen/ })).toHaveTextContent(
      '3 wartend',
    )

    await userEvent
      .setup()
      .click(screen.getByLabelText('Neue Ideen vor der Veröffentlichung freigeben'))

    await waitFor(() =>
      expect(screen.getByText('Freigabe-Einstellung gespeichert.')).toBeInTheDocument(),
    )
    const post = vi.mocked(globalThis.fetch).mock.calls.find(([, init]) => init?.method === 'POST')
    expect(JSON.parse(String(post?.[1]?.body))).toEqual({
      action: 'premoderation',
      premoderation: 'all',
    })
  })
})

// ── Guest idea submission (ADR 0006) ─────────────────────────────────────────

describe('AdminPage — ideas without an account', () => {
  afterEach(() => {
    setFeatures(undefined)
  })

  // AdminPage is heavy to render; under a fully parallel suite run the
  // dialog flows can exceed the default 10s budget.
  const GUEST_TEST_TIMEOUT_MS = 30_000
  const GUEST_LABEL = 'Ideen ohne Konto erlauben'
  const GUEST_REVIEW_LABEL = 'Ideen ohne Konto vor der Veröffentlichung freigeben'

  /** URL-routed mock; records every POST body. */
  function mockAdmin(
    moderation: object,
    bootstrap: object = ADMIN_BOOTSTRAP,
  ): Array<Record<string, unknown>> {
    const posts: Array<Record<string, unknown>> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const json = (body: object) =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      if (init?.method === 'POST') {
        posts.push(JSON.parse(String(init.body)) as Record<string, unknown>)
        return json({ ok: true })
      }
      if (url.includes('/bootstrap')) return json(bootstrap)
      if (url.endsWith('/moderation')) return json(moderation)
      if (url.includes('/smtp')) return json(SMTP_RESPONSE)
      if (url.includes('/webhook')) return json(WEBHOOK_RESPONSE)
      return json(BRANDING_RESPONSE)
    })
    return posts
  }

  function guestModeration(
    anon: {
      enabled: boolean
      instance_allowed?: boolean
      turnstile_configured: boolean
      plan_allowed: boolean
    },
    premoderation = 'off',
  ) {
    return { ...MODERATION_RESPONSE, premoderation, pending_count: 0, anonymous_submission: anon }
  }

  it(
    'switches guests on and shows that guest ideas are now reviewed',
    async () => {
      const posts = mockAdmin(
        guestModeration({ enabled: false, turnstile_configured: true, plan_allowed: true }),
      )
      renderAdminPage()

      const toggle = await screen.findByLabelText(GUEST_LABEL)
      expect(toggle).not.toBeChecked()
      await userEvent.setup().click(toggle)

      await waitFor(() => expect(posts).toHaveLength(1))
      expect(posts[0]).toEqual({
        action: 'anonymous_submission',
        anonymous_submission_enabled: '1',
      })
      // The server raised premoderation off → anonymous; the UI mirrors it.
      expect(await screen.findByLabelText(GUEST_REVIEW_LABEL)).toBeChecked()
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'disables the switch with an explanation when Turnstile is not configured',
    async () => {
      mockAdmin(
        guestModeration({ enabled: false, turnstile_configured: false, plan_allowed: true }),
      )
      renderAdminPage()

      expect(await screen.findByLabelText(GUEST_LABEL)).toBeDisabled()
      expect(screen.getByText(/Bot-Schutz \(Cloudflare Turnstile\)/)).toBeInTheDocument()
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'hides the switch while the installation has guest ideas turned off',
    async () => {
      mockAdmin(
        guestModeration({
          enabled: false,
          instance_allowed: false,
          turnstile_configured: true,
          plan_allowed: true,
        }),
      )
      renderAdminPage()

      expect(
        await screen.findByLabelText('Neue Ideen vor der Veröffentlichung freigeben'),
      ).toBeInTheDocument()
      expect(screen.queryByLabelText(GUEST_LABEL)).not.toBeInTheDocument()
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'keeps a still-enabled board switchable off while the installation has guest ideas turned off',
    async () => {
      const posts = mockAdmin(
        guestModeration(
          {
            enabled: true,
            instance_allowed: false,
            turnstile_configured: true,
            plan_allowed: true,
          },
          'anonymous',
        ),
      )
      renderAdminPage()

      const toggle = await screen.findByLabelText(GUEST_LABEL)
      expect(toggle).toBeChecked()
      expect(toggle).not.toBeDisabled()
      expect(
        screen.getByText(/auf dieser Installation derzeit nicht verfügbar/),
      ).toBeInTheDocument()
      await userEvent.setup().click(toggle)
      await waitFor(() => expect(posts).toHaveLength(1))
      expect(posts[0]).toEqual({
        action: 'anonymous_submission',
        anonymous_submission_enabled: '0',
      })
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'Free plan: disabled switch with the upgrade link',
    async () => {
      setFeatures({ board_smtp: true, legal_links: null, billing: true })
      mockAdmin(
        guestModeration({ enabled: false, turnstile_configured: true, plan_allowed: false }),
        OWNER_BOOTSTRAP,
      )
      renderAdminPage()

      expect(await screen.findByLabelText(GUEST_LABEL)).toBeDisabled()
      const hint = screen.getByText(/ab dem Lite-Tarif/).closest('p') as HTMLElement
      expect(within(hint).getByRole('link', { name: /Jetzt upgraden/i })).toBeInTheDocument()
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'asks for confirmation before guest ideas go public without review',
    async () => {
      const posts = mockAdmin(
        guestModeration(
          { enabled: true, turnstile_configured: true, plan_allowed: true },
          'anonymous',
        ),
      )
      renderAdminPage()

      const user = userEvent.setup()
      const review = await screen.findByLabelText(GUEST_REVIEW_LABEL)
      expect(review).toBeChecked()
      await user.click(review)

      const dialog = await screen.findByRole('alertdialog')
      expect(within(dialog).getByText(/sofort öffentlich/)).toBeInTheDocument()
      expect(posts).toHaveLength(0)

      await user.click(
        within(dialog).getByRole('button', { name: 'Ohne Freigabe veröffentlichen' }),
      )
      await waitFor(() => expect(posts).toHaveLength(1))
      expect(posts[0]).toEqual({
        action: 'premoderation',
        premoderation: 'off',
        confirm_guest_public: true,
      })
      await waitFor(() => expect(screen.getByLabelText(GUEST_REVIEW_LABEL)).not.toBeChecked())
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'cancelling the confirmation keeps guest ideas under review',
    async () => {
      const posts = mockAdmin(
        guestModeration(
          { enabled: true, turnstile_configured: true, plan_allowed: true },
          'anonymous',
        ),
      )
      renderAdminPage()

      const user = userEvent.setup()
      await user.click(await screen.findByLabelText(GUEST_REVIEW_LABEL))
      const dialog = await screen.findByRole('alertdialog')
      await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }))

      expect(posts).toHaveLength(0)
      expect(screen.getByLabelText(GUEST_REVIEW_LABEL)).toBeChecked()
    },
    GUEST_TEST_TIMEOUT_MS,
  )

  it(
    'turning off "approve all" keeps guests under review instead of switching it off',
    async () => {
      const posts = mockAdmin(
        guestModeration({ enabled: true, turnstile_configured: true, plan_allowed: true }, 'all'),
      )
      renderAdminPage()

      await userEvent
        .setup()
        .click(await screen.findByLabelText('Neue Ideen vor der Veröffentlichung freigeben'))

      await waitFor(() => expect(posts).toHaveLength(1))
      expect(posts[0]).toEqual({ action: 'premoderation', premoderation: 'anonymous' })
    },
    GUEST_TEST_TIMEOUT_MS,
  )
})

// ── AI duplicate confirmation (issue 11) ──────────────────────────────────────

describe('AdminPage — AI-assisted duplicate detection line', () => {
  afterEach(() => {
    setFeatures(undefined)
  })

  function brandingWith(duplicateConfirmation: string | null) {
    return [
      { body: OWNER_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, duplicate_confirmation: duplicateConfirmation } },
      { body: MODERATION_RESPONSE },
    ]
  }

  it('Pro: says the feature is on and what is sent', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock(brandingWith('available'))
    renderAdminPage()

    const line = await screen.findByTestId('admin-ai-duplicates')
    expect(within(line).getByText(/Übermittelt werden Titel und Text der Idee/)).toBeInTheDocument()
    expect(within(line).queryByRole('link')).not.toBeInTheDocument()
  })

  it('plan without it: factual upgrade hint with the upgrade link', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock(brandingWith('upgrade_required'))
    renderAdminPage()

    const line = await screen.findByTestId('admin-ai-duplicates')
    expect(
      within(line).getByText(/KI-gestützte Vorschläge sind Teil von Pro\./),
    ).toBeInTheDocument()
    expect(within(line).getByRole('link', { name: /Jetzt upgraden/i })).toHaveAttribute(
      'href',
      '/admin/billing',
    )
  })

  it('not switched on for the account (opt-in): says so, no upgrade link', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock(brandingWith('disabled'))
    renderAdminPage()

    const line = await screen.findByTestId('admin-ai-duplicates')
    expect(within(line).getByText(/Für diesen Account nicht eingeschaltet/)).toBeInTheDocument()
    expect(within(line).queryByRole('link')).not.toBeInTheDocument()
  })

  it('Community (no confirmer): nothing is shown', async () => {
    makeFetchMock(brandingWith(null))
    renderAdminPage()

    await waitFor(() => expect(screen.getByTestId('admin-branding-section')).toBeInTheDocument())
    expect(screen.queryByTestId('admin-ai-duplicates')).not.toBeInTheDocument()
  })
})

describe('AdminPage — Q19 branding save with plan-locked values (P-38)', () => {
  /** Server entitlements for a Free account: only secondary_color is locked (Lite). */
  const FREE_ENTITLEMENTS = {
    current_plan: 'free',
    can_change_plan: false,
    features: {
      secondary_color: { allowed: false, required_plan: 'lite' },
      logo_url: { allowed: false, required_plan: 'lite' },
    },
  }

  function postBodies(): Array<Record<string, unknown>> {
    return vi
      .mocked(globalThis.fetch)
      .mock.calls.filter(([, init]) => (init as RequestInit | undefined)?.method === 'POST')
      .map(([, init]) => JSON.parse(String((init as RequestInit).body)) as Record<string, unknown>)
  }

  it('omits a locked field from the save payload and keeps its stored value visible', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          secondary_color: '#112233',
          allowed_branding_fields: [],
          entitlements: FREE_ENTITLEMENTS,
        },
      },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } },
    ])

    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    // Stored but inactive on Free: the hint names the tier that reactivates it.
    expect(
      screen.getByText(/Gespeichert, aber auf deinem Tarif gerade nicht aktiv/),
    ).toBeInTheDocument()
    expect(screen.getByText(/Wird wieder aktiv, sobald du auf Lite wechselst/)).toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByTestId('branding-editor-save'))
    await waitFor(() => expect(screen.getByText('Branding gespeichert.')).toBeInTheDocument())

    const [body] = postBodies()
    expect(body).toHaveProperty('primary_color')
    expect(body).not.toHaveProperty('secondary_color')
    expect(body).not.toHaveProperty('logo_url')
  })

  it('sends a field the plan allows, even when it is also set', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          ...BRANDING_RESPONSE,
          secondary_color: '#112233',
          allowed_branding_fields: ['secondary_color'],
          entitlements: {
            current_plan: 'lite',
            can_change_plan: false,
            features: { secondary_color: { allowed: true, required_plan: null } },
          },
        },
      },
      { body: MODERATION_RESPONSE },
      { body: { ok: true } },
    ])

    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByTestId('branding-editor-save'))
    await waitFor(() => expect(screen.getByText('Branding gespeichert.')).toBeInTheDocument())

    const [body] = postBodies()
    expect(body).toMatchObject({ secondary_color: '#112233' })
  })
})

describe('AdminPage — SMTP certificate check under Erweitert', () => {
  /** URL-routed mock: the SMTP settings return the given certificate-check state. */
  function mockSmtpPage(verifyPeer: boolean) {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const json = (body: object) =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      if (url.includes('/bootstrap')) return json(ADMIN_BOOTSTRAP)
      if (url.endsWith('/moderation')) return json(MODERATION_RESPONSE)
      if (url.includes('/smtp')) return json({ ...SMTP_RESPONSE, verify_peer: verifyPeer })
      if (url.includes('/webhook')) return json(WEBHOOK_RESPONSE)
      return json(BRANDING_RESPONSE)
    })
  }

  it('keeps the certificate option collapsed under Erweitert when it is off', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    mockSmtpPage(true)
    renderAdminPage()

    const advanced = await screen.findByTestId('admin-smtp-advanced')
    await waitFor(() =>
      expect(
        vi.mocked(globalThis.fetch).mock.calls.some(([url]) => String(url).includes('/smtp')),
      ).toBe(true),
    )
    expect(advanced).not.toHaveAttribute('open')
    expect(advanced.querySelector('summary')?.textContent).toBe('Erweitert')
  })

  it('opens the block when the certificate check is already switched off', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    mockSmtpPage(false)
    renderAdminPage()

    await waitFor(() => expect(screen.getByTestId('admin-smtp-advanced')).toHaveAttribute('open'))
    expect(screen.getByTestId('admin-smtp-verify-peer')).toBeChecked()
  })
})

describe('AdminPage — board name in the editor', () => {
  const json = (body: object, status = 200) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })

  /** GETs answer the page load in order; PUT /admin/boards/demo is the Allgemein rename endpoint. */
  function mockEditorFetch(renameResponse: { status: number; body: object }) {
    const gets: object[] = [ADMIN_BOOTSTRAP, BRANDING_RESPONSE, MODERATION_RESPONSE]
    let getIndex = 0
    const puts: Array<{ url: string; body: unknown }> = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      if (method === 'GET') return json(gets[getIndex++] ?? {})
      if (method === 'PUT') {
        puts.push({ url, body: JSON.parse(String(init?.body)) })
        return json(renameResponse.body, renameResponse.status)
      }
      return json({ ok: true })
    })
    return puts
  }

  const renameRequests = (puts: Array<{ url: string; body: unknown }>) =>
    puts.filter((put) => put.url === '/admin/boards/demo')

  it('edits the name in the editor, previews it live and saves it through the rename endpoint', async () => {
    const puts = mockEditorFetch({
      status: 200,
      body: { ok: true, slug: 'demo', name: 'Neuer Name' },
    })
    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const user = userEvent.setup()
    const nameInput = screen.getByTestId('admin-editor-board-name')
    await user.clear(nameInput)
    await user.type(nameInput, 'Neuer Name')

    // Live preview shows the draft name before anything is saved.
    expect(screen.getAllByText('Neuer Name').length).toBeGreaterThan(0)
    expect(puts).toHaveLength(0)

    await user.click(screen.getByTestId('branding-editor-save'))

    // The link-footer save follows the rename in the same click; only the rename counts here.
    await waitFor(() => expect(renameRequests(puts)).toHaveLength(1))
    expect(renameRequests(puts)[0]?.body).toEqual({ name: 'Neuer Name' })
  })

  it('renders the preview texts in the wording locale being edited, not the page language', async () => {
    mockEditorFetch({ status: 200, body: { ok: true, slug: 'demo', name: 'Demo Board' } })
    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    expect(screen.getByRole('group', { name: 'Vorschau' })).toBeInTheDocument()

    const user = userEvent.setup()
    await user.selectOptions(await screen.findByTestId('branding-pro-locale'), 'en')

    expect(screen.getByRole('group', { name: 'Preview' })).toBeInTheDocument()
  })

  it('shows the server validation on the editor field and keeps the texts group unsaved', async () => {
    mockEditorFetch({
      status: 422,
      body: {
        error: { key: 'validation_error', message: 'Ungültig', fields: { name: 'Name zu lang' } },
      },
    })
    renderAdminPage('demo', 'design')
    await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())

    const user = userEvent.setup()
    const nameInput = screen.getByTestId('admin-editor-board-name')
    await user.clear(nameInput)
    await user.type(nameInput, 'x')
    await user.click(screen.getByTestId('branding-editor-save'))

    await waitFor(() => expect(screen.getByTestId('admin-branding-error')).toBeInTheDocument())
    expect(await screen.findByText('Name zu lang')).toBeInTheDocument()
    expect(screen.getByTestId('branding-editor-group-texts-error')).toBeInTheDocument()
    expect(screen.getByTestId('branding-editor-group-texts-dirty')).toBeInTheDocument()
  })
})

describe('AdminPage — status reason setting', () => {
  it('saves the board switch with the general settings', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, status_reason_required: false } },
      { body: MODERATION_RESPONSE },
      { body: {} }, // GET smtp
      { body: {} }, // GET webhook
      { body: {} }, // GET notifications
      { body: {} }, // GET chat notifications
      { body: { ok: true } }, // POST branding (single General save)
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByTestId('admin-status-reason-required'))
    await user.click(screen.getByRole('button', { name: /Allgemein speichern/i }))

    await waitFor(() => expect(screen.getByText('Einstellungen gespeichert.')).toBeInTheDocument())

    const postCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(
        ([url, init]) =>
          String(url).includes('/branding') && (init as RequestInit | undefined)?.method === 'POST',
      )
    expect(postCall).toBeDefined()
    const body = JSON.parse(String((postCall?.[1] as RequestInit).body))
    expect(body.status_reason_required).toBe(true)
  })

  it('shows the switch off when the board does not require a reason', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { ...BRANDING_RESPONSE, status_reason_required: false } },
      { body: MODERATION_RESPONSE },
      { body: {} },
      { body: {} },
      { body: {} },
      { body: {} },
    ])

    renderAdminPage()

    await waitFor(() => expect(screen.getByText('Allgemein')).toBeInTheDocument())
    expect(screen.getByTestId('admin-status-reason-required')).not.toBeChecked()
  })
})
