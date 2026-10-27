/**
 * Board QR code (broaden-use-cases issue 09) — where it is offered: board
 * settings (AdminPage) and the setup wizard's ready step. Public/unlisted
 * boards get the collapsed "Show QR code" toggle; private boards get none.
 */

// Board imagery settings fetch their own endpoint on mount; mock it so the
// fetch-sequence mocks in this file keep matching the calls they expect.
vi.mock('../lib/api/boardAssets', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardAssets')>()),
  getBoardAssets: vi.fn().mockResolvedValue({
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true },
    max_upload_bytes: 4194304,
    link: { back_link_url: null, back_link_label: null, footer_note: null },
  }),
}))

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AdminPage from '../pages/AdminPage'
import BoardsAdminPage from '../pages/BoardsAdminPage'

const ADMIN_BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 1, is_admin: true } }

const BRANDING = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  primary_color: null,
  secondary_color: null,
  logo_url: null,
  intro: null,
  hide_badge: false,
  translation_enabled: false,
  anonymous_voting_enabled: false,
  visibility: 'public',
  allowed_visibilities: ['public', 'unlisted', 'private'],
  allowed_branding_fields: [],
  frozen_at: null,
}

function json(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/** Routes the admin page's concurrent loads by path, not by call order. */
function mockAdminFetch(visibility: string) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const path = String(input)
    if (init?.method === 'POST') return json({ ok: true })
    if (path.endsWith('/branding')) return json({ ...BRANDING, visibility })
    if (path.endsWith('/moderation')) {
      return json({
        board_slug: 'demo',
        board_name: 'Demo Board',
        moderation_enabled: false,
        words: [],
        base_lists: { de: 0, en: 0 },
      })
    }
    if (path.endsWith('/webhook'))
      return json({ configured: false, url: null, active: false, deliveries: [] })
    if (path.endsWith('/smtp')) return json({})
    return json(ADMIN_BOOTSTRAP)
  })
}

function renderAdminPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/boards/demo']}>
      <Routes>
        <Route path="/admin/boards/:boardSlug" element={<AdminPage />} />
        <Route path="/admin/boards/:boardSlug/poster" element={<div data-testid="poster-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('AdminPage — board QR code', () => {
  it.each([
    'public',
    'unlisted',
  ])('offers the QR code for a %s board, linking to the poster', async (visibility) => {
    mockAdminFetch(visibility)
    const user = userEvent.setup()
    renderAdminPage()

    const toggle = await screen.findByRole('button', { name: 'QR-Code anzeigen' })
    // Collapsed by default: the page looks as before until it is opened.
    expect(screen.queryByRole('img', { name: /QR-Code für/ })).not.toBeInTheDocument()

    await user.click(toggle)
    expect(
      screen.getByRole('img', { name: `QR-Code für ${window.location.origin}/demo` }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole('link', { name: 'Druckbares Poster öffnen' }))
    expect(await screen.findByTestId('poster-page')).toBeInTheDocument()
  })

  it('offers no QR code for a private board', async () => {
    mockAdminFetch('private')
    renderAdminPage()
    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'QR-Code anzeigen' })).not.toBeInTheDocument()
  })
})

describe('Setup wizard — board QR code on the ready step', () => {
  /** The account default decides the wizard's preselected visibility. */
  function mockWizardFetch(visibility: string): () => Record<string, unknown> | null {
    let created: Record<string, unknown> | null = null
    let getIndex = 0
    const gets = [
      ADMIN_BOOTSTRAP,
      {
        boards: [],
        account: {
          onboarding_completed_at: null,
          allowed_visibilities: ['public', 'unlisted', 'private'],
          default_visibility: visibility,
        },
      },
    ]
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (_input, init) => {
      // Only the create call: the wizard also POSTs the look preset after it.
      if (init?.method === 'POST' && String(_input).endsWith('/admin/boards')) {
        created = JSON.parse(init.body as string)
        return json({ ok: true, slug: 'fest', name: 'Fest' }, 201)
      }
      const payload = gets[Math.min(getIndex, gets.length - 1)]
      getIndex++
      return json(payload)
    })
    return () => created
  }

  async function createBoard(visibility: 'public' | 'unlisted' | 'private') {
    const createdBody = mockWizardFetch(visibility)
    render(
      <MemoryRouter initialEntries={['/admin/boards']}>
        <Routes>
          <Route path="/admin/boards" element={<BoardsAdminPage />} />
        </Routes>
      </MemoryRouter>,
    )
    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: /Los geht's/i }))
    await user.type(await screen.findByLabelText(/^Name/i), 'Fest')
    await user.click(screen.getByRole('button', { name: /Board anlegen/i }))
    // The optional "how your board looks" step sits between creation and the ready step; skip it.
    await user.click(await screen.findByTestId('setup-wizard-look-skip'))
    await screen.findByText(/Öffentlicher Link/i)
    expect(createdBody()?.visibility).toBe(visibility)
    return user
  }

  it('offers the QR code for a non-private board created in the wizard', async () => {
    const user = await createBoard('unlisted')
    await user.click(screen.getByRole('button', { name: 'QR-Code anzeigen' }))
    expect(
      screen.getByRole('img', { name: `QR-Code für ${window.location.origin}/fest` }),
    ).toBeInTheDocument()
  })

  it('offers no QR code for a private board created in the wizard', async () => {
    await createBoard('private')
    expect(screen.queryByRole('button', { name: 'QR-Code anzeigen' })).not.toBeInTheDocument()
  })
})
