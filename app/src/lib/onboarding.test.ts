/**
 * Onboarding helpers: template presets, accent contrast, plan locks, checklist
 * completion and the reopen/deep-link targets used by the setup wizard.
 */

import { describe, expect, it } from 'vitest'
import type { Entitlements } from './entitlements'
import {
  ACCENT_SWATCHES,
  accentHasLowContrast,
  appearanceIsSet,
  checklistProgress,
  DEFAULT_ACCENT,
  featureLocked,
  markShareCopied,
  NEXT_STEPS,
  nextStepTarget,
  normalizeAccent,
  ONBOARDING_TEMPLATES,
  readShareCopied,
  reopenSetupTarget,
} from './onboarding'

function entitlements(allowed: boolean): Entitlements {
  return {
    current_plan: allowed ? 'pro' : 'free',
    can_change_plan: false,
    features: {
      cover_upload: { allowed, required_plan: 'lite' },
    },
  } as unknown as Entitlements
}

describe('template presets', () => {
  it('suggest a valid accent colour and an anonymous-voting default per audience', () => {
    for (const preset of Object.values(ONBOARDING_TEMPLATES)) {
      expect(normalizeAccent(preset.accent)).toBe(preset.accent)
    }
    expect(ONBOARDING_TEMPLATES.product.anonymousVoting).toBe(false)
    expect(ONBOARDING_TEMPLATES.event.anonymousVoting).toBe(true)
    expect(ONBOARDING_TEMPLATES.club.anonymousVoting).toBe(true)
    expect(ONBOARDING_TEMPLATES.team.anonymousVoting).toBe(false)
  })

  it('offers only palette swatches that are valid colours', () => {
    expect(ACCENT_SWATCHES.length).toBeGreaterThan(0)
    for (const swatch of ACCENT_SWATCHES) {
      expect(normalizeAccent(swatch.hex)).toBe(swatch.hex)
      expect(accentHasLowContrast(swatch.hex)).toBe(false)
    }
  })
})

describe('accent colour', () => {
  it('normalises a typed hex colour and rejects anything else', () => {
    expect(normalizeAccent(' #10946d ')).toBe('#10946D')
    expect(normalizeAccent('green')).toBeNull()
    expect(normalizeAccent('#12345')).toBeNull()
  })

  it('warns when white text on the accent is below 4.5:1, but accepts dark accents', () => {
    expect(accentHasLowContrast(DEFAULT_ACCENT)).toBe(true)
    expect(accentHasLowContrast('#102F28')).toBe(false)
  })
})

describe('plan locks', () => {
  it('never locks outside billing installations (Community/self-host)', () => {
    expect(featureLocked(entitlements(false), 'cover_upload', false)).toBe(false)
  })

  it('locks a feature on billing installations when the viewer is not entitled', () => {
    expect(featureLocked(entitlements(false), 'cover_upload', true)).toBe(true)
    expect(featureLocked(null, 'cover_upload', true)).toBe(true)
  })

  it('unlocks an entitled feature on billing installations', () => {
    expect(featureLocked(entitlements(true), 'cover_upload', true)).toBe(false)
  })
})

describe('checklist progress', () => {
  const base = {
    appearanceSet: false,
    shareCopied: false,
    memberCount: null,
    ideaCount: 0,
    voteCount: 0,
  }

  it('marks the board item done and everything else open for a fresh board', () => {
    const states = checklistProgress(base)
    expect(states.find((s) => s.id === 'board')?.done).toBe(true)
    expect(states.filter((s) => s.done).map((s) => s.id)).toEqual(['board'])
  })

  it('completes items from real data and treats an unknown member count as one member', () => {
    const states = checklistProgress({
      ...base,
      appearanceSet: true,
      shareCopied: true,
      memberCount: 1,
      ideaCount: 3,
      voteCount: 2,
    })
    expect(states.find((s) => s.id === 'team')?.done).toBe(false)
    expect(states.filter((s) => s.done).length).toBe(5)
    expect(checklistProgress({ ...base, memberCount: 2 }).find((s) => s.id === 'team')?.done).toBe(
      true,
    )
  })

  it('counts a board as styled once it has an accent colour or logo', () => {
    expect(appearanceIsSet(null)).toBe(false)
    expect(appearanceIsSet({ primary_color: null, logo_url: null })).toBe(false)
    expect(appearanceIsSet({ primary_color: '#102F28', logo_url: null })).toBe(true)
    expect(appearanceIsSet({ primary_color: null, logo_url: 'https://x.test/l.png' })).toBe(true)
  })

  it('remembers the copied share link per board in this browser', () => {
    localStorage.clear()
    expect(readShareCopied('alpha')).toBe(false)
    markShareCopied('alpha')
    expect(readShareCopied('alpha')).toBe(true)
    expect(readShareCopied('beta')).toBe(false)
  })
})

describe('reopen and deep-link targets', () => {
  it('reopens the wizard on the boards page, optionally for one board', () => {
    expect(reopenSetupTarget()).toBe('/admin/boards?setup=1')
    expect(reopenSetupTarget('alpha')).toBe('/admin/boards?setup=1&board=alpha')
  })

  it('points each next-step card at its anchor on the board settings page', () => {
    const appearance = NEXT_STEPS.find((s) => s.id === 'appearance')
    const members = NEXT_STEPS.find((s) => s.id === 'members')
    if (appearance === undefined || members === undefined)
      throw new Error('next-step cards missing')
    expect(nextStepTarget('alpha', appearance)).toBe('/admin/boards/alpha/design?group=colors')
    expect(nextStepTarget('alpha', members)).toBe('/admin/members')
  })
})
