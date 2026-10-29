import type { BrandingPro, WordingField } from '../branding'
import type { Language } from './context'

/**
 * The board owner's text for `field` in `language` (Pro, migration 0093), or
 * undefined for Votepit's own text. A locale the owner did not customise keeps
 * Votepit's text, so an override never leaks into another language.
 */
export function wordingOverride(
  wording: BrandingPro['wording'] | null | undefined,
  language: Language,
  field: WordingField,
): string | undefined {
  const text = wording?.[language]?.[field]
  return text !== undefined && text.trim() !== '' ? text : undefined
}
