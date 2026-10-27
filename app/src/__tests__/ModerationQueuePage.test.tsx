/**
 * RTL tests for ModerationQueuePage (pre-moderation, broaden-use-cases
 * issue 05) — user-visible behaviour only, fetch mocked globally.
 *
 *  1. Lists the pending ideas with the waiting count
 *  2. Approve sends the idea id to the approve endpoint and reloads
 *  3. Bulk approve sends every selected id in one request
 *  4. Reject goes through the reason-code dialog and the existing /moderate route
 *  5. 403 → access denied
 *  6. Guest ideas (ADR 0006) carry a "Gast" label; "reject all guest ideas"
 *     asks for confirmation and posts confirm=true to its own route
 *  7. "Approve and trust author" (issue 11) is offered for member ideas
 *     only, posts the single id to its own route and links to the list
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ModerationQueuePage from '../pages/ModerationQueuePage'

const BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 1, is_admin: false, memberships: [] } }

const QUEUE = {
  board: { slug: 'demo', name: 'Demo Board', premoderation: 'all' },
  count: 2,
  ideas: [
    { id: 11, title: 'Erste Idee', body: 'Text eins', created_at: '2026-09-01 10:00:00' },
    { id: 12, title: 'Zweite Idee', body: 'Text zwei', created_at: '2026-09-02 10:00:00' },
  ],
}

const EMPTY_QUEUE = { ...QUEUE, count: 0, ideas: [] }

interface Call {
  method: string
  url: string
  body: unknown
}

/** Routes by URL; returns the recorded non-GET calls. */
function mockApi(
  options: { queueStatus?: number; afterAction?: object; queue?: object } = {},
): Call[] {
  const calls: Call[] = []
  let actionDone = false
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    const method = init?.method ?? 'GET'
    const json = (body: object, status = 200) =>
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      })

    if (url.includes('/bootstrap')) return json(BOOTSTRAP)
    if (url.startsWith('/notifications')) return json({ notifications: [] })
    if (method !== 'GET') {
      calls.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : null })
      actionDone = true
      if (url.endsWith('/guest-reject-all')) return json({ ok: true, rejected: 1 })
      if (url.endsWith('/queue/approve-and-trust')) {
        const id = (calls[calls.length - 1].body as { id: number }).id
        return json({ ok: true, approved: [id], trusted: true })
      }
      if (url.endsWith('/queue/approve')) {
        const ids = (calls[calls.length - 1].body as { ids: number[] }).ids
        return json({ ok: true, approved: ids })
      }
      return json({ ok: true, moderation_action_id: 1 })
    }
    if (url.endsWith('/admin/boards/demo/queue')) {
      if (options.queueStatus) {
        return json(
          { error: { key: 'account_forbidden', message: 'Kein Zugriff.' } },
          options.queueStatus,
        )
      }
      return json(actionDone ? (options.afterAction ?? EMPTY_QUEUE) : (options.queue ?? QUEUE))
    }
    return json({}, 404)
  })
  return calls
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/boards/demo/queue']}>
      <Routes>
        <Route path="/admin/boards/:boardSlug/queue" element={<ModerationQueuePage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('ModerationQueuePage', () => {
  it('lists the pending ideas with the waiting count', async () => {
    mockApi()
    renderPage()

    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())
    expect(screen.getByText('Zweite Idee')).toBeInTheDocument()
    expect(screen.getAllByText('Wartende Ideen (2)').length).toBeGreaterThan(0)
  })

  it('approves a single idea and reloads the queue', async () => {
    const calls = mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())

    const row = screen.getByText('Erste Idee').closest('tr') as HTMLElement
    await userEvent.setup().click(within(row).getByRole('button', { name: 'Freigeben' }))

    await waitFor(() => expect(screen.getByText('1 Idee(n) freigegeben.')).toBeInTheDocument())
    expect(calls).toEqual([
      { method: 'POST', url: '/admin/boards/demo/queue/approve', body: { ids: [11] } },
    ])
    expect(screen.getByText('Keine Ideen warten auf Freigabe.')).toBeInTheDocument()
  })

  it('bulk-approves every selected idea in one request', async () => {
    const calls = mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByLabelText('Alle auswählen'))
    await user.click(screen.getByRole('button', { name: 'Auswahl freigeben (2)' }))

    await waitFor(() => expect(calls).toHaveLength(1))
    expect(calls[0].body).toEqual({ ids: [11, 12] })
  })

  it('rejects through the reason-code dialog and the existing moderate route', async () => {
    const calls = mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Zweite Idee')).toBeInTheDocument())

    const user = userEvent.setup()
    const row = screen.getByText('Zweite Idee').closest('tr') as HTMLElement
    await user.click(within(row).getByRole('button', { name: 'Ablehnen' }))

    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Entfernen' }))

    await waitFor(() => expect(calls).toHaveLength(1))
    expect(calls[0].url).toBe('/demo/ideas/12/moderate')
    expect(calls[0].body).toMatchObject({ reason_code: 'community_rule' })
  })

  it('shows access denied for members without a moderation role', async () => {
    mockApi({ queueStatus: 403 })
    renderPage()

    await waitFor(() => expect(screen.getByText('Kein Zugriff')).toBeInTheDocument())
  })

  it('labels guest ideas and bulk-rejects them after confirmation', async () => {
    const queue = {
      ...QUEUE,
      guest_count: 1,
      ideas: [
        { ...QUEUE.ideas[0], author_type: 'member' },
        { ...QUEUE.ideas[1], author_type: 'guest' },
      ],
    }
    const calls = mockApi({ queue })
    renderPage()
    await waitFor(() => expect(screen.getByText('Zweite Idee')).toBeInTheDocument())

    const guestRow = screen.getByText('Zweite Idee').closest('tr') as HTMLElement
    expect(within(guestRow).getByText('Gast')).toBeInTheDocument()
    const memberRow = screen.getByText('Erste Idee').closest('tr') as HTMLElement
    expect(within(memberRow).queryByText('Gast')).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Alle Gast-Ideen ablehnen (1)' }))
    const dialog = await screen.findByRole('alertdialog')
    expect(calls).toHaveLength(0)
    await user.click(within(dialog).getByRole('button', { name: 'Alle ablehnen' }))

    await waitFor(() => expect(calls).toHaveLength(1))
    expect(calls[0]).toEqual({
      method: 'POST',
      url: '/admin/boards/demo/moderation/guest-reject-all',
      body: { confirm: true },
    })
    await waitFor(() => expect(screen.getByText('1 Gast-Idee(n) abgelehnt.')).toBeInTheDocument())
  })

  it('hides the guest bulk reject without waiting guest ideas', async () => {
    mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: /Gast-Ideen ablehnen/ })).not.toBeInTheDocument()
  })

  it('approves and trusts the author of a member idea, never offered for guests', async () => {
    const queue = {
      ...QUEUE,
      guest_count: 1,
      ideas: [
        { ...QUEUE.ideas[0], author_type: 'member' },
        { ...QUEUE.ideas[1], author_type: 'guest' },
      ],
    }
    const calls = mockApi({ queue })
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())

    const guestRow = screen.getByText('Zweite Idee').closest('tr') as HTMLElement
    expect(
      within(guestRow).queryByRole('button', { name: 'Freigeben und Person vertrauen' }),
    ).not.toBeInTheDocument()

    const memberRow = screen.getByText('Erste Idee').closest('tr') as HTMLElement
    await userEvent
      .setup()
      .click(within(memberRow).getByRole('button', { name: 'Freigeben und Person vertrauen' }))

    await waitFor(() =>
      expect(
        screen.getByText(
          'Idee freigegeben. Weitere Ideen dieser Person erscheinen ab jetzt sofort.',
        ),
      ).toBeInTheDocument(),
    )
    expect(calls).toEqual([
      { method: 'POST', url: '/admin/boards/demo/queue/approve-and-trust', body: { id: 11 } },
    ])
  })

  it('does not offer trust when the author type is unknown', async () => {
    mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())
    expect(
      screen.queryByRole('button', { name: 'Freigeben und Person vertrauen' }),
    ).not.toBeInTheDocument()
  })

  it('links to the trusted-authors list', async () => {
    mockApi()
    renderPage()
    await waitFor(() => expect(screen.getByText('Erste Idee')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'Vertrauenswürdige Personen' })).toHaveAttribute(
      'href',
      '/admin/trusted-authors',
    )
  })
})
