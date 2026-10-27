/**
 * Live preview of the design editor (P-27, owner 2026-10-25): every editor
 * setting shows on the canvas at once, unsaved. One test per field: change it
 * on the left, see the effect on the right (data-testid in the preview).
 * Plan-locked values are kept out of the canvas and named by their tier.
 */

vi.mock('../lib/api/boardAssets', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardAssets')>()),
  getBoardAssets: vi.fn(),
}))
vi.mock('../lib/api/boardBranding', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardBranding')>()),
  getBoardBranding: vi.fn(),
}))

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getBoardAssets } from '../lib/api/boardAssets'
import { getBoardBranding } from '../lib/api/boardBranding'
import { setFeatures, setTranslationAvailable } from '../lib/features'
import AdminPage from '../pages/AdminPage'

const ADMIN_BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 1, is_admin: true } }

const BRANDING_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  primary_color: '#1fa890',
  secondary_color: null,
  logo_url: null,
  intro: null,
  hide_badge: false,
  cover_pattern: null,
  translation_enabled: false,
  anonymous_voting_enabled: false,
  visibility: 'public',
  allowed_visibilities: ['public'],
  allowed_branding_fields: ['cover_pattern'],
  frozen_at: null,
}

const MODERATION_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  moderation_enabled: true,
  words: [],
  base_lists: { de: 66, en: 403 },
}

const WEBHOOK_RESPONSE = { configured: false, url: null, active: false, deliveries: [] }

const PRO_ALL_ALLOWED = {
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
    current_plan: 'pro',
    can_change_plan: false,
    features: {
      typography_presets: { allowed: true, required_plan: null },
      custom_wording: { allowed: true, required_plan: null },
      header_links_sections: { allowed: true, required_plan: null },
    },
  },
}

const ASSETS_ALL_ALLOWED = {
  variants: {},
  allowed: { cover_upload: true, favicon_og: true, back_link_footer: true },
  max_upload_bytes: 4194304,
  link: { back_link_url: '', back_link_label: '', footer_note: '' },
}

