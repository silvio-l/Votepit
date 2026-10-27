import { describe, expect, it } from 'vitest'
import { brandingProAttributes } from '../branding'
import { wordingOverride } from './boardWording'

const wording = {
  en: { idea_singular: 'Suggestion', thank_you: '  ' },
  de: { idea_singular: 'Vorschlag' },
}

describe('wordingOverride', () => {
  it('returns the board text for the active language only', () => {
    expect(wordingOverride(wording, 'en', 'idea_singular')).toBe('Suggestion')
    expect(wordingOverride(wording, 'fr', 'idea_singular')).toBeUndefined()
  })

  it('treats blank text and missing wording as Votepit default', () => {
    expect(wordingOverride(wording, 'en', 'thank_you')).toBeUndefined()
    expect(wordingOverride(null, 'de', 'idea_singular')).toBeUndefined()
  })
})

describe('brandingProAttributes', () => {
  it('maps the presets onto data attributes and nothing without Pro branding', () => {
    expect(
      brandingProAttributes({
        typography: 'mono',
        shape: 'square',
        density: 'compact',
        wording: {},
        header_links: [],
        roadmap_hidden: false,
      }),
    ).toEqual({
      'data-board-typography': 'mono',
      'data-board-shape': 'square',
      'data-board-density': 'compact',
    })
    expect(brandingProAttributes(null)).toEqual({})
  })
})
