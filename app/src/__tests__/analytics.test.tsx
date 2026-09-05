import { beforeEach, describe, expect, it } from 'vitest'
import {
  _resetAnalyticsForTests,
  initAnalytics,
  sanitizePath,
  setAnalyticsConfig,
  trackEvent,
  trackPageView,
} from '../lib/analytics'

function paqCalls(): unknown[][] {
  return window._paq ?? []
}

describe('sanitizePath', () => {
  it('keeps known static route segments untouched', () => {
    expect(sanitizePath('/admin/boards')).toBe('/admin/boards')
    expect(sanitizePath('/login/verify')).toBe('/login/verify')
  })

  it('masks dynamic segments (slugs, ids) as :id', () => {
    expect(sanitizePath('/my-board-slug')).toBe('/:id')
    expect(sanitizePath('/admin/boards/my-board-slug')).toBe('/admin/boards/:id')
    expect(sanitizePath('/acme/my-board/idea/42')).toBe('/:id/:id/idea/:id')
  })

  it('never leaks an email-shaped or token-shaped segment', () => {
    expect(sanitizePath('/invite/accept?token=deadbeef')).toBe('/invite/accept')
    expect(sanitizePath('/password/reset/confirm')).toBe('/password/reset/confirm')
    // query strings are never passed in by the caller (location.pathname
    // excludes them), but a defensive check costs nothing:
    expect(sanitizePath('/members/user@example.com/profile')).toBe('/members/:id/profile')
  })

  it('handles the root path', () => {
    expect(sanitizePath('/')).toBe('/')
  })
})

describe('analytics tracking (own + telemetry targets)', () => {
  beforeEach(() => {
    _resetAnalyticsForTests()
    for (const script of document.head.querySelectorAll('script')) script.remove()
  })

  it('is a no-op with nothing configured', () => {
    trackPageView('/admin/boards')
    trackEvent('Cloud', 'board_created')
    expect(paqCalls()).toEqual([])
  })

  it('loads the tracker cookielessly when an own analytics target is configured', () => {
    setAnalyticsConfig({ matomo_url: 'https://matomo.example.com', matomo_site_id: '10' })
    initAnalytics()

    expect(paqCalls()).toContainEqual(['setTrackerUrl', 'https://matomo.example.com/matomo.php'])
    expect(paqCalls()).toContainEqual(['setSiteId', '10'])
    expect(paqCalls()).toContainEqual(['disableCookies'])
    const script = document.head.querySelector('script[src="https://matomo.example.com/matomo.js"]')
    expect(script).not.toBeNull()
  })

  it('loads the telemetry tracker when opted in and no own target is set', () => {
    setAnalyticsConfig({
      telemetry: {
        opted_in: true,
        matomo_url: 'https://matomo.silvio-und-maik.de',
        matomo_site_id: '11',
      },
    })
    initAnalytics()

    expect(paqCalls()).toContainEqual(['setSiteId', '11'])
  })

  it('never loads the telemetry tracker when opted out', () => {
    setAnalyticsConfig({
      telemetry: {
        opted_in: false,
        matomo_url: 'https://matomo.silvio-und-maik.de',
        matomo_site_id: '11',
      },
    })
    initAnalytics()

    expect(paqCalls()).toEqual([])
  })

  it('trackPageView sends a sanitized custom URL, never the raw dynamic path', () => {
    setAnalyticsConfig({ matomo_url: 'https://matomo.example.com', matomo_site_id: '10' })
    initAnalytics()

    trackPageView('/acme-account/secret-board-name')

    expect(paqCalls()).toContainEqual(['setCustomUrl', '/:id/:id'])
    expect(paqCalls()).not.toContainEqual(
      expect.arrayContaining([expect.stringContaining('secret-board-name')]),
    )
  })

  it('trackEvent pushes a fixed, low-cardinality goal event', () => {
    setAnalyticsConfig({ matomo_url: 'https://matomo.example.com', matomo_site_id: '10' })
    initAnalytics()

    trackEvent('Cloud', 'board_created')

    expect(paqCalls()).toContainEqual(['trackEvent', 'Cloud', 'board_created'])
  })

  it('initAnalytics is idempotent — a second call never re-injects the tracker', () => {
    setAnalyticsConfig({ matomo_url: 'https://matomo.example.com', matomo_site_id: '10' })
    initAnalytics()
    initAnalytics()

    const scripts = document.head.querySelectorAll(
      'script[src="https://matomo.example.com/matomo.js"]',
    )
    expect(scripts).toHaveLength(1)
  })
})
