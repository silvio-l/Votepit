/**
 * Board settings → deadlines (broaden-use-cases issue 04): the effective
 * state comes from the server, inputs go out with an explicit offset, the
 * clear action sends null bounds, and a 422 shows the field error.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fromLocalInput } from '../lib/boardPhases'
import { PhaseSettings } from './PhaseSettings'

function settings(overrides: Record<string, unknown> = {}) {
  return {
    submissions_opens_at: null,
    submissions_closes_at: null,
    voting_opens_at: null,
    voting_closes_at: '2026-03-31T22:00:00Z',
    phases: {
      submissions: { state: 'open', opens_at: null, closes_at: null },
      voting: { state: 'closed', opens_at: null, closes_at: '2026-03-31T22:00:00Z' },
    },
    now: '2026-04-15T12:00:00Z',
    statuses_closed_for_votes: [
      {
        key: 'done',
        category: 'done',
        category_name: 'done',
        is_system: true,
        label_de: null,
        label_en: null,
        is_hidden: false,
        allow_votes: false,
        allow_comments: true,
        idea_count: null,
      },
    ],
    ...overrides,
  }
}

type Call = { method: string; url: string; body: unknown }

function mockApi(postResponse: { status: number; body: object }) {
  const calls: Call[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const method = init?.method ?? 'GET'
    calls.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : null })
    const r = method === 'POST' ? postResponse : { status: 200, body: settings() }
    return new Response(JSON.stringify(r.body), {
      status: r.status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return calls
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('PhaseSettings', () => {
  it('shows the server-computed effective state and the statuses that close voting', async () => {
    mockApi({ status: 200, body: settings() })
    render(<PhaseSettings boardSlug="acme" />)
    fireEvent.click(screen.getByRole('button', { name: 'Fristen verwalten' }))

    await waitFor(() =>
      expect(screen.getByTestId('phase-voting')).toHaveTextContent('Aktuell: geschlossen'),
    )
    expect(screen.getByTestId('phase-submissions')).toHaveTextContent('Aktuell: offen')
    expect(screen.getByTestId('phase-voting')).toHaveTextContent(
      /diesen Status geschlossen: Erledigt/,
    )
    expect(screen.getByText(/Zeiten in deiner Zeitzone:/)).toBeInTheDocument()
  })

  it('saves all four bounds with an explicit offset', async () => {
    const calls = mockApi({ status: 200, body: settings() })
    render(<PhaseSettings boardSlug="acme" />)
    fireEvent.click(screen.getByRole('button', { name: 'Fristen verwalten' }))
    const opens = await screen.findByLabelText('Öffnet', {
      selector: '#phase-submissions_opens_at',
    })

    fireEvent.change(opens, { target: { value: '2026-05-01T09:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Fristen speichern' }))

    await waitFor(() => expect(screen.getByText('Fristen gespeichert.')).toBeInTheDocument())
    const post = calls.find((c) => c.method === 'POST')
    expect(post?.url).toContain('/admin/boards/acme/phases')
    expect(post?.body).toMatchObject({
      submissions_opens_at: fromLocalInput('2026-05-01T09:00'),
      submissions_closes_at: null,
      voting_opens_at: null,
    })
    expect((post?.body as Record<string, string>).voting_closes_at).toMatch(/[+-]\d{2}:\d{2}$/)
  })

  it('clears one phase by sending null bounds', async () => {
    const calls = mockApi({ status: 200, body: settings({ voting_closes_at: null }) })
    render(<PhaseSettings boardSlug="acme" />)
    fireEvent.click(screen.getByRole('button', { name: 'Fristen verwalten' }))
    await screen.findByTestId('phase-voting')

    fireEvent.click(screen.getByRole('button', { name: 'Zeitfenster entfernen' }))

    await waitFor(() => expect(calls.some((c) => c.method === 'POST')).toBe(true))
    expect(calls.find((c) => c.method === 'POST')?.body).toMatchObject({
      voting_opens_at: null,
      voting_closes_at: null,
    })
  })

  it('shows the order error on the end field when the server rejects the window', async () => {
    mockApi({
      status: 422,
      body: {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { submissions_closes_at: 'The end must be after the start.' },
        },
      },
    })
    render(<PhaseSettings boardSlug="acme" />)
    fireEvent.click(screen.getByRole('button', { name: 'Fristen verwalten' }))
    const opens = await screen.findByLabelText('Öffnet', {
      selector: '#phase-submissions_opens_at',
    })
    const closes = screen.getByLabelText('Schließt', { selector: '#phase-submissions_closes_at' })

    fireEvent.change(opens, { target: { value: '2026-05-02T09:00' } })
    fireEvent.change(closes, { target: { value: '2026-05-01T09:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Fristen speichern' }))

    await waitFor(() =>
      expect(screen.getByText('Das Ende muss nach dem Beginn liegen.')).toBeInTheDocument(),
    )
  })
})
