/**
 * RTL tests for PublicProfilePage (profile-visibility feature).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  - visible profile → avatar + only the social links that are set, as
 *    outbound links built from the bare identifiers
 *  - anonymous profile → "Voter" placeholder, no social links, hint text
 *  - the owner badge shows for an anonymous owner (independent of visibility)
 *  - 404 from the API → not-found state
 *  - a non-numeric :userId → not-found state without a profile request
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicProfilePage from '../pages/PublicProfilePage'

const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

function mockFetch(profile: object, profileStatus = 200) {
  const fetchCalls: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    fetchCalls.push(url)
    const body = url.includes('/api/bootstrap') ? ANON_BOOTSTRAP : profile
    const status = url.includes('/api/bootstrap') ? 200 : profileStatus
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return fetchCalls
}

function renderPage(userId = '7') {
  return render(
    <MemoryRouter initialEntries={[`/members/${userId}/profile`]}>
      <Routes>
        <Route path="/members/:userId/profile" element={<PublicProfilePage />} />
        <Route path="/" element={<div data-testid="home" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('PublicProfilePage', () => {
  it('renders a visible profile with avatar and only the social links that are set', async () => {
    mockFetch({
      id: 7,
      visible: true,
      is_admin: false,
      is_operator: false,
      role: null,
      avatar_url: '/avatar/7.jpg',
      website_domain: 'example.com',
      x_handle: 'handle',
      youtube_handle: null,
      github_username: 'octocat',
    })

    renderPage()

    await waitFor(() => expect(document.querySelector('img[src="/avatar/7.jpg"]')).not.toBeNull())
    expect(screen.getByText('Voter')).toBeInTheDocument()

    const website = screen.getByRole('link', { name: 'example.com' })
    expect(website).toHaveAttribute('href', 'https://example.com')
    expect(website).toHaveAttribute('rel', expect.stringContaining('noopener'))
    expect(screen.getByRole('link', { name: '@handle' })).toHaveAttribute(
      'href',
      'https://x.com/handle',
    )
    expect(screen.getByRole('link', { name: 'octocat' })).toHaveAttribute(
      'href',
      'https://github.com/octocat',
    )
    // YouTube was null → not rendered at all.
    expect(screen.queryByRole('link', { name: /youtube/i })).not.toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(3)
  })

  it('renders the "Voter" placeholder for an anonymous profile, no social links', async () => {
    mockFetch({ id: 7, visible: false, is_admin: false, is_operator: false, role: null })

    renderPage()

    await waitFor(() => expect(screen.getByText('Voter')).toBeInTheDocument())
    expect(screen.getByText(/kein öffentliches Profil/)).toBeInTheDocument()
    expect(document.querySelector('img')).toBeNull()
    expect(screen.queryByRole('list', { name: 'Social-Links' })).not.toBeInTheDocument()
  })

  it('renders the contribution stats for a visible profile', async () => {
    mockFetch({
      id: 7,
      visible: true,
      is_admin: false,
      is_operator: false,
      role: null,
      avatar_url: null,
      website_domain: null,
      x_handle: null,
      youtube_handle: null,
      github_username: null,
      ideas_submitted: 4,
      ideas_shipped: 2,
      votes_cast: 11,
    })

    renderPage()

    await waitFor(() => expect(screen.getByText('4')).toBeInTheDocument())
    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.getByText('11')).toBeInTheDocument()
    expect(screen.getByText('Eingereichte Ideen')).toBeInTheDocument()
    expect(screen.getByText('Umgesetzte Ideen')).toBeInTheDocument()
    expect(screen.getByText('Abgegebene Stimmen')).toBeInTheDocument()
  })

  it('does not render contribution stats for an anonymous profile', async () => {
    mockFetch({ id: 7, visible: false, is_admin: false, is_operator: false, role: null })

    renderPage()

    await waitFor(() => expect(screen.getByText('Voter')).toBeInTheDocument())
    expect(screen.queryByText('Eingereichte Ideen')).not.toBeInTheDocument()
  })

  it('shows the owner badge for an anonymous owner', async () => {
    mockFetch({ id: 7, visible: false, is_admin: true, is_operator: false, role: 'owner' })

    renderPage()

    await waitFor(() => expect(screen.getByText('Owner')).toBeInTheDocument())
    expect(screen.getByText('Voter')).toBeInTheDocument()
  })

  it('shows the not-found state on a 404', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Not found.' } }, 404)

    renderPage()

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(
        'Dieses Mitglied ist gerade nicht hier',
      ),
    )
    expect(screen.getByTestId('not-found-message')).toHaveTextContent(
      'Vielleicht ist das Profil nicht mehr sichtbar',
    )
    expect(screen.getByTestId('not-found-home')).toHaveAttribute('href', '/')
  })

  it('treats a non-numeric id as not found without requesting a profile', async () => {
    const fetchCalls = mockFetch({})

    renderPage('abc')

    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    expect(fetchCalls.some((u) => u.includes('/members/'))).toBe(false)
  })

  it('renders a 404 and a non-numeric id identically (no existence hint)', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Not found.' } }, 404)
    const byApi = renderPage('7')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    const apiMarkup = byApi.container.innerHTML
    byApi.unmount()

    mockFetch({})
    const byRoute = renderPage('abc')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())

    expect(byRoute.container.innerHTML).toBe(apiMarkup)
  })
})

describe('PublicProfilePage — board look (branding)', () => {
  const anonProfile = { id: 7, visible: false, is_admin: false, is_operator: false, role: null }

  function renderBoardProfile() {
    return render(
      <MemoryRouter initialEntries={['/members/7/profile?board=demo']}>
        <Routes>
          <Route path="/members/:userId/profile" element={<PublicProfilePage />} />
          <Route path="/" element={<div data-testid="home" />} />
        </Routes>
      </MemoryRouter>,
    )
  }

  it('applies the board look when the profile is opened in a board context', async () => {
    const fetchCalls = mockFetch({
      ...anonProfile,
      board: {
        slug: 'demo',
        name: 'Demo Board',
        primary_color: '#00AA55',
        secondary_color: '#112233',
        logo_url: 'https://example.com/profile-logo.png',
      },
    })

    const { container } = renderBoardProfile()
    await waitFor(() => expect(container.querySelector('[style*="--vp-primary"]')).not.toBeNull())

    const branded = container.querySelector('[style*="--vp-primary"]') as HTMLElement
    expect(branded.style.getPropertyValue('--vp-primary')).toBe('#00AA55')
    expect(
      container.querySelectorAll('img[src="https://example.com/profile-logo.png"]').length,
    ).toBeGreaterThan(0)
    expect(fetchCalls.some((u) => u.includes('/members/7/profile?board=demo'))).toBe(true)
  })

  it('applies no board look when the plan withholds it (Free)', async () => {
    mockFetch({
      ...anonProfile,
      board: { slug: 'demo', name: 'Demo Board', primary_color: null, logo_url: null },
    })

    const { container } = renderBoardProfile()
    await screen.findByText('Voter')

    expect(container.querySelector('[style*="--vp-primary"]')).toBeNull()
    expect(container.querySelectorAll('img').length).toBe(0)
  })
})
