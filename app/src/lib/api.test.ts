/**
 * Sample tests proving api.ts applies the account prefix correctly under
 * cloud-mode account context (cloud path routing, SPA half).
 * Not exhaustive over every endpoint — just enough call sites (a plain GET,
 * a GET with query params, and a POST) to prove the accountPath() wrapping
 * pattern works end to end, plus proof that self-host (no account set)
 * leaves requests unprefixed (zero behavior change for the default mode).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setAccountSlug } from './accountContext'
import { createIdea, getBoard, getDefaultBoardSlug } from './api'

function mockFetchOnce(body: object, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

describe('api.ts — cloud account-prefix wrapping', () => {
  beforeEach(() => {
    setAccountSlug(null)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('self-host (no account set): requests stay unprefixed', async () => {
    const fetchMock = mockFetchOnce({
      board: { id: 1, slug: 'demo', name: 'Demo', intro: '', show_badge: true },
      ideas: [],
      stats: { weekly_votes: 0, weekly_new_ideas: 0, avg_consensus: 0 },
      active_status: null,
      active_sort: 'top',
      page: 1,
      total_pages: 1,
      is_authenticated: false,
    })

    await getBoard('demo')

    const url = fetchMock.mock.calls[0]?.[0] as string
    expect(url).toBe('/demo')
  })

  it('cloud mode: a plain GET is prefixed with the account slug', async () => {
    setAccountSlug('acme')
    const fetchMock = mockFetchOnce({
      board: { id: 1, slug: 'demo', name: 'Demo', intro: '', show_badge: true },
      ideas: [],
      stats: { weekly_votes: 0, weekly_new_ideas: 0, avg_consensus: 0 },
      active_status: null,
      active_sort: 'top',
      page: 1,
      total_pages: 1,
      is_authenticated: false,
    })

    await getBoard('demo')

    const url = fetchMock.mock.calls[0]?.[0] as string
    expect(url).toBe('/acme/demo')
  })

  it('cloud mode: a GET with an /api/... path is prefixed before /api', async () => {
    setAccountSlug('acme')
    const fetchMock = mockFetchOnce({ slug: 'demo' })

    await getDefaultBoardSlug()

    const url = fetchMock.mock.calls[0]?.[0] as string
    expect(url).toBe('/acme/api/board/default')
  })

  it('cloud mode: a POST is prefixed with the account slug', async () => {
    setAccountSlug('acme')
    const fetchMock = mockFetchOnce({ id: 42 }, 201)

    await createIdea('demo', { title: 'Idea', body: 'Body', website: '', _form_at: 'stamp' })

    const url = fetchMock.mock.calls[0]?.[0] as string
    expect(url).toBe('/acme/demo/ideas')
  })
})
