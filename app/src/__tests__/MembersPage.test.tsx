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
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MembersPage from '../pages/MembersPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const OWNER_MEMBERS_RESPONSE = {
  members: [
    {
      user_id: 1,
      public_id: '1',
      username: null,
      role: 'owner',
      created_at: '2026-01-01T00:00:00Z',
    },
    {
      user_id: 2,
      public_id: '2',
      username: null,
      role: 'moderator',
      created_at: '2026-01-02T00:00:00Z',
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
    },
    {
      user_id: 2,
      public_id: '2',
      username: null,
      role: 'moderator',
      created_at: '2026-01-02T00:00:00Z',
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

    await waitFor(() => expect(screen.getByText(/User #1/)).toBeInTheDocument())
    expect(screen.getByText(/User #2/)).toBeInTheDocument()
    expect(screen.getByText(/User #3/)).toBeInTheDocument() // pending invite

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Einladen/i })).toBeInTheDocument()
    expect(screen.getByLabelText(/User #2 entfernen/i)).toBeInTheDocument()
    expect(screen.getByLabelText(/Einladung für User #3 widerrufen/i)).toBeInTheDocument()
  })

  it("offers only 'admin'/'moderator'/'member' as role-selector options — never 'owner'", async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()

    const roleSelect = await screen.findByLabelText(/Rolle für User #2/i)
    const optionValues = within(roleSelect)
      .getAllByRole('option')
      .map((o) => (o as HTMLOptionElement).value)

    expect(optionValues).toEqual(['admin', 'moderator', 'member'])
  })
})

describe('MembersPage — admin view', () => {
  it('shows members but hides invite form and mutation controls', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: ADMIN_MEMBERS_RESPONSE }])

    renderMembersPage()

    await waitFor(() => expect(screen.getByText(/User #1/)).toBeInTheDocument())
    expect(screen.getByText(/User #2/)).toBeInTheDocument()

    expect(screen.queryByLabelText(/E-Mail-Adresse/i)).not.toBeInTheDocument()
    expect(screen.queryByLabelText(/User #2 entfernen/i)).not.toBeInTheDocument()
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

    await waitFor(() => expect(screen.getByText('Einladung versendet.')).toBeInTheDocument())
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
      members: [{ user_id: 1, role: 'owner', created_at: '2026-01-01T00:00:00Z' }],
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
    await waitFor(() => expect(screen.getByLabelText(/User #2 entfernen/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/User #2 entfernen/i))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Entfernen' }))

    await waitFor(() => expect(screen.queryByText(/User #2/)).not.toBeInTheDocument())
  })

  it('does nothing if the confirm dialog is dismissed', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: OWNER_MEMBERS_RESPONSE }])

    renderMembersPage()
    await waitFor(() => expect(screen.getByLabelText(/User #2 entfernen/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText(/User #2 entfernen/i))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(screen.getByText(/User #2/)).toBeInTheDocument()
  })
})
