/**
 * The owner's For/Against words (Pro wording, per locale) reach the vote
 * controls and the vote summary, with Votepit's own text as the per-locale
 * fallback. Votepit's voting rules are untouched: only the words change.
 */

import { render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { BrandingPro } from '../lib/branding'
import { I18nProvider } from '../lib/i18n/context'
import { useIdeaCardLabels } from './useIdeaCardLabels'

function Probe({ pro }: { pro?: BrandingPro | null }) {
  const { voteLabels, summaryLabels } = useIdeaCardLabels(null, pro)
  const seen = {
    up: voteLabels.up,
    down: voteLabels.down,
    summaryUp: summaryLabels.up,
    summaryDown: summaryLabels.down,
  }
  return <p data-testid="probe">{JSON.stringify(seen)}</p>
}

function renderProbe(pro?: BrandingPro | null) {
  render(
    <I18nProvider>
      <Probe pro={pro} />
    </I18nProvider>,
  )
  return JSON.parse(screen.getByTestId('probe').textContent ?? '{}') as {
    up: { verb: string; label: string }
    down: { verb: string; label: string }
    summaryUp: string
    summaryDown: string
  }
}

function branding(wording: BrandingPro['wording']): BrandingPro {
  return {
    typography: 'default',
    shape: 'rounded',
    density: 'comfortable',
    wording,
    header_links: [],
    roadmap_hidden: false,
  }
}

describe('useIdeaCardLabels — owner vote words', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.spyOn(navigator, 'languages', 'get').mockReturnValue(['en-US'])
  })
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('keeps Votepit words without branding', () => {
    const seen = renderProbe(null)
    expect(seen.up.label).toBe('For')
    expect(seen.down.label).toBe('Against')
    expect(seen.summaryUp).toBe('For')
    expect(seen.summaryDown).toBe('Against')
  })

  it('uses the owner words for the active locale in controls, names and summary', () => {
    const seen = renderProbe(branding({ en: { vote_for: 'Support', vote_against: 'Concern' } }))
    expect(seen.up).toEqual({ verb: 'Support', label: 'Support' })
    expect(seen.down).toEqual({ verb: 'Concern', label: 'Concern' })
    expect(seen.summaryUp).toBe('Support')
    expect(seen.summaryDown).toBe('Concern')
  })

  it('falls back to Votepit words for a locale the owner did not customise', () => {
    const seen = renderProbe(branding({ de: { vote_for: 'Unterstützen' } }))
    expect(seen.up.label).toBe('For')
    expect(seen.down.label).toBe('Against')
  })

  it('treats a blank owner word as Votepit default for that word only', () => {
    const seen = renderProbe(branding({ en: { vote_for: 'Support', vote_against: '   ' } }))
    expect(seen.up.label).toBe('Support')
    expect(seen.down.label).toBe('Against')
  })
})
