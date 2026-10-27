/**
 * RTL tests for RootRedirectPage — bare `/` in cloud mode.
 *
 * Covers the three destinations a visitor can land on: /login (anon), their
 * own /{slug}/admin/boards (has a membership), and — the pure-voter fix —
 * /profile instead of the forced /signup/account wizard when the visitor is
 * signed in but has no account membership at all (e.g. someone who only
 * ever voted on someone else's board, never created their own account).
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import RootRedirectPage from '../pages/RootRedirectPage'

function jsonResponse(body: object, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderRootRedirectPage() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="/" element={<RootRedirectPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
        <Route path="/profile" element={<div data-testid="profile-page" />} />
        <Route path="/signup/account" element={<div data-testid="signup-account-page" />} />
        <Route
          path="/:accountSlug/admin/boards"
          element={<div data-testid="admin-boards-page" />}
        />
      </Routes>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('RootRedirectPage', () => {
  it('sends an anonymous visitor to /login', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ user: null }))
    renderRootRedirectPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })

  it('sends a member straight to their own account boards list', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ user: { id: 1, memberships: [{ account_slug: 'acme', role: 'owner' }] } }),
    )
    renderRootRedirectPage()

    await waitFor(() => expect(screen.getByTestId('admin-boards-page')).toBeInTheDocument())
  })

  it('sends a signed-in voter with no account membership to their own profile, not the forced account-setup wizard', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      jsonResponse({ user: { id: 7, memberships: [] } }),
    )
    renderRootRedirectPage()

    await waitFor(() => expect(screen.getByTestId('profile-page')).toBeInTheDocument())
    expect(screen.queryByTestId('signup-account-page')).not.toBeInTheDocument()
  })
})
