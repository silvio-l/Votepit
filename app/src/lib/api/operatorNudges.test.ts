/**
 * Tests for the operator-nudge API client: getOperatorNudges,
 * saveOperatorNudges, unsubscribeOperatorNudge. fetch is mocked; no real
 * network calls are made.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { getOperatorNudges, saveOperatorNudges, unsubscribeOperatorNudge } from './operatorNudges'

function mockFetchOnce(body: object, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

describe('api/operatorNudges', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('getOperatorNudges() GETs the caller own switches', async () => {
    const fetchMock = mockFetchOnce({ onboarding: true, quiet_board: false, plan_limit: true })

    const prefs = await getOperatorNudges()

    expect(prefs).toEqual({ onboarding: true, quiet_board: false, plan_limit: true })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toContain('/account/operator-nudges')
    expect(init.method ?? 'GET').toBe('GET')
  })

  it('saveOperatorNudges() PUTs only the switches it was given', async () => {
    const fetchMock = mockFetchOnce({
      ok: true,
      onboarding: true,
      quiet_board: false,
      plan_limit: true,
    })

    await saveOperatorNudges({ quiet_board: false })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toContain('/account/operator-nudges')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(String(init.body))).toEqual({ quiet_board: false })
  })

  it('unsubscribeOperatorNudge() POSTs the signed token and nothing else', async () => {
    const fetchMock = mockFetchOnce({ ok: true, kind: 'plan_limit' })

    const result = await unsubscribeOperatorNudge('MQ.abc_-')

    expect(result).toEqual({ ok: true, kind: 'plan_limit' })
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toContain('/operator-nudges/unsubscribe')
    expect(init.method).toBe('POST')
    expect(JSON.parse(String(init.body))).toEqual({ token: 'MQ.abc_-' })
  })
})
