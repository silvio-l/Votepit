import { render, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, request } from '../lib/api/client'
import { I18nProvider, useI18n } from '../lib/i18n/context'
import commonDe from '../lib/i18n/dictionaries/common.de'
import commonEn from '../lib/i18n/dictionaries/common.en'
import commonFr from '../lib/i18n/dictionaries/common.fr'

function mockTotpRequired() {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => {
      return new Response(
        JSON.stringify({ error: { key: 'totp_required', message: 'Two-factor required' } }),
        { status: 403, headers: { 'Content-Type': 'application/json' } },
      )
    }),
  )
}

async function totpMessage(): Promise<string> {
  try {
    await request('GET', '/anything')
  } catch (e) {
    if (e instanceof ApiError) return e.message
  }
  throw new Error('expected an ApiError')
}

describe('request() totp_required message', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
    document.documentElement.lang = ''
  })

  it('uses German for a German page', async () => {
    mockTotpRequired()
    document.documentElement.lang = 'de'
    expect(await totpMessage()).toBe(commonDe['state.totpRequired'])
  })

  it('falls back to English (not German) while a lazy language is not loaded yet', async () => {
    mockTotpRequired()
    document.documentElement.lang = 'it'
    expect(await totpMessage()).toBe(commonEn['state.totpRequired'])
  })

  it('uses the active lazily loaded language once its catalog is loaded', async () => {
    localStorage.setItem('vp_lang', 'fr')
    let ready = false
    function Probe() {
      const { language } = useI18n()
      ready = language === 'fr'
      return null
    }
    render(
      <I18nProvider>
        <Probe />
      </I18nProvider>,
    )
    await waitFor(() => expect(ready && document.documentElement.lang === 'fr').toBe(true))
    mockTotpRequired()
    await waitFor(async () => expect(await totpMessage()).toBe(commonFr['state.totpRequired']))
  })
})
