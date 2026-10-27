/**
 * request() error mapping — a 403 because 2FA is missing on an operator/
 * support account must tell the user what to do, not just "HTTP 403".
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { type ApiError, request } from '../lib/api'

function mock403(body: object) {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(
    new Response(JSON.stringify(body), {
      status: 403,
      headers: { 'Content-Type': 'application/json' },
    }),
  )
}

async function caught(): Promise<ApiError> {
  try {
    await request('GET', '/admin/overview')
  } catch (err) {
    return err as ApiError
  }
  throw new Error('request() did not reject')
}

afterEach(() => {
  vi.restoreAllMocks()
  document.documentElement.lang = ''
})

describe('request() — totp_required', () => {
  it('replaces the English API message with a localized 2FA hint (de)', async () => {
    document.documentElement.lang = 'de'
    mock403({
      error: {
        key: 'totp_required',
        message: 'Two-factor authentication is required for this area.',
      },
    })

    const err = await caught()

    expect(err.status).toBe(403)
    expect(err.payload.key).toBe('totp_required')
    expect(err.payload.message).toBe(
      'Für diesen Bereich ist die Zwei-Faktor-Authentifizierung Pflicht. Richte sie unter Profil → Sicherheit ein.',
    )
  })

  it('uses the English hint when the UI language is English', async () => {
    document.documentElement.lang = 'en'
    mock403({ error: { key: 'totp_required', message: 'x' } })

    expect((await caught()).payload.message).toBe(
      'Two-factor authentication is required for this area. Set it up under Profile → Security.',
    )
  })

  it('leaves other API error messages untouched', async () => {
    document.documentElement.lang = 'de'
    mock403({ error: { key: 'forbidden', message: 'Nope.' } })

    expect((await caught()).payload.message).toBe('Nope.')
  })
})
