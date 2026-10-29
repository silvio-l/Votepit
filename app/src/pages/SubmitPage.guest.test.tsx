/**
 * SubmitPage — guest idea submission (ADR 0006): a visitor without an
 * account sees the form (instead of a login redirect) only when the board
 * accepts guest ideas; the inline Turnstile token travels with the submit;
 * the thanks state distinguishes "waiting for review" from "published"; and
 * the device panel lists and withdraws this device's waiting ideas. Guests
 * never trigger the AI-confirmed duplicate path (confirm=1).
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setFeatures } from '../lib/features'
import SubmitPage from './SubmitPage'

const GUEST_BOOT = { csrf_token: 'test-csrf', user: null }

function guestForm(available: boolean) {
  return {
    board: {
      id: 1,
      slug: 'acme',
      name: 'Acme Board',
      anonymous_submission_available: available,
      ...(available ? { turnstile_site_key: 'site-123' } : {}),
    },
    is_authenticated: false,
    form_at: 'stamp',
  }
}

interface Calls {
  urls: string[]
  posts: { url: string; body: Record<string, unknown> }[]
}

function mockFetch(
  formBody: object,
  opts: { postStatus?: number; postBody?: object; mine?: object[] } = {},
): Calls {
  const calls: Calls = { urls: [], posts: [] }
  let mine = opts.mine ?? []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    calls.urls.push(url)
    let status = 200
    let body: unknown = { notifications: [] }
    if (init?.method === 'POST') {
      const parsed = JSON.parse(String(init.body ?? '{}')) as Record<string, unknown>
      calls.posts.push({ url, body: parsed })
      if (url.includes('guest-withdraw')) {
        mine = []
        body = { ok: true }
      } else {
        status = opts.postStatus ?? 201
        body = opts.postBody ?? { ok: true, id: 7, moderation_state: 'pending' }
      }
    } else if (url.includes('bootstrap')) {
      body = GUEST_BOOT
    } else if (url.includes('/ideas/new')) {
      body = formBody
    } else if (url.includes('guest-mine')) {
      body = { ideas: mine }
    } else if (url.includes('search-duplicates')) {
      body = { candidates: [] }
    }
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return calls
}

function renderSubmitPage() {
  return render(
    <MemoryRouter initialEntries={['/acme/submit']}>
      <Routes>
        <Route path="/:boardSlug/submit" element={<SubmitPage />} />
        <Route path="/login" element={<div>login page</div>} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  // Fake Turnstile: solves immediately with a deterministic token.
  window.turnstile = {
    render: (_el, options) => {
      setTimeout(() => options.callback(`token-${options.action ?? ''}`), 0)
      return 'w1'
    },
    remove: () => undefined,
  }
})

afterEach(() => {
  window.turnstile = undefined
  setFeatures(undefined)
})

describe('SubmitPage — guest submission', () => {
  it('redirects to login when the board does not accept guest ideas', async () => {
    mockFetch(guestForm(false))
    renderSubmitPage()
    await waitFor(() => expect(screen.getByText('login page')).toBeInTheDocument())
  })

  it('shows the guest form with a guest notice instead of redirecting', async () => {
    mockFetch(guestForm(true))
    renderSubmitPage()
    expect(await screen.findByLabelText(/Titel/)).toBeInTheDocument()
    expect(screen.getByText(/ohne Konto/)).toBeInTheDocument()
    expect(screen.getByTestId('turnstile-widget')).toBeInTheDocument()
  })

  it('links the privacy policy from the guest notice (cookie hint, ADR 0006)', async () => {
    setFeatures({
      board_smtp: false,
      legal_links: {
        de: [
          { label: 'Impressum', href: 'https://example.test/impressum' },
          { label: 'Datenschutz', href: 'https://example.test/datenschutz' },
        ],
      },
    })
    mockFetch(guestForm(true))
    renderSubmitPage()

    const notice = await screen.findByTestId('guest-notice')
    const links = Array.from(notice.querySelectorAll('a')).map((a) => a.getAttribute('href'))
    expect(links).toContain('https://example.test/datenschutz')
    expect(links).not.toContain('https://example.test/impressum')
  })

  it('submits with the widget token and shows the review thanks state', async () => {
    const calls = mockFetch(guestForm(true))
    renderSubmitPage()

    fireEvent.change(await screen.findByLabelText(/Titel/), { target: { value: 'Dark mode' } })
    fireEvent.change(screen.getByLabelText(/Beschreibung/), {
      target: { value: 'Please add a dark mode.' },
    })
    const submit = screen.getByRole('button', { name: /Idee einreichen|Einreichen/ })
    await waitFor(() => expect(submit).toBeEnabled())
    fireEvent.click(submit)

    expect(await screen.findByText(/wartet auf Freigabe/i)).toBeInTheDocument()
    const post = calls.posts.find((p) => p.url.endsWith('/acme/ideas'))
    expect(post?.body.turnstile_token).toBe('token-anon_idea_submit')
    expect(post?.body.website).toBe('')
  })

  it('shows the published thanks state when the board publishes guest ideas directly', async () => {
    mockFetch(guestForm(true), { postBody: { ok: true, id: 9, moderation_state: 'published' } })
    renderSubmitPage()

    fireEvent.change(await screen.findByLabelText(/Titel/), { target: { value: 'Dark mode' } })
    const submit = screen.getByRole('button', { name: /Idee einreichen|Einreichen/ })
    await waitFor(() => expect(submit).toBeEnabled())
    fireEvent.click(submit)

    expect(await screen.findByText(/ist jetzt sichtbar/i)).toBeInTheDocument()
  })

  it('maps url_not_allowed to a localized hint', async () => {
    mockFetch(guestForm(true), {
      postStatus: 422,
      postBody: { error: { key: 'url_not_allowed', message: 'Links are not allowed.' } },
    })
    renderSubmitPage()

    fireEvent.change(await screen.findByLabelText(/Titel/), {
      target: { value: 'See example.com' },
    })
    const submit = screen.getByRole('button', { name: /Idee einreichen|Einreichen/ })
    await waitFor(() => expect(submit).toBeEnabled())
    fireEvent.click(submit)

    expect(await screen.findByText(/Links sind ohne Konto nicht erlaubt/)).toBeInTheDocument()
  })

  it('never asks for AI-confirmed duplicates as a guest', async () => {
    const calls = mockFetch(guestForm(true))
    renderSubmitPage()

    const title = await screen.findByLabelText(/Titel/)
    fireEvent.change(title, { target: { value: 'Dark mode' } })
    fireEvent.blur(title)
    await new Promise((r) => setTimeout(r, 450))

    expect(calls.urls.some((u) => u.includes('confirm=1'))).toBe(false)
  })

  it('lists this device’s waiting ideas and withdraws one', async () => {
    const calls = mockFetch(guestForm(true), {
      mine: [
        {
          id: 5,
          title: 'My waiting idea',
          moderation_state: 'pending',
          status: 'open',
          rejected: false,
          reason_code: null,
          created_at: '2026-09-01 10:00:00',
        },
      ],
    })
    renderSubmitPage()

    expect(await screen.findByText('My waiting idea')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Zurückziehen' }))

    await waitFor(() => expect(screen.queryByText('My waiting idea')).not.toBeInTheDocument())
    expect(calls.posts.some((p) => p.url.endsWith('/acme/ideas/5/guest-withdraw'))).toBe(true)
  })
})
