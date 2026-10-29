/**
 * WidgetApp — the iframe UI of the embeddable widget (ADR 0005): cookie-less
 * requests, identify only via a message from the allowed parent origin, and
 * read-only fallback (new tab) for unidentified visitors.
 */

import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { WidgetConfig } from './config'
import { widgetTranslator } from './i18n'
import { WidgetApp } from './WidgetApp'

const config: WidgetConfig = {
  origins: ['https://shop.example.com'],
  board: { slug: 'ideas', name: 'Ideas' },
  basePath: '/acme/ideas',
}
const t = widgetTranslator('en')
const idea = {
  id: 7,
  title: 'Dark mode',
  body: 'Please',
  status: 'open',
  up_count: 2,
  down_count: 0,
  comment_count: 1,
}

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  fetchMock = vi.fn((url: string) => {
    if (url.endsWith('/widget/identify'))
      return Promise.resolve(json({ token: 'vpw1.x.y', expires_in: 3600, user: { name: 'Ada' } }))
    if (url.endsWith('/vote'))
      return Promise.resolve(json({ my_vote: 'up', up_count: 3, down_count: 0 }))
    return Promise.resolve(json({ ideas: [idea] }))
  })
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

function hostMessage(origin: string, data: unknown, source: unknown = window.parent) {
  act(() => {
    window.dispatchEvent(
      new MessageEvent('message', { origin, data, source: source as MessageEventSource }),
    )
  })
}

