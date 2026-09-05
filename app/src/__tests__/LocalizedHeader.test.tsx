/**
 * RTL tests for LocalizedHeader — the app bar's information architecture:
 * the nav row only ever carries "where am I" links (Board / Roadmap, plus the
 * current board's Settings for moderators), while everything about the
 * visitor (profile, admin area, operator panel, logout) is grouped in one
 * account menu. Covers both routing modes via accountContext.
 */

import { render as rtlRender, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LocalizedHeader, useAccountAdminNavLinks } from '../components/LocalizedHeader'
import { accountPath, setAccountSlug } from '../lib/accountContext'
import type { User } from '../lib/api'
import { setEdition } from '../lib/edition'

// LocalizedHeader now renders its nav/account-menu links via react-router's
// Link (see LocalizedHeader.tsx's RouterLink adapter) instead of plain <a>,
// so every render needs a Router context — wrap once here instead of at
// each of this file's 21 call sites.
function render(ui: ReactElement) {
  return rtlRender(<MemoryRouter>{ui}</MemoryRouter>)
}

function mockNotifications(notifications: Array<{ is_read: boolean }>) {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify({ notifications }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

const VOTER: User = {
  id: 7,
  is_admin: false,
  is_operator: false,
  avatar_url: null,
  memberships: [],
}
const OWNER: User = {
  id: 1,
  is_admin: false,
  is_operator: false,
  avatar_url: null,
  memberships: [{ account_slug: 'acme', role: 'owner' }],
}
const OPERATOR: User = { ...OWNER, is_operator: true }
/** A pure operator/support persona with no account membership of their own (LocalizedHeader.test.tsx notification-bell fallback). */
const PURE_OPERATOR: User = { ...VOTER, is_operator: true }
const PURE_SUPPORT: User = { ...VOTER, is_support: true }

function navLinkHrefs() {
  const nav = screen.getByRole('navigation', { name: 'Hauptnavigation' })
  return within(nav)
    .getAllByRole('link')
    .map((l) => [l.textContent, l.getAttribute('href')])
}

async function openAccountMenu() {
  // Not selected by name: the trigger's label switches to a voter-preview
  // indicator while that mode is active (see LocalizedHeader's AccountMenu).
  await userEvent.click(screen.getByRole('button', { name: /Profil|Voter-Vorschau/ }))
  return within(screen.getByRole('menu'))
    .getAllByRole('menuitem')
    .map((el) => el.textContent)
}

afterEach(() => {
  setAccountSlug(null)
  setEdition('self-host')
  vi.restoreAllMocks()
})

describe('LocalizedHeader — board-scoped pages', () => {
  it('shows a login button and no account menu for anonymous visitors', () => {
    render(<LocalizedHeader basePath="/demo" boardSlug="demo" />)

    expect(screen.getByRole('button', { name: 'Anmelden' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Profil' })).not.toBeInTheDocument()
    expect(navLinkHrefs()).toEqual([
      ['Board', '/demo'],
      ['Roadmap', '/demo/roadmap'],
    ])
  })

  it('groups profile and logout in the account menu for a signed-in voter, without admin entries', async () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={VOTER}
        onLogoutClick={() => {}}
      />,
    )

    // "Boards" (admin) never shares the nav row with "Board" (this board).
    expect(navLinkHrefs()).toEqual([
      ['Board', '/demo'],
      ['Roadmap', '/demo/roadmap'],
    ])
    expect(await openAccountMenu()).toEqual(['Profil', 'Abmelden'])
  })

  it('never shows the login button for an authenticated visitor, even with a compact/pending account slot', () => {
    // Regression: AdminShell passes account=null (via compactAccountMenu /
    // authPending) to intentionally render an empty slot instead of the
    // account menu — the session menu lives in the sidebar footer there
    // instead. Header.tsx's `account ?? <LoginButton/>` previously treated
    // that explicit `null` the same as "not provided" (`??` doesn't
    // distinguish null from undefined), so it fell back to showing "Anmelden"
    // even while the visitor was fully authenticated (sidebar showed them
    // logged in at the same time — the bug reported on staging 2026-09-04).
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={VOTER}
        onLogoutClick={() => {}}
        compactAccountMenu
      />,
    )

    expect(screen.queryByRole('button', { name: 'Anmelden' })).not.toBeInTheDocument()
  })

  it('shows the real avatar image in the account menu trigger when one is set', () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={{ ...VOTER, avatar_url: '/avatar/abc123.jpg' }}
        onLogoutClick={() => {}}
      />,
    )

    const trigger = screen.getByRole('button', { name: 'Profil' })
    expect(trigger.querySelector('img')).toHaveAttribute('src', '/avatar/abc123.jpg')
  })

  it('falls back to the placeholder glyph in the account menu trigger when no avatar is set', () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={VOTER}
        onLogoutClick={() => {}}
      />,
    )

    const trigger = screen.getByRole('button', { name: 'Profil' })
    expect(trigger.querySelector('img')).not.toBeInTheDocument()
  })

  it('adds the board Settings link and the admin area for an owner (self-host paths)', async () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OWNER}
        onLogoutClick={() => {}}
      />,
    )

    expect(navLinkHrefs()).toEqual([
      ['Board', '/demo'],
      ['Roadmap', '/demo/roadmap'],
      ['Einstellungen', '/admin/boards/demo'],
    ])
    expect(await openAccountMenu()).toEqual(['Profil', 'Verwaltung', 'Abmelden'])
    expect(screen.getByRole('menuitem', { name: 'Verwaltung' })).toHaveAttribute(
      'href',
      '/admin/boards',
    )
  })

  it('prefixes every account-scoped target with the account slug in cloud mode', async () => {
    setEdition('cloud')
    setAccountSlug('acme')
    render(
      <LocalizedHeader
        basePath="/acme/demo"
        boardSlug="demo"
        isAuthenticated
        user={OPERATOR}
        onLogoutClick={() => {}}
      />,
    )

    expect(navLinkHrefs()).toEqual([
      ['Board', '/acme/demo'],
      ['Roadmap', '/acme/demo/roadmap'],
      ['Einstellungen', '/acme/admin/boards/demo'],
    ])
    expect(await openAccountMenu()).toEqual([
      'Profil',
      'Verwaltung',
      'Operator-Panel',
      'Plattform-Verwaltung',
      'Abmelden',
    ])
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveAttribute(
      'href',
      '/acme/profile',
    )
    expect(screen.getByRole('menuitem', { name: 'Verwaltung' })).toHaveAttribute(
      'href',
      '/acme/admin/boards',
    )
    expect(screen.getByRole('menuitem', { name: 'Operator-Panel' })).toHaveAttribute(
      'href',
      '/operator',
    )
    expect(screen.getByRole('menuitem', { name: 'Plattform-Verwaltung' })).toHaveAttribute(
      'href',
      '/admin/overview',
    )
  })

  it('falls back to the first membership on a cloud page without an account segment', async () => {
    setEdition('cloud')
    setAccountSlug(null) // e.g. /operator
    render(<LocalizedHeader navLinks={[]} isAuthenticated user={OWNER} onLogoutClick={() => {}} />)

    expect(await openAccountMenu()).toEqual(['Profil', 'Verwaltung', 'Abmelden'])
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveAttribute(
      'href',
      '/acme/profile',
    )
    expect(screen.getByRole('menuitem', { name: 'Verwaltung' })).toHaveAttribute(
      'href',
      '/acme/admin/boards',
    )
  })

  it('still links to the global /profile for a member-less voter on a cloud page without an account segment', async () => {
    setEdition('cloud')
    setAccountSlug(null) // e.g. the pure-voter landing after RootRedirectPage
    render(<LocalizedHeader navLinks={[]} isAuthenticated user={VOTER} onLogoutClick={() => {}} />)

    // No account to manage, but the profile link must not disappear — a
    // voter with zero memberships still has their own global profile.
    expect(await openAccountMenu()).toEqual(['Profil', 'Abmelden'])
    expect(screen.getByRole('menuitem', { name: 'Profil' })).toHaveAttribute('href', '/profile')
  })
})

