/**
 * Installation capabilities (lib/features.ts) as seen by the pages:
 *  - PageShell renders footer legal links only when the server supplies them
 *    (a bare self-host install ships none);
 *  - AdminPage neither fetches nor renders the per-board SMTP sheet when
 *    `board_smtp` is off (hosted multi-tenant installs).
 */

import { render, screen, waitFor } from '@testing-library/react'
import { PageShell } from '@votepit/ui'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { legalLinksFor, setFeatures } from '../lib/features'
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
  visibility: 'public',
  allowed_visibilities: ['public'],
  allowed_branding_fields: [],
  frozen_at: null,
}
const MODERATION_RESPONSE = {
  board_slug: 'demo',
  board_name: 'Demo Board',
  moderation_enabled: false,
  words: [],
}

afterEach(() => {
  setFeatures(undefined)
  vi.restoreAllMocks()
})

describe('legal links', () => {
  it('are empty by default — a self-hosted install has no footer links', () => {
    expect(legalLinksFor('de')).toEqual([])
    render(<PageShell legalLinks={legalLinksFor('de')}>content</PageShell>)
    expect(screen.queryByRole('contentinfo')).not.toBeInTheDocument()
  })

  it('come from bootstrap features per language, falling back to English', () => {
    setFeatures({
      board_smtp: true,
      legal_links: { en: [{ label: 'Terms', href: 'https://example.test/terms' }] },
    })
    expect(legalLinksFor('de')).toEqual([{ label: 'Terms', href: 'https://example.test/terms' }])

    render(<PageShell legalLinks={legalLinksFor('en')}>content</PageShell>)
    expect(screen.getByRole('link', { name: 'Terms' })).toHaveAttribute(
      'href',
      'https://example.test/terms',
    )
  })
})

describe('AdminPage with board_smtp disabled', () => {
  it('loads branding + moderation only and renders no SMTP sheet', async () => {
    setFeatures({ board_smtp: false, legal_links: null })
    const responses = [ADMIN_BOOTSTRAP, BRANDING_RESPONSE, MODERATION_RESPONSE]
    const urls: string[] = []
    let callIndex = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      urls.push(typeof input === 'string' ? input : (input as Request).url)
      const body = responses[callIndex] ?? { ok: true }
      callIndex++
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    render(
      <MemoryRouter initialEntries={['/admin/boards/demo']}>
        <Routes>
          <Route path="/admin/boards/:boardSlug" element={<AdminPage />} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => expect(screen.getByText('Branding')).toBeInTheDocument())
    expect(screen.getByText('Moderation')).toBeInTheDocument()
    expect(screen.queryByLabelText(/SMTP-Host/i)).not.toBeInTheDocument()
    expect(urls.some((u) => u.includes('/smtp'))).toBe(false)
  })
})