describe('WidgetApp', () => {
  it('loads the public list without credentials and opens a new tab to vote when unidentified', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/acme/ideas')
    expect(init.credentials).toBe('omit')
    expect((init.headers as Record<string, string>).Authorization).toBeUndefined()

    fireEvent.click(screen.getByRole('button', { name: 'Vote up' }))
    expect(open).toHaveBeenCalledWith(
      `${window.location.origin}/acme/ideas/idea/7`,
      '_blank',
      'noopener,noreferrer',
    )
    expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/vote'))).toBe(false)
  })

  it('ignores an identify message from a foreign origin', async () => {
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')
    hostMessage('https://evil.example', { type: 'votepit:identify', jwt: 'a.b.c' })
    expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/widget/identify'))).toBe(false)
  })

  it('identifies via the allowed parent and votes with the Bearer token only', async () => {
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')
    hostMessage('https://shop.example.com', { type: 'votepit:identify', jwt: 'a.b.c' })

    await screen.findByText('Signed in as Ada')
    const identifyCall = fetchMock.mock.calls.find(([u]) =>
      String(u).endsWith('/widget/identify'),
    ) as [string, RequestInit]
    expect((identifyCall[1].headers as Record<string, string>).Authorization).toBe('Bearer a.b.c')
    expect(identifyCall[1].credentials).toBe('omit')

    fireEvent.click(screen.getByRole('button', { name: 'Vote up' }))
    await waitFor(() =>
      expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/widget/ideas/7/vote'))).toBe(
        true,
      ),
    )
    const voteCall = fetchMock.mock.calls.find(([u]) => String(u).endsWith('/vote')) as [
      string,
      RequestInit,
    ]
    expect((voteCall[1].headers as Record<string, string>).Authorization).toBe('Bearer vpw1.x.y')
    expect(voteCall[1].credentials).toBe('omit')
  })

  it('offers to continue on the board when the server asks for a Turnstile challenge', async () => {
    fetchMock.mockImplementation((url: string) => {
      if (url.endsWith('/widget/identify'))
        return Promise.resolve(json({ token: 'vpw1.x.y', expires_in: 3600, user: { name: 'Ada' } }))
      if (url.endsWith('/vote'))
        return Promise.resolve(json({ error: { key: 'turnstile_required', site_key: 'x' } }, 422))
      return Promise.resolve(json({ ideas: [idea] }))
    })
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')
    hostMessage('https://shop.example.com', { type: 'votepit:identify', jwt: 'a.b.c' })
    await screen.findByText('Signed in as Ada')

    fireEvent.click(screen.getByRole('button', { name: 'Vote up' }))
    await screen.findByText(t('verificationOnBoard'))
    expect(screen.queryByText(t('actionFailed'))).toBeNull()
    fireEvent.click(screen.getByTestId('widget-continue-on-board'))
    expect(open).toHaveBeenCalledTimes(1)
    expect(String(open.mock.calls[0]?.[0])).toContain('/acme/ideas')
  })

  it('tells the visitor when a submitted idea waits for approval (pre-moderation)', async () => {
    fetchMock.mockImplementation((url: string, init?: RequestInit) => {
      if (url.endsWith('/widget/identify'))
        return Promise.resolve(json({ token: 'vpw1.x.y', expires_in: 3600, user: { name: 'Ada' } }))
      if (url.endsWith('/ideas/new')) return Promise.resolve(json({ form_at: 'stamp' }))
      if (url.endsWith('/widget/ideas') && init?.method === 'POST')
        return Promise.resolve(json({ ok: true, id: 9, moderation_state: 'pending' }, 201))
      return Promise.resolve(json({ ideas: [idea] }))
    })
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')
    hostMessage('https://shop.example.com', { type: 'votepit:identify', jwt: 'a.b.c' })
    await screen.findByText('Signed in as Ada')

    fireEvent.click(screen.getByRole('button', { name: 'New idea' }))
    fireEvent.change(await screen.findByRole('textbox', { name: /Title/ }), {
      target: { value: 'Export as CSV' },
    })
    await waitFor(() =>
      expect(fetchMock.mock.calls.some(([u]) => String(u).endsWith('/ideas/new'))).toBe(true),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Submit idea' }))

    expect(
      await screen.findByText('Thanks! Your idea will appear once the team has approved it.'),
    ).toBeInTheDocument()
  })

  it('replies to the host only on its exact origin', async () => {
    const post = vi.spyOn(window.parent, 'postMessage').mockImplementation(() => {})
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    fireEvent.click(screen.getByRole('button', { name: 'Close' }))
    expect(post).not.toHaveBeenCalled() // host origin not known yet

    hostMessage('https://shop.example.com', { type: 'votepit:init' })
    fireEvent.click(screen.getByRole('button', { name: 'Close' }))
    expect(post).toHaveBeenCalledWith({ type: 'votepit:close' }, 'https://shop.example.com')
  })

  it('marks an idea with few votes (P-02) as text, not only by colour', async () => {
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    // two votes in total: below the 10-vote threshold
    expect(screen.getByTestId('widget-idea-7-few')).toHaveTextContent(t('fewVotes'))
  })

  it('shows no few-votes marker at exactly 10 votes (fewer than, not up to)', async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(json({ ideas: [{ ...idea, up_count: 6, down_count: 4 }] })),
    )
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    expect(screen.queryByTestId('widget-idea-7-few')).toBeNull()
  })

  it('keeps the net value as the main number and adds the approval share (P-02, 18a)', async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(json({ ideas: [{ ...idea, up_count: 3, down_count: 1 }] })),
    )
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    // Net (3 - 1) stays the number in the vote column; approval 3 of 4 = 75 %.
    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.getByTestId('widget-idea-7-approval')).toHaveTextContent('75% approval')
  })

  it('shows no approval share without any vote', async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(json({ ideas: [{ ...idea, up_count: 0, down_count: 0 }] })),
    )
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    expect(screen.queryByTestId('widget-idea-7-approval')).toBeNull()
  })

  it('never shows 100 % while a down vote exists (L-03 display guard)', async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(json({ ideas: [{ ...idea, up_count: 199, down_count: 1 }] })),
    )
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    expect(screen.getByTestId('widget-idea-7-approval')).toHaveTextContent('99% approval')
  })

  it('shows the few-votes hint under the approval share as on the board', async () => {
    fetchMock.mockImplementationOnce(() =>
      Promise.resolve(json({ ideas: [{ ...idea, up_count: 3, down_count: 1 }] })),
    )
    render(<WidgetApp config={config} t={t} />)
    await screen.findByText('Dark mode')

    const approval = screen.getByTestId('widget-idea-7-approval')
    const few = screen.getByTestId('widget-idea-7-few')
    expect(few).toHaveTextContent(t('fewVotes'))
    expect(approval.compareDocumentPosition(few) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })
})
