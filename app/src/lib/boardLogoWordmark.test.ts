/**
 * Wordmark helpers (BR-LOGO2): the topbar uses the wordmark when the server
 * delivers one, and a light plate only when its ink is too faint on the bar.
 */

import { describe, expect, it } from 'vitest'
import { boardWordmarkPlated, boardWordmarkSrc } from './boardLogo'

const ID = 'a'.repeat(32)

describe('boardWordmarkSrc', () => {
  it('returns the gated public URL when the board has a wordmark', () => {
    expect(boardWordmarkSrc('demo', { wordmark_public_id: ID })).toMatch(`/demo/assets/${ID}`)
  })

  it('is undefined without a wordmark or a slug', () => {
    expect(boardWordmarkSrc('demo', { wordmark_public_id: null })).toBeUndefined()
    expect(boardWordmarkSrc(null, { wordmark_public_id: ID })).toBeUndefined()
  })
})

describe('boardWordmarkPlated', () => {
  it('plates a dark wordmark on the default forest bar', () => {
    expect(
      boardWordmarkPlated({ wordmark_public_id: ID, wordmark_ink: 0.01, secondary_color: null }),
    ).toBe(true)
  })

  it('does not plate a light wordmark on the forest bar', () => {
    expect(
      boardWordmarkPlated({ wordmark_public_id: ID, wordmark_ink: 0.9, secondary_color: null }),
    ).toBe(false)
  })

  it('is false without a wordmark or without metadata', () => {
    expect(boardWordmarkPlated({ wordmark_public_id: null, wordmark_ink: 0.01 })).toBe(false)
    expect(boardWordmarkPlated({ wordmark_public_id: ID, wordmark_ink: null })).toBe(false)
  })
})
