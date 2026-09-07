import type { Language } from './i18n/context'

const LOCALES: Record<Language, string> = { de: 'de-DE', en: 'en-GB' }

function parse(iso: string): Date {
  // MySQL "YYYY-MM-DD HH:MM:SS" and ISO-8601 both arrive here.
  return new Date(iso.includes('T') ? iso : iso.replace(' ', 'T'))
}

/** Localised date, e.g. "2. Sep. 2026" / "2 Sept 2026". */
export function formatDate(iso: string, language: Language): string {
  const d = parse(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(LOCALES[language], {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

/** Localised date + time, minutes precision. */
export function formatDateTime(iso: string, language: Language): string {
  const d = parse(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(LOCALES[language], {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
