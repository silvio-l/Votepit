/**
 * The widget's own tiny translator: it imports only the `widget` namespace
 * (all six languages, a few hundred bytes each) instead of the SPA's full
 * catalog, keeping the embed bundle small. Language follows the visitor's
 * browser; the SPA's language picker/storage is not involved.
 */

import de from '../lib/i18n/dictionaries/widget.de'
import en from '../lib/i18n/dictionaries/widget.en'
import es from '../lib/i18n/dictionaries/widget.es'
import fr from '../lib/i18n/dictionaries/widget.fr'
import it from '../lib/i18n/dictionaries/widget.it'
import nl from '../lib/i18n/dictionaries/widget.nl'

type WidgetKey = keyof typeof de
const DICTIONARIES = { de, en, fr, es, nl, it } as const
type WidgetLanguage = keyof typeof DICTIONARIES

export type WidgetT = (key: WidgetKey, vars?: Record<string, string | number>) => string

export function detectWidgetLanguage(preferred: readonly string[]): WidgetLanguage {
  for (const tag of preferred) {
    const base = tag.toLowerCase().split('-')[0]
    if (Object.hasOwn(DICTIONARIES, base)) return base as WidgetLanguage
  }
  return 'en'
}

export function widgetTranslator(language: WidgetLanguage): WidgetT {
  const dict: Record<WidgetKey, string> = DICTIONARIES[language]
  return (key, vars) =>
    dict[key].replace(/\{(\w+)\}/g, (match, name: string) =>
      vars && Object.hasOwn(vars, name) ? String(vars[name]) : match,
    )
}
