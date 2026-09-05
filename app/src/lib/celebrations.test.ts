/**
 * Unit tests for the per-browser "already celebrated" bookkeeping backing
 * the Celebration component (referral-reward + board-milestone moments).
 */

import { beforeEach, describe, expect, it } from 'vitest'
import { crossedThreshold, hasCelebrated, isNewCountIncrease, markCelebrated } from './celebrations'

describe('celebrations', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  describe('hasCelebrated / markCelebrated', () => {
    it('is false until marked', () => {
      expect(hasCelebrated('referral-reward')).toBe(false)
      markCelebrated('referral-reward')
      expect(hasCelebrated('referral-reward')).toBe(true)
    })

    it('keeps keys independent', () => {
      markCelebrated('milestone:42:first-idea')
      expect(hasCelebrated('milestone:42:first-idea')).toBe(true)
      expect(hasCelebrated('milestone:42:ten-ideas')).toBe(false)
    })

    it('marking twice is a no-op', () => {
      markCelebrated('referral-reward')
      markCelebrated('referral-reward')
      expect(hasCelebrated('referral-reward')).toBe(true)
    })
  })

  describe('isNewCountIncrease', () => {
    it('does not report an increase on the first observation', () => {
      expect(isNewCountIncrease('referral-qualified', 2)).toBe(false)
    })

    it('reports an increase once the count rises above the last-seen value', () => {
      isNewCountIncrease('referral-qualified', 1)
      expect(isNewCountIncrease('referral-qualified', 2)).toBe(true)
    })

    it('does not report an increase when the count stays the same', () => {
      isNewCountIncrease('referral-qualified', 2)
      expect(isNewCountIncrease('referral-qualified', 2)).toBe(false)
    })

    it('does not report an increase when the count drops', () => {
      isNewCountIncrease('referral-qualified', 3)
      expect(isNewCountIncrease('referral-qualified', 1)).toBe(false)
    })
  })

  describe('crossedThreshold', () => {
    it('does not report a crossing on the first observation, even above the threshold', () => {
      expect(crossedThreshold('board:acme:total-ideas:ten', 10, 12)).toBe(false)
    })

    it('reports a crossing once the count rises from below the threshold to at/above it', () => {
      crossedThreshold('board:acme:total-ideas:ten', 10, 8)
      expect(crossedThreshold('board:acme:total-ideas:ten', 10, 10)).toBe(true)
    })

    it('does not report a crossing while staying below the threshold', () => {
      crossedThreshold('board:acme:total-ideas:ten', 10, 3)
      expect(crossedThreshold('board:acme:total-ideas:ten', 10, 7)).toBe(false)
    })

    it('does not re-report once already above the threshold', () => {
      crossedThreshold('board:acme:total-ideas:ten', 10, 11)
      expect(crossedThreshold('board:acme:total-ideas:ten', 10, 12)).toBe(false)
    })
  })
})
