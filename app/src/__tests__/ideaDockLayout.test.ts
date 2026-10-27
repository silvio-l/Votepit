/**
 * Phone idea-detail dock: layout contract (G-03 / G-08 leftovers).
 *
 * jsdom has no layout engine, so the real geometry (no overlap at 360/390/430,
 * nothing under the dock at the end of the page) is verified in Chrome. This
 * guard pins the CSS values that the measurements depend on, so a later edit
 * to mobile.css cannot silently bring back the overlap:
 *
 * - the dock sits above the lifted tab-bar action (tab bar 58 px, action lifted
 *   13 px, so the dock bottom offset must be at least 71 px);
 * - the vote fields fill their dock slot instead of keeping the 160 px desktop
 *   minimum, which made them overlap their neighbours;
 * - the page keeps enough bottom padding for the dock plus its offset;
 * - anchor and focus targets scroll to a point above the dock.
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const testDir = dirname(fileURLToPath(import.meta.url))
const mobileCss = readFileSync(resolve(testDir, '../../../packages/ui/src/ds/mobile.css'), 'utf8')

/** Returns the bodies of every rule whose selector matches exactly. */
function ruleBodies(selector: string): string[] {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const matches = [...mobileCss.matchAll(new RegExp(`${escaped}\\s*\\{([^}]*)\\}`, 'g'))]
  if (matches.length === 0) throw new Error(`rule not found: ${selector}`)
  return matches.map((m) => m[1] ?? '')
}

/** The body that declares `prop`; fails if no rule for the selector does. */
function declaring(selector: string, prop: RegExp): string {
  const body = ruleBodies(selector).find((b) => prop.test(b))
  if (body === undefined) throw new Error(`no rule for ${selector} sets ${prop}`)
  return body
}

describe('idea detail dock (phone layout contract)', () => {
  it('lifts the dock clear of the tab bar and its central action', () => {
    const body = declaring('.ds-idea-dock', /bottom:/)
    const offset = /bottom:\s*calc\((\d+)px/.exec(body)
    expect(offset, 'dock bottom offset').not.toBeNull()
    expect(Number(offset?.[1])).toBeGreaterThanOrEqual(58 + 13)
  })

  it('lets the vote fields shrink to their dock slot', () => {
    const body = declaring('.ds-idea-dock .ds-vote-control', /width:/)
    expect(body).toMatch(/width:\s*100%/)
    expect(body).toMatch(/min-width:\s*0/)
  })

  it('reserves bottom padding for the dock on the detail page', () => {
    const body = declaring('.ds-phone-touch:has(.ds-idea-dock)', /padding-bottom:/)
    const padding = /padding-bottom:\s*(\d+)px/.exec(body)
    // Dock height (about 65 px) + its 78 px offset = 143 px. The body adds
    // 56 px of its own (tab bar padding), so this block covers the remaining 87 px.
    expect(Number(padding?.[1])).toBeGreaterThanOrEqual(65 + 78 - 56)
  })

  it('scrolls anchor and focus targets above the dock', () => {
    const body = declaring(':root:has(.ds-idea-dock)', /scroll-padding-bottom:/)
    const padding = /scroll-padding-bottom:\s*(\d+)px/.exec(body)
    expect(Number(padding?.[1])).toBeGreaterThanOrEqual(65 + 78)
  })
})