describe('LocalizedHeader — voterPreview (owner/moderator "view as voter")', () => {
  it('hides the board Settings link and every admin/operator menu entry when voterPreview is on', async () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OPERATOR}
        onLogoutClick={() => {}}
        voterPreview
      />,
    )

    // Same nav a real voter sees — no "Settings" link.
    expect(navLinkHrefs()).toEqual([
      ['Board', '/demo'],
      ['Roadmap', '/demo/roadmap'],
    ])
    // Profile/logout survive (a real voter who is signed in sees those too);
    // admin area, operator panel and platform-admin entries do not.
    expect(await openAccountMenu()).toEqual(['Profil', 'Abmelden'])
  })

  it('is false by default — an omitted prop still shows the admin affordances for an owner', async () => {
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OWNER}
        onLogoutClick={() => {}}
      />,
    )

    expect(navLinkHrefs()).toEqual([
      ['Board', '/demo'],
      ['Roadmap', '/demo/roadmap'],
      ['Einstellungen', '/admin/boards/demo'],
    ])
    expect(await openAccountMenu()).toEqual(['Profil', 'Verwaltung', 'Abmelden'])
  })
})

describe('LocalizedHeader — edition wordmark', () => {
  it('shows the COMMUNITY suffix on self-host', () => {
    setEdition('self-host')
    render(<LocalizedHeader basePath="/demo" boardSlug="demo" />)
    expect(screen.getByText('COMMUNITY')).toBeInTheDocument()
    expect(screen.queryByText('CLOUD')).not.toBeInTheDocument()
  })

  it('shows the CLOUD suffix in cloud mode', () => {
    setEdition('cloud')
    render(<LocalizedHeader basePath="/demo" boardSlug="demo" />)
    expect(screen.getByText('CLOUD')).toBeInTheDocument()
    expect(screen.queryByText('COMMUNITY')).not.toBeInTheDocument()
  })
})

