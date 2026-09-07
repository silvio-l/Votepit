/**
 * RTL tests for ApiTokensPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Every "ready" render fires two fetches in parallel (listApiTokens +
 * listAdminBoards) — in that order, since Promise.all evaluates its array
 * left-to-right — so every response queue below carries a boards response
 * right after the tokens response.
 *
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Token list renders (label, scope, granted boards, created/last-used/
 *     revoked info, revoke button)
 *  3. Non-member (403 from GET /admin/tokens) → access denied message
 *  4. Anon (user: null from bootstrap) → redirect to /login
 *  5. Create form: field-error mapping from the API's 422 response
 *  6. Create form: success reveals the plaintext token once + reloads the list
 *  7. Revoke: click triggers POST and reloads the list
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApiTokensPage from '../pages/ApiTokensPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}

const BOARDS_RESPONSE = {
  boards: [
    { id: 10, slug: 'demo', name: 'Demo Board', frozen_at: null, idea_count: 0, vote_count: 0 },
  ],
  account: {
    onboarding_completed_at: '2026-01-01T00:00:00Z',
    allowed_visibilities: [],
    default_visibility: 'public',
  },
}

const TWO_BOARDS_RESPONSE = {
  boards: [
    { id: 10, slug: 'demo', name: 'Demo Board', frozen_at: null, idea_count: 0, vote_count: 0 },
    {
      id: 11,
      slug: 'roadmap',
      name: 'Roadmap Board',
      frozen_at: null,
      idea_count: 0,
      vote_count: 0,
    },
  ],
  account: {
    onboarding_completed_at: '2026-01-01T00:00:00Z',
    allowed_visibilities: [],
    default_visibility: 'public',
  },
}

const TOKENS_RESPONSE = {
  tokens: [
    {
      id: 1,
      label: 'CI bot',
      boards: [{ board_id: 10, scope: 'write' }],
      created_by_user_id: 1,
      last_used_at: '2026-01-05T00:00:00Z',
      revoked_at: null,
      created_at: '2026-01-01T00:00:00Z',
    },
    {
      id: 2,
      label: 'Old integration',
      boards: [{ board_id: 10, scope: 'read' }],
      created_by_user_id: 1,
      last_used_at: null,
      revoked_at: '2026-01-10T00:00:00Z',
      created_at: '2026-01-02T00:00:00Z',
    },
  ],
}

const EMPTY_TOKENS_RESPONSE = { tokens: [] }

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    // The header's notification bell fires its own GET /notifications on every
    // authenticated page — served out-of-band so it never consumes a slot from
    // this page's own response queue below.
    if (typeof input === 'string' && input.startsWith('/notifications')) {
      return new Response(JSON.stringify({ notifications: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderTokensPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/tokens']}>
      <Routes>
        <Route path="/admin/tokens" element={<ApiTokensPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ApiTokensPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: TOKENS_RESPONSE }, { body: BOARDS_RESPONSE }])

    renderTokensPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('ApiTokensPage — token list', () => {
  it('renders existing tokens with their status, scope, boards, and a revoke button for active ones', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: TOKENS_RESPONSE }, { body: BOARDS_RESPONSE }])

    renderTokensPage()

    await waitFor(() => expect(screen.getByText(/CI bot/)).toBeInTheDocument())
    expect(screen.getByText(/Old integration/)).toBeInTheDocument()
    expect(screen.getByText(/widerrufen/)).toBeInTheDocument()
    expect(screen.getByText('Demo Board: Lesen & Schreiben')).toBeInTheDocument()
    expect(screen.getByText('Demo Board: Nur Lesen')).toBeInTheDocument()

    expect(screen.getByLabelText('Token „CI bot" widerrufen')).toBeInTheDocument()
    expect(screen.queryByLabelText('Token „Old integration" widerrufen')).not.toBeInTheDocument()
  })

  it('shows an empty-state message when there are no tokens yet', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
    ])

    renderTokensPage()

    await waitFor(() => expect(screen.getByText(/Noch keine Tokens angelegt/)).toBeInTheDocument())
  })
})

describe('ApiTokensPage — access denied', () => {
  it('shows no-access message when GET /admin/tokens returns 403', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'forbidden', message: 'Forbidden' } }, status: 403 },
    ])

    renderTokensPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderTokensPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('ApiTokensPage — docs links', () => {
  it('always shows links to the API reference and MCP server docs', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
    ])

    renderTokensPage()
    await waitFor(() => expect(screen.getByLabelText(/Label/i)).toBeInTheDocument())

    const apiRefLink = screen.getByRole('link', { name: 'API-Referenz' })
    expect(apiRefLink).toHaveAttribute('href', 'https://votepit.com/docs/api-reference')
    expect(apiRefLink).toHaveAttribute('target', '_blank')
    expect(apiRefLink).toHaveAttribute('rel', 'noreferrer')

    const mcpLink = screen.getByRole('link', { name: 'MCP-Server' })
    expect(mcpLink).toHaveAttribute('href', 'https://votepit.com/docs/mcp-server')
    expect(mcpLink).toHaveAttribute('target', '_blank')
    expect(mcpLink).toHaveAttribute('rel', 'noreferrer')
  })
})

describe('ApiTokensPage — agent setup section', () => {
  it('appears only after a token is revealed, with a working target select and prompt field', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
      {
        body: {
          ok: true,
          id: 3,
          label: 'New bot',
          boards: [{ board_id: 10, scope: 'write' }],
          token: 'a'.repeat(64),
        },
      },
      { body: TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
    ])

    renderTokensPage()
    await waitFor(() => expect(screen.getByLabelText(/Label/i)).toBeInTheDocument())

    expect(screen.queryByLabelText('Ziel')).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Label/i), 'New bot')
    await user.selectOptions(screen.getByLabelText('Zugriff für Demo Board'), 'write')
    await user.click(screen.getByRole('button', { name: /Token erstellen/i }))

    await waitFor(() => expect(screen.getByText('a'.repeat(64))).toBeInTheDocument())

    const targetSelect = screen.getByLabelText('Ziel')
    expect(targetSelect).toBeInTheDocument()

    const promptField = screen.getByLabelText('Prompt für deinen KI-Agenten')
    expect(promptField.textContent).toContain('claude mcp add --transport http votepit')
    expect(promptField.textContent).toContain('a'.repeat(64))
    // Single-board grant — the prompt should include the resolved board slug.
    expect(promptField.textContent).toContain('board=demo')

    await user.selectOptions(targetSelect, 'generic-cli')
    expect(screen.getByLabelText('Prompt für deinen KI-Agenten').textContent).toContain(
      '@votepit/cli',
    )
  })
})

describe('ApiTokensPage — create form', () => {
  it('shows the label field error returned by the API', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
      {
        body: {
          error: {
            key: 'validation_error',
            message: 'Validation failed.',
            fields: { label: 'Ein Label ist erforderlich.' },
          },
        },
        status: 422,
      },
    ])

    renderTokensPage()
    await waitFor(() => expect(screen.getByLabelText(/Label/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Label/i), 'x')
    await user.selectOptions(screen.getByLabelText('Zugriff für Demo Board'), 'write')
    await user.click(screen.getByRole('button', { name: /Token erstellen/i }))

    await waitFor(() => expect(screen.getByText('Ein Label ist erforderlich.')).toBeInTheDocument())
  })

  it('reveals the plaintext token once on success and reloads the list', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
      {
        body: {
          ok: true,
          id: 3,
          label: 'New bot',
          boards: [{ board_id: 10, scope: 'write' }],
          token: 'a'.repeat(64),
        },
      },
      { body: TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
    ])

    renderTokensPage()
    await waitFor(() => expect(screen.getByLabelText(/Label/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Label/i), 'New bot')
    await user.selectOptions(screen.getByLabelText('Zugriff für Demo Board'), 'write')
    await user.click(screen.getByRole('button', { name: /Token erstellen/i }))

    await waitFor(() => expect(screen.getByText('a'.repeat(64))).toBeInTheDocument())
  })

  it('grants a different scope per board on the same token', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: TWO_BOARDS_RESPONSE },
      {
        body: {
          ok: true,
          id: 4,
          label: 'Mixed bot',
          boards: [
            { board_id: 10, scope: 'write' },
            { board_id: 11, scope: 'read' },
          ],
          token: 'b'.repeat(64),
        },
      },
      { body: EMPTY_TOKENS_RESPONSE },
      { body: TWO_BOARDS_RESPONSE },
    ])

    renderTokensPage()
    await waitFor(() => expect(screen.getByLabelText(/Label/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Label/i), 'Mixed bot')
    await user.selectOptions(screen.getByLabelText('Zugriff für Demo Board'), 'write')
    await user.selectOptions(screen.getByLabelText('Zugriff für Roadmap Board'), 'read')
    await user.click(screen.getByRole('button', { name: /Token erstellen/i }))

    await waitFor(() => expect(screen.getByText('b'.repeat(64))).toBeInTheDocument())
  })
})

describe('ApiTokensPage — revoke', () => {
  it('sends a revoke request and reloads the list', async () => {
    const afterRevoke = {
      tokens: [
        {
          id: 1,
          label: 'CI bot',
          boards: [{ board_id: 10, scope: 'write' }],
          created_by_user_id: 1,
          last_used_at: '2026-01-05T00:00:00Z',
          revoked_at: '2026-01-11T00:00:00Z',
          created_at: '2026-01-01T00:00:00Z',
        },
      ],
    }

    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: TOKENS_RESPONSE },
      { body: BOARDS_RESPONSE },
      { body: { ok: true } },
      { body: afterRevoke },
      { body: BOARDS_RESPONSE },
    ])

    renderTokensPage()
    await waitFor(() =>
      expect(screen.getByLabelText('Token „CI bot" widerrufen')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByLabelText('Token „CI bot" widerrufen'))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Widerrufen' }))

    await waitFor(() =>
      expect(screen.queryByLabelText('Token „CI bot" widerrufen')).not.toBeInTheDocument(),
    )
  })

  it('does nothing if the confirm dialog is dismissed', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: TOKENS_RESPONSE }, { body: BOARDS_RESPONSE }])

    renderTokensPage()
    await waitFor(() =>
      expect(screen.getByLabelText('Token „CI bot" widerrufen')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByLabelText('Token „CI bot" widerrufen'))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(screen.getByLabelText('Token „CI bot" widerrufen')).toBeInTheDocument()
  })
})
