/**
 * RTL tests for the Linear/GitHub connection section (issue 19): token
 * only shown as hint, GitHub webhook secret shown once after connect, the
 * plan gate and localized provider errors.
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { IssueTrackerConnectionsSection } from './IssueTrackerConnectionsSection'

const GITHUB = {
  provider: 'github',
  target: 'acme/roadmap',
  token_hint: '…abcd',
  webhook_url:
    'https://app.example/api/issue-tracker-webhooks/github/0123456789abcdef0123456789abcdef',
  sync_enabled: true,
  updated_at: '2026-10-01 10:00:00',
}

type Handler = (url: string, init?: RequestInit) => { body: unknown; status?: number }

function mockFetch(handler: Handler) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const { body, status = 200 } = handler(String(input), init)
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderSection() {
  return render(
    <MemoryRouter>
      <IssueTrackerConnectionsSection />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('IssueTrackerConnectionsSection', () => {
  it('shows an existing connection by target, token hint and webhook URL', async () => {
    mockFetch(() => ({ body: { allowed: true, connections: { linear: null, github: GITHUB } } }))

    renderSection()

    await waitFor(() =>
      expect(screen.getByText('Verbunden mit acme/roadmap, Token …abcd')).toBeInTheDocument(),
    )
    expect(screen.getByText(GITHUB.webhook_url)).toBeInTheDocument()
    expect(screen.getByText('Status-Sync aktiv')).toBeInTheDocument()
    expect(screen.getByLabelText('GitHub-Token')).toHaveValue('')
  })

  it('connects GitHub and reveals the webhook secret once', async () => {
    const fetchSpy = mockFetch((_url, init) => {
      if (init?.method === 'PUT') {
        return { body: { ok: true, connection: GITHUB, webhook_secret: 'f'.repeat(64) } }
      }
      return { body: { allowed: true, connections: { linear: null, github: null } } }
    })
    const user = userEvent.setup()

    renderSection()
    await waitFor(() => expect(screen.getByLabelText('GitHub-Token')).toBeEnabled())
    await user.type(screen.getByLabelText('GitHub-Token'), 'gh-test-token')
    await user.type(screen.getByLabelText('Repository'), 'acme/roadmap')
    const githubForm = screen.getByRole('form', { name: 'GitHub' })
    await user.click(within(githubForm).getByRole('button', { name: 'Verbinden' }))

    await waitFor(() => expect(screen.getByText('f'.repeat(64))).toBeInTheDocument())
    const put = fetchSpy.mock.calls.find(([, init]) => init?.method === 'PUT')
    expect(String(put?.[0])).toContain('/admin/issue-trackers/github')
    expect(JSON.parse(String(put?.[1]?.body))).toEqual({
      repository: 'acme/roadmap',
      token: 'gh-test-token',
    })
    expect(screen.getByLabelText('GitHub-Token')).toHaveValue('')
  })

  it('shows the provider error without echoing the token', async () => {
    mockFetch((_url, init) => {
      if (init?.method === 'PUT') {
        return {
          status: 422,
          body: { error: { key: 'issue_tracker_invalid_credentials', message: 'x' } },
        }
      }
      return { body: { allowed: true, connections: { linear: null, github: null } } }
    })
    const user = userEvent.setup()

    renderSection()
    await waitFor(() => expect(screen.getByLabelText('Linear-API-Key')).toBeEnabled())
    await user.type(screen.getByLabelText('Linear-API-Key'), 'lin-test-key')
    await user.type(screen.getByLabelText('Team-Kürzel'), 'ENG')
    const linearForm = screen.getByRole('form', { name: 'Linear' })
    await user.click(within(linearForm).getByRole('button', { name: 'Verbinden' }))

    await waitFor(() => expect(screen.getByText('Der Token wurde abgelehnt.')).toBeInTheDocument())
    expect(screen.queryByText(/lin-test-key/)).not.toBeInTheDocument()
  })

  it('disables the inputs when the plan does not include issue trackers', async () => {
    mockFetch(() => ({ body: { allowed: false, connections: { linear: null, github: GITHUB } } }))

    renderSection()

    await waitFor(() =>
      expect(
        screen.getByText(
          /Linear- und GitHub-Issues sind in deinem aktuellen Tarif nicht enthalten/,
        ),
      ).toBeInTheDocument(),
    )
    expect(screen.getByLabelText('GitHub-Token')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Trennen' })).toBeEnabled()
  })
})
