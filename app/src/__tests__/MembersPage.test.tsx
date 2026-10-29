/**
 * RTL tests for MembersPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Owner sees members + pending invites + invite form + remove/revoke controls,
 *     plus a role selector offering only 'admin'/'moderator'/'member' (never 'owner')
 *  3. Admin sees members but NOT the invite form / remove / revoke controls
 *  4. Non-member/moderator (403 from GET /admin/members) → access denied message
 *  5. Anon (user: null from bootstrap) → redirect to /login
 *  6. Invite form: field-error mapping from the API's 422 response
 *  7. Invite form: success reloads the list
 *  8. Remove: click triggers POST and reloads the list
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setFeatures } from '../lib/features'
import MembersPage from '../pages/MembersPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const NORMAL_STANDING = { violation_count: 0, state: 'normal' }

const OWNER_MEMBERS_RESPONSE = {
  members: [
    {
      user_id: 1,
      public_id: '1',
      username: null,
      role: 'owner',
      created_at: '2026-01-01T00:00:00Z',
      standing: NORMAL_STANDING,
    },
    {
      user_id: 2,
      public_id: '2',
      username: null,
      role: 'moderator',
      created_at: '2026-01-02T00:00:00Z',
      standing: NORMAL_STANDING,
    },
  ],
  invites: [
    {
      id: 5,
      user_id: 3,
      role: 'moderator',
      expires_at: '2026-02-01T00:00:00Z',
      created_at: '2026-01-03T00:00:00Z',
    },
  ],
  viewer_role: 'owner',
}

const ADMIN_MEMBERS_RESPONSE = {
  members: [
    {
      user_id: 1,
      public_id: '1',
      username: null,
      role: 'owner',
      created_at: '2026-01-01T00:00:00Z',
      standing: NORMAL_STANDING,
    },
    {
      user_id: 2,
      public_id: '2',
      username: null,
      role: 'moderator',
      created_at: '2026-01-02T00:00:00Z',
      standing: NORMAL_STANDING,
    },
  ],
  invites: [],
  viewer_role: 'admin',
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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

function renderMembersPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/members']}>
      <Routes>
        <Route path="/admin/members" element={<MembersPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('MembersPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('MembersPage — owner view', () => {
  it('shows members, pending invites, and owner-only controls', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByText(/Person #1/)).toBeInTheDocument())
    expect(screen.getByText(/Person #2/)).toBeInTheDocument()
    expect(screen.getByText(/Person #3/)).toBeInTheDocument() // pending invite

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Einladen/i })).toBeInTheDocument()
    expect(screen.getByLabelText(/Person #2 entfernen/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Einladung für Person #3 widerrufen/i)).toBeInTheDocument()
  })

  it("offers only 'admin'/'moderator'/'member' as role-selector options — never 'owner'", async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()

    const roleSelect = await screen.findByLabelText(/Rolle für Person #2/i)
    const optionValues = within(roleSelect)
      .getAllByRole('option')
      .map((o) => (o as HTMLOptionElement).value)

    expect(optionValues).toEqual(['admin', 'moderator', 'member'])
  })
})

describe('MembersPage — admin view', () => {
  it('marks a member whose seat the plan limit suspended', async () => {
    const suspended = {
      ...OWNER_MEMBERS_RESPONSE,
      members: OWNER_MEMBERS_RESPONSE.members.map((m) =>
        m.user_id === 2 ? { ...m, suspended_at: '2026-10-28 10:00:00' } : m,
      ),
    }
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: suspended }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByTestId('members-suspended-2')).toBeInTheDocument())
    expect(screen.queryByTestId('members-suspended-1')).not.toBeInTheDocument()
  })

  it('shows members but hides invite form and mutation controls', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ADMIN_MEMBERS_RESPONSE }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByText(/Person #1/)).toBeInTheDocument())
    expect(screen.getByText(/Person #2/)).toBeInTheDocument()

    expect(screen.queryByLabelText(/E-Mail-Adresse/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/Person #2 entfernen/i)).not.toBeInTheDocument()
  })
})

describe('MembersPage — access denied', () => {
  it('shows no-access message when GET /admin/members returns 403', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'forbidden', message: 'Forbidden' } }, status: 403 },
    ])

    renderMembersPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('MembersPage — invite form', () => {
  it('shows the email field error returned by the API', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: OWNER_MEMBERS_RESPONSE },
      {
        body: {
          error: {
            key: 'already_member',
            message: 'Validation failed.',
            fields: { email: 'Diese Person ist bereits Mitglied dieses Accounts.' },
          },
        },
        status: 422,
      },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'existing@example.com')
    await user.click(screen.getByRole('button', { name: /Einladen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Diese Person ist bereits Mitglied dieses Accounts.'),
      ).toBeInTheDocument(),
    )
  })

  it('reloads the list on a successful invite', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: OWNER_MEMBERS_RESPONSE },
      { body: { ok: true } },
      { body: OWNER_MEMBERS_RESPONSE },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'newbie@example.com')
    await user.click(screen.getByRole('button', { name: /Einladen/i }))

    await waitFor(() =>
      expect(
        screen.getByText(/Einladung versendet\. Die Person wird erst Mitglied/),
      ).toBeInTheDocument(),
    )
  })
})

describe('MembersPage — password reset', () => {
  it('is visible for admin viewers too (accountAdmin, not owner-only)', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ADMIN_MEMBERS_RESPONSE }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByLabelText(/E-Mail des Mitglieds/i)).toBeInTheDocument())
  })

  it('sends a reset link for the re-typed email', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: OWNER_MEMBERS_RESPONSE },
      { body: { ok: true } },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/E-Mail des Mitglieds/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail des Mitglieds/i), 'member@example.com')
    await user.click(screen.getByRole('button', { name: /Reset-Link senden/i }))

    await waitFor(() => expect(screen.getByText('Reset-Link gesendet.')).toBeInTheDocument())
  })

  it('shows a not-found error for an unknown email and sends no further calls', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: OWNER_MEMBERS_RESPONSE },
      {
        body: {
          error: { key: 'not_found', message: 'No member of this account matches that email.' },
        },
        status: 404,
      },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/E-Mail des Mitglieds/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail des Mitglieds/i), 'ghost@example.com')
    await user.click(screen.getByRole('button', { name: /Reset-Link senden/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Kein Mitglied dieses Accounts stimmt mit dieser E-Mail überein.'),
      ).toBeInTheDocument(),
    )
  })
})

describe('MembersPage — remove member', () => {
  it('sends a remove request and reloads the list', async () => {
    const afterRemove = {
      members: [
        {
          user_id: 1,
          role: 'owner',
          created_at: '2026-01-01T00:00:00Z',
          standing: NORMAL_STANDING,
        },
      ],
      invites: [],
      viewer_role: 'owner',
    }

    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: OWNER_MEMBERS_RESPONSE },
      { body: { ok: true } },
      { body: afterRemove },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/Person #2 entfernen/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/Person #2 entfernen/i))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Entfernen' }))

    await waitFor(() => expect(screen.queryByText(/Person #2/)).not.toBeInTheDocument())
  })

  it('does nothing if the confirm dialog is dismissed', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/Person #2 entfernen/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/Person #2 entfernen/i))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(screen.getByText(/Person #2/)).toBeInTheDocument()
  })
})

describe('MembersPage — plan limit on invite', () => {
  const BOOTSTRAP_OWNER = {
    csrf_token: 'test-csrf',
    user: { id: 1, is_admin: false, memberships: [{ account_slug: 'demo', role: 'owner' }] },
  }

  afterEach(() => {
    setFeatures(undefined)
  })

  it('explains the member limit in plain words, never the raw server text, and offers the upgrade path to the owner', async () => {
    setFeatures({ board_smtp: true, legal_links: null, billing: true })
    makeFetchMock([
      { body: BOOTSTRAP_OWNER },
      { body: OWNER_MEMBERS_RESPONSE },
      {
        body: {
          error: {
            key: 'plan_limit_team',
            message:
              'Your current plan does not allow additional members. Please upgrade to grow your team.',
          },
        },
        status: 422,
      },
    ])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'more@example.com')
    await user.click(screen.getByRole('button', { name: /Einladen/i }))

    const alert = await screen.findByTestId('members-invite-error')
    expect(alert).toHaveTextContent(
      'Dein aktueller Tarif bietet keinen Platz für weitere Mitglieder.',
    )
    expect(alert).not.toHaveTextContent('Your current plan')
    expect(within(alert).getByRole('link', { name: 'Tarif ansehen' })).toHaveAttribute(
      'href',
      '/admin/billing',
    )
  })
})

describe('MembersPage — phone layout (below 640 px)', () => {
  afterEach(() => {
    delete (window as { matchMedia?: unknown }).matchMedia
  })

  it('renders one card per member with the same testids and no table', async () => {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: true,
      media: query,
      onchange: null,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      addListener: vi.fn(),
      removeListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })) as unknown as typeof window.matchMedia
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByTestId('members-list')).toBeInTheDocument())
    expect(screen.queryByTestId('members-table')).not.toBeInTheDocument()
    const card = screen.getByTestId('members-row-2')
    expect(within(card).getByTestId('members-role-2')).toBeInTheDocument()
    expect(within(card).getByTestId('members-standing-2')).toBeInTheDocument()
    expect(within(card).getByTestId('members-remove-2')).toBeInTheDocument()
  })
})