describe('LocalizedHeader — notification bell', () => {
  it('shows no bell for anonymous visitors', () => {
    render(<LocalizedHeader basePath="/demo" boardSlug="demo" />)
    expect(screen.queryByRole('link', { name: /Postfach/ })).not.toBeInTheDocument()
  })

  it('shows the bell without a dot when everything is read', async () => {
    mockNotifications([{ is_read: true }])
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OWNER}
        onLogoutClick={() => {}}
      />,
    )

    const bell = await screen.findByRole('link', { name: 'Postfach' })
    expect(bell).toHaveAttribute('href', '/admin/inbox')
  })

  it('shows a dot on the bell when a notification is unread', async () => {
    mockNotifications([{ is_read: false }])
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OWNER}
        onLogoutClick={() => {}}
      />,
    )

    await waitFor(() =>
      expect(
        screen.getByRole('link', { name: 'Postfach (ungelesene Nachrichten)' }),
      ).toBeInTheDocument(),
    )
  })

  it('polls for unread notifications and lights up the dot once a new one arrives', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let callCount = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      callCount++
      const notifications = callCount === 1 ? [{ is_read: true }] : [{ is_read: false }]
      return new Response(JSON.stringify({ notifications }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={OWNER}
        onLogoutClick={() => {}}
      />,
    )

    await waitFor(() => expect(screen.getByRole('link', { name: 'Postfach' })).toBeInTheDocument())

    await vi.advanceTimersByTimeAsync(20_000)

    await waitFor(() =>
      expect(
        screen.getByRole('link', { name: 'Postfach (ungelesene Nachrichten)' }),
      ).toBeInTheDocument(),
    )
    expect(callCount).toBeGreaterThanOrEqual(2)
    vi.useRealTimers()
  })

  it('falls back to the operator panel for a pure operator with no account memberships', async () => {
    setEdition('cloud')
    setAccountSlug(null)
    mockNotifications([{ is_read: false }])
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={PURE_OPERATOR}
        onLogoutClick={() => {}}
      />,
    )

    const bell = await screen.findByRole('link', { name: 'Postfach (ungelesene Nachrichten)' })
    expect(bell).toHaveAttribute('href', '/operator/support')
  })

  it('falls back to the operator panel for a pure support agent with no account memberships', async () => {
    setEdition('cloud')
    setAccountSlug(null)
    mockNotifications([{ is_read: true }])
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={PURE_SUPPORT}
        onLogoutClick={() => {}}
      />,
    )

    const bell = await screen.findByRole('link', { name: 'Postfach' })
    expect(bell).toHaveAttribute('href', '/operator/support')
  })

  it('shows no bell for a voter with no memberships in cloud mode', async () => {
    setEdition('cloud')
    setAccountSlug(null)
    mockNotifications([])
    render(
      <LocalizedHeader
        basePath="/demo"
        boardSlug="demo"
        isAuthenticated
        user={VOTER}
        onLogoutClick={() => {}}
      />,
    )

    expect(screen.queryByRole('link', { name: /Postfach/ })).not.toBeInTheDocument()
  })
})

describe('useAccountAdminNavLinks', () => {
  function Probe() {
    const links = useAccountAdminNavLinks()
    return <LocalizedHeader navLinks={links} navAriaLabel="Verwaltung" />
  }

  it('is the admin section nav only — no personal entries', () => {
    setAccountSlug('acme')
    render(<Probe />)
    const nav = screen.getByRole('navigation', { name: 'Verwaltung' })
    expect(
      within(nav)
        .getAllByRole('link')
        .map((l) => [l.textContent, l.getAttribute('href')]),
    ).toEqual([
      ['Boards', accountPath('/admin/boards')],
      ['Mitglieder', '/acme/admin/members'],
      ['Support', '/acme/admin/support'],
      ['Account', '/acme/admin/account'],
    ])
  })
})
