/**
 * Unit tests for the module-level account-context state (cloud multi-tenant
 * routing, SPA half). No React involved here — App.tsx's ScopedLayout/
 * GlobalLayout call setAccountSlug() directly, api.ts calls accountPath().
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { accountPath, getAccountPrefix, getAccountSlug, setAccountSlug } from './accountContext'

describe('accountContext', () => {
  beforeEach(() => {
    setAccountSlug(null)
  })

  it('defaults to no account (self-host mode)', () => {
    expect(getAccountSlug()).toBeNull()
    expect(getAccountPrefix()).toBe('')
  })

  it('stores and returns the current account slug', () => {
    setAccountSlug('acme')
    expect(getAccountSlug()).toBe('acme')
    expect(getAccountPrefix()).toBe('/acme')
  })

  it('resets back to null', () => {
    setAccountSlug('acme')
    setAccountSlug(null)
    expect(getAccountSlug()).toBeNull()
    expect(getAccountPrefix()).toBe('')
  })

  describe('accountPath()', () => {
    it('leaves paths unprefixed with no account set (self-host)', () => {
      expect(accountPath('/demo')).toBe('/demo')
      expect(accountPath('/')).toBe('/')
    })

    it('prefixes paths with the current account slug', () => {
      setAccountSlug('acme')
      expect(accountPath('/demo')).toBe('/acme/demo')
    })

    it('collapses the root path to just the account prefix', () => {
      setAccountSlug('acme')
      expect(accountPath('/')).toBe('/acme')
    })
  })
})