function makeFetchMock(branding: object) {
  let call = 0
  const bodies = [ADMIN_BOOTSTRAP, branding, MODERATION_RESPONSE]
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    let body: object = MODERATION_RESPONSE
    if (url.includes('/webhook')) body = WEBHOOK_RESPONSE
    else if (url.includes('/smtp')) body = {}
    else if (call < bodies.length) body = bodies[call++]
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

async function renderDesign(overrides: { pro?: object; assets?: object; branding?: object } = {}) {
  vi.mocked(getBoardBranding).mockResolvedValue(
    (overrides.pro ?? PRO_ALL_ALLOWED) as Awaited<ReturnType<typeof getBoardBranding>>,
  )
  vi.mocked(getBoardAssets).mockResolvedValue(
    (overrides.assets ?? ASSETS_ALL_ALLOWED) as Awaited<ReturnType<typeof getBoardAssets>>,
  )
  makeFetchMock(overrides.branding ?? BRANDING_RESPONSE)
  render(
    <MemoryRouter initialEntries={['/admin/boards/demo/design']}>
      <Routes>
        <Route path="/admin/boards/:boardSlug/design" element={<AdminPage mode="design" />} />
        <Route path="/admin/boards/:boardSlug" element={<div />} />
        <Route path="/admin/boards" element={<div />} />
        <Route path="/login" element={<div />} />
      </Routes>
    </MemoryRouter>,
  )
  await waitFor(() => expect(screen.getByTestId('branding-editor')).toBeInTheDocument())
  // Pro values and link fields load on their own requests; wait until they are editable.
  await screen.findByTestId('branding-pro-typography')
  await screen.findByTestId('admin-board-images')
  await waitFor(() => expect(screen.getByTestId('admin-board-link-form')).toBeInTheDocument())
  return screen.getByTestId('branding-preview-frame')
}

function preview(): HTMLElement {
  return screen.getByTestId('branding-preview-frame')
}

beforeEach(() => {
  setFeatures({ board_smtp: false })
  setTranslationAvailable(false)
  vi.restoreAllMocks()
  // Object URLs for picked image files do not exist in jsdom.
  URL.createObjectURL = vi.fn(() => 'blob:preview')
  URL.revokeObjectURL = vi.fn()
  // jsdom has no scrollIntoView.
  Element.prototype.scrollIntoView = vi.fn()
})

describe('BrandingBuilder — live preview of unsaved settings', () => {
  it('typography preset shows on the canvas', async () => {
    await renderDesign()
    expect(preview()).not.toHaveAttribute('data-board-typography', 'mono')
    await userEvent.setup().selectOptions(screen.getByTestId('branding-pro-typography'), 'mono')
    expect(preview()).toHaveAttribute('data-board-typography', 'mono')
  })

  it('shape preset shows on the canvas', async () => {
    await renderDesign()
    await userEvent.setup().selectOptions(screen.getByTestId('branding-pro-shape'), 'square')
    expect(preview()).toHaveAttribute('data-board-shape', 'square')
  })

  it('density preset shows on the canvas', async () => {
    await renderDesign()
    await userEvent.setup().selectOptions(screen.getByTestId('branding-pro-density'), 'compact')
    expect(preview()).toHaveAttribute('data-board-density', 'compact')
  })

  it('cover pattern tile shows on the cover when no cover image is set', async () => {
    await renderDesign()
    await userEvent.setup().click(screen.getByTestId('admin-cover-pattern-option-waves'))
    expect(screen.getByTestId('branding-preview-cover-pattern')).toHaveAttribute(
      'data-cover-pattern',
      'waves',
    )
  })

  it('cover fills the preview frame without container units (no zoom-dependent width)', async () => {
    await renderDesign()
    const cover = screen.getByTestId('branding-preview-cover-pattern')
    // The frame variant is plain width:100%; the board's bleed class uses cqw, which the zoomed device frame must not rely on.
    expect(cover).toHaveClass('ds-board-cover', 'ds-board-cover--frame')
    expect(cover).not.toHaveClass('ds-board-cover--bleed')
  })

  it('idea plural noun shows in the board stat line', async () => {
    await renderDesign()
    await userEvent.setup().type(screen.getByTestId('branding-pro-wording-idea_plural'), 'Anliegen')
    expect(screen.getByTestId('branding-editor-page-board')).toHaveAttribute('aria-pressed', 'true')
    expect(preview()).toHaveTextContent('2 Anliegen')
  })

  it('idea singular noun shows on the idea page', async () => {
    await renderDesign()
    const user = userEvent.setup()
    await user.click(screen.getByTestId('branding-editor-page-idea'))
    await user.type(screen.getByTestId('branding-pro-wording-idea_singular'), 'Anliegen')
    expect(preview()).toHaveTextContent('1 Anliegen')
  })

  it('submit button text shows on the submit page and focusing it switches there', async () => {
    await renderDesign()
    const user = userEvent.setup()
    await user.type(screen.getByTestId('branding-pro-wording-submit_button'), 'Anregen')
    expect(preview()).toHaveAttribute('data-page', 'submit')
    expect(screen.getByTestId('branding-preview-submit-button')).toHaveTextContent('Anregen')
  })

  it('empty-state text shows in the empty board state', async () => {
    await renderDesign()
    await userEvent
      .setup()
      .type(screen.getByTestId('branding-pro-wording-empty_state'), 'Noch nichts da')
    expect(preview()).toHaveAttribute('data-state', 'empty')
    expect(screen.getByTestId('branding-preview-empty')).toHaveTextContent('Noch nichts da')
  })

  it('thank-you text shows on the submit page in the thank-you state', async () => {
    await renderDesign()
    await userEvent
      .setup()
      .type(screen.getByTestId('branding-pro-wording-thank_you'), 'Super, danke!')
    expect(preview()).toHaveAttribute('data-page', 'submit')
    expect(screen.getByTestId('branding-preview-thanks')).toHaveTextContent('Super, danke!')
  })

  it('back link label and https target show in the footer', async () => {
    await renderDesign()
    const user = userEvent.setup()
    await user.type(screen.getByTestId('admin-board-link-label'), 'Zur Startseite')
    await user.type(screen.getByTestId('admin-board-link-url'), 'https://example.org')
    expect(
      within(screen.getByTestId('branding-preview-footer')).getByText('Zur Startseite'),
    ).toBeInTheDocument()
    expect(preview()).toHaveAttribute('data-page', 'board')
  })

  it('footer note shows in the footer', async () => {
    await renderDesign()
    await userEvent.setup().type(screen.getByTestId('admin-board-footer-note'), 'Fußnote Test')
    expect(screen.getByTestId('branding-preview-footer')).toHaveTextContent('Fußnote Test')
  })

  it('hiding the roadmap removes its link; the roadmap page shows a note', async () => {
    await renderDesign()
    const user = userEvent.setup()
    // Board pages show the Ideen / Roadmap tabs under the profile, not a topbar link.
    expect(screen.getByTestId('branding-preview-profile-tabs')).toBeInTheDocument()
    await user.click(screen.getByTestId('branding-pro-roadmap-hidden'))
    expect(screen.queryByTestId('branding-preview-profile-tabs')).not.toBeInTheDocument()
    await user.click(screen.getByTestId('branding-editor-page-roadmap'))
    expect(screen.getByTestId('branding-preview-roadmap-hidden')).toBeInTheDocument()
  })

  it('a picked favicon file shows in the browser-tab mock before upload', async () => {
    await renderDesign()
    const file = new File(['x'], 'icon.png', { type: 'image/png' })
    await userEvent.setup().upload(screen.getByTestId('admin-board-asset-favicon-input'), file)
    expect(screen.getByTestId('branding-preview-tab-icon')).toBeInTheDocument()
  })

  it('a picked share image shows on the share page', async () => {
    await renderDesign()
    const user = userEvent.setup()
    const file = new File(['x'], 'og.png', { type: 'image/png' })
    await user.upload(screen.getByTestId('admin-board-asset-og-input'), file)
    expect(preview()).toHaveAttribute('data-page', 'share')
    expect(screen.getByTestId('branding-preview-og-image')).toBeInTheDocument()
  })

  it('a locked preset is kept out of the canvas and named by its tier', async () => {
    await renderDesign({
      pro: {
        ...PRO_ALL_ALLOWED,
        typography_preset: 'inter',
        entitlements: {
          current_plan: 'lite',
          can_change_plan: true,
          features: {
            typography_presets: { allowed: false, required_plan: 'pro' },
            custom_wording: { allowed: true, required_plan: null },
            header_links_sections: { allowed: true, required_plan: null },
          },
        },
      },
    })
    expect(preview()).not.toHaveAttribute('data-board-typography', 'inter')
    expect(screen.getByTestId('branding-preview-locked-presets')).toBeInTheDocument()
    expect(screen.getByTestId('branding-pro-typography')).toBeDisabled()
  })

  it('a locked cover pattern is not drawn on the cover', async () => {
    await renderDesign({
      branding: { ...BRANDING_RESPONSE, cover_pattern: 'waves', allowed_branding_fields: [] },
    })
    expect(screen.getByTestId('admin-cover-pattern-option-waves')).toBeDisabled()
    expect(screen.getByTestId('branding-preview-cover-pattern')).not.toHaveAttribute(
      'data-cover-pattern',
      'waves',
    )
    expect(screen.getByTestId('branding-preview-locked-pattern')).toBeInTheDocument()
  })

  it('locked back link and footer on Free: not shown in the footer, named by tier', async () => {
    await renderDesign({
      assets: {
        ...ASSETS_ALL_ALLOWED,
        allowed: { cover_upload: true, favicon_og: true, back_link_footer: false },
        link: { back_link_url: 'https://example.org', back_link_label: 'Zurück', footer_note: '' },
      },
    })
    // Exact match: the footer's placeholder text also contains the back label.
    expect(
      within(screen.getByTestId('branding-preview-footer')).queryByText('Zurück', { exact: true }),
    ).not.toBeInTheDocument()
    expect(screen.getByTestId('branding-preview-locked-links')).toBeInTheDocument()
  })
})
