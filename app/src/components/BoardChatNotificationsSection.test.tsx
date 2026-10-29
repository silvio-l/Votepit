/**
 * RTL tests for the Slack/Discord chat-notification section (issue 18):
 * load + status display, saving a URL (never echoed back), the plan gate
 * with upgrade hint, and the localized validation error.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { User } from '../lib/api/model'
import { setFeatures } from '../lib/features'
import { setViewer } from '../lib/viewer'
import { BoardChatNotificationsSection } from './BoardChatNotificationsSection'

const SLACK = {
  provider: 'slack',
  url_hint: 'hooks.slack.com/…abcd',
  last_delivery_at: null,
  last_delivery_ok: null,
  last_delivery_error: null,
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
      <BoardChatNotificationsSection boardSlug="demo" />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  setViewer(null)
  setFeatures(undefined)
})

describe('BoardChatNotificationsSection', () => {
  it('shows a configured integration by its hint only', async () => {
    mockFetch(() => ({ body: { allowed: true, integrations: { slack: SLACK, discord: null } } }))

    renderSection()

    await waitFor(() => expect(screen.getByText('hooks.slack.com/…abcd')).toBeInTheDocument())
    expect(screen.getByText('Noch keine Nachricht gesendet')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Trennen' })).toBeInTheDocument()
    expect(screen.getByLabelText(/Slack-Webhook-URL/)).toHaveValue('')
  })

  it('saves a Discord URL via PUT and shows the returned hint', async () => {
    let saved = false
    const fetchSpy = mockFetch((_url, init) => {
      if (init?.method === 'PUT') {
        saved = true
        return { body: { ok: true, provider: 'discord', url_hint: 'discord.com/…oken' } }
      }
      return {
        body: {
          allowed: true,
          integrations: {
            slack: null,
            discord: saved
              ? { ...SLACK, provider: 'discord', url_hint: 'discord.com/…oken' }
              : null,
          },
        },
      }
    })

    renderSection()
    const user = userEvent.setup()
    const input = await screen.findByLabelText(/Discord-Webhook-URL/)
    await user.type(input, 'https://discord.com/api/webhooks/1/token')
    const connectButtons = screen.getAllByRole('button', { name: 'Verbinden' })
    await user.click(connectButtons[1] as HTMLElement)

    await waitFor(() => expect(screen.getByText('discord.com/…oken')).toBeInTheDocument())
    const put = fetchSpy.mock.calls.find(([, init]) => init?.method === 'PUT')
    expect(String(put?.[0])).toContain('/admin/boards/demo/chat-notifications/discord')
    expect(JSON.parse(String(put?.[1]?.body))).toEqual({
      url: 'https://discord.com/api/webhooks/1/token',
    })
    expect(input).toHaveValue('')
  })

  it('shows a localized error for an invalid URL', async () => {
    mockFetch((_url, init) =>
      init?.method === 'PUT'
        ? {
            status: 422,
            body: { error: { key: 'validation_error', message: 'x', fields: { url: 'x' } } },
          }
        : { body: { allowed: true, integrations: { slack: null, discord: null } } },
    )

    renderSection()
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText(/Slack-Webhook-URL/), 'https://evil.example/x')
    await user.click(screen.getAllByRole('button', { name: 'Verbinden' })[0] as HTMLElement)

    await waitFor(() =>
      expect(
        screen.getByText('Das ist keine gültige Webhook-URL für diesen Dienst.'),
      ).toBeInTheDocument(),
    )
  })

  it('disables the inputs and shows the upgrade hint when the plan does not include it', async () => {
    setFeatures({ board_smtp: false, legal_links: null, billing: true })
    setViewer({ memberships: [{ account_slug: 'demo', role: 'owner' }] } as unknown as User)
    mockFetch(() => ({ body: { allowed: false, integrations: { slack: SLACK, discord: null } } }))

    renderSection()

    await waitFor(() => expect(screen.getByLabelText(/Discord-Webhook-URL/)).toBeDisabled())
    expect(screen.getByLabelText(/Slack-Webhook-URL/)).toBeDisabled()
    expect(screen.getByText(/Auf deinem aktuellen Plan nicht verfügbar/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Jetzt upgraden/ })).toBeInTheDocument()
    // Disconnecting stays possible after a downgrade.
    expect(screen.getByRole('button', { name: 'Trennen' })).toBeEnabled()
  })

  it('offers no upgrade link to a moderator, only the owner note', async () => {
    setFeatures({ board_smtp: false, legal_links: null, billing: true })
    setViewer({ memberships: [{ account_slug: 'demo', role: 'moderator' }] } as unknown as User)
    mockFetch(() => ({ body: { allowed: false, integrations: { slack: SLACK, discord: null } } }))

    renderSection()

    await waitFor(() => expect(screen.getByLabelText(/Discord-Webhook-URL/)).toBeDisabled())
    expect(screen.queryByRole('link', { name: /Jetzt upgraden/ })).toBeNull()
    expect(screen.getByText('Den Tarif kann nur der Konto-Owner ändern.')).toBeInTheDocument()
  })
})
