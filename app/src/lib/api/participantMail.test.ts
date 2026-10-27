/**
 * Tests for the participant-mail API client: preferences, unsubscribe and the
 * idea follow routes. fetch is mocked; no real network calls are made.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  followIdea,
  getIdeaFollow,
  getParticipantMailPreferences,
  saveParticipantMailPreferences,
  unfollowIdea,
  unsubscribeParticipantMail,
} from './participantMail'

function mockFetchOnce(body: object, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

const PREFS = {
  categories: {
    my_ideas: { cadence: 'instant', allowed: ['instant', 'daily', 'weekly', 'off'] },
    participated: { cadence: 'daily', allowed: ['instant', 'daily', 'weekly', 'off'] },
    new_ideas: { cadence: 'off', allowed: ['weekly', 'off'] },
  },
}

describe('api/participantMail', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('getParticipantMailPreferences() GETs the caller own cadences', async () => {
    const fetchMock = mockFetchOnce(PREFS)

    const prefs = await getParticipantMailPreferences()

    expect(prefs).toEqual(PREFS)
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/account/participant-mail-preferences')
    expect(init.method ?? 'GET').toBe('GET')
  })

  it('saveParticipantMailPreferences() PUTs only the categories it was given', async () => {
    const fetchMock = mockFetchOnce(PREFS)

    await saveParticipantMailPreferences({ participated: 'weekly' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/account/participant-mail-preferences')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(String(init.body))).toEqual({ participated: 'weekly' })
  })

  it('unsubscribeParticipantMail() POSTs the signed token and nothing else', async () => {
    const fetchMock = mockFetchOnce({ ok: true })

    const result = await unsubscribeParticipantMail('MQ.abc_-')

    expect(result).toEqual({ ok: true })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/participant-mail/unsubscribe')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({ token: 'MQ.abc_-' })
  })

  it('getIdeaFollow() GETs the follow state of the idea on its board', async () => {
    const fetchMock = mockFetchOnce({ following: true })

    const result = await getIdeaFollow('demo', 42)

    expect(result).toEqual({ following: true })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/demo/ideas/42/follow')
    expect(init.method ?? 'GET').toBe('GET')
  })

  it('followIdea() POSTs with a body so the CSRF header is sent', async () => {
    const fetchMock = mockFetchOnce({ following: true })

    await followIdea('demo', 42)

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/demo/ideas/42/follow')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({})
    expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json')
  })

  it('unfollowIdea() DELETEs the follow of the idea', async () => {
    const fetchMock = mockFetchOnce({ following: false })

    const result = await unfollowIdea('demo', '42')

    expect(result).toEqual({ following: false })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/demo/ideas/42/follow')
    expect(init.method).toBe('DELETE')
  })
})
