/**
 * Unit tests for the client-side referral-ref pass-through (social-features
 * ticket 01): the `ref` query param from votepit.com/r/<account-slug> is
 * captured on /signup, carried across the magic-link round trip in
 * sessionStorage, and consumed exactly once on /signup/account.
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { captureReferralRef, consumeReferralRef } from './referral'

const STORAGE_KEY = 'vp_referral_ref'

describe('referral', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  describe('captureReferralRef', () => {
    it('stores the ref query param', () => {
      captureReferralRef('?ref=acme-referrer')
      expect(sessionStorage.getItem(STORAGE_KEY)).toBe('acme-referrer')
    })

    it('ignores a missing ref param', () => {
      captureReferralRef('?foo=bar')
      expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
    })

    it('ignores an empty ref param', () => {
      captureReferralRef('?ref=')
      expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
    })

    it('does not overwrite an already-stored ref with a missing one', () => {
      captureReferralRef('?ref=acme-referrer')
      captureReferralRef('')
      expect(sessionStorage.getItem(STORAGE_KEY)).toBe('acme-referrer')
    })
  })

  describe('consumeReferralRef', () => {
    it('returns null when nothing was stored', () => {
      expect(consumeReferralRef()).toBeNull()
    })

    it('returns the stored ref and clears it (one-shot)', () => {
      captureReferralRef('?ref=acme-referrer')
      expect(consumeReferralRef()).toBe('acme-referrer')
      expect(consumeReferralRef()).toBeNull()
    })
  })
})
