/**
 * i18n runtime: initial-language detection (stored choice → browser
 * languages → English), lazy loading of the non-base languages, the
 * language picker, and a completeness guard that every namespace exists in
 * all six languages (the `satisfies typeof de` typing only checks files that
 * exist — it cannot notice a missing file).
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LanguagePicker } from '../components/LanguagePicker'
import {
  I18nProvider,
  LANGUAGES,
  type Localized,
  pickLanguage,
  useI18n,
  useT,
} from '../lib/i18n/context'
import commonEs from '../lib/i18n/dictionaries/common.es'
import commonFr from '../lib/i18n/dictionaries/common.fr'

function Probe() {
  const { language } = useI18n()
  const t = useT('common')
  return (
    <p data-testid="probe" data-language={language}>
      {t('language.label')}
    </p>
  )
}

function mockBrowserLanguages(languages: string[]) {
  vi.spyOn(navigator, 'languages', 'get').mockReturnValue(languages)
}

function renderApp() {
  return render(
    <I18nProvider>
      <LanguagePicker />
      <Probe />
    </I18nProvider>,
  )
}

beforeEach(() => {
  localStorage.clear()
})

afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('initial language detection', () => {
  it('prefers a stored choice over the browser languages', () => {
    localStorage.setItem('vp_lang', 'de')
    mockBrowserLanguages(['fr-FR', 'fr'])
    renderApp()
    expect(screen.getByTestId('probe')).toHaveAttribute('data-language', 'de')
    expect(screen.getByTestId('probe')).toHaveTextContent('Sprache')
  })

  it('ignores an unknown stored value', () => {
    localStorage.setItem('vp_lang', 'xx')
    mockBrowserLanguages(['de-AT'])
    renderApp()
    expect(screen.getByTestId('probe')).toHaveAttribute('data-language', 'de')
  })

  it('picks the first supported browser language by primary subtag and loads it lazily', async () => {
    mockBrowserLanguages(['pl-PL', 'fr-CA', 'en'])
    renderApp()
    expect(screen.getByTestId('probe')).toHaveAttribute('data-language', 'fr')
    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent(commonFr['language.label']),
    )
    expect(document.documentElement.lang).toBe('fr')
  })

  it('falls back to English when no browser language is supported', () => {
    mockBrowserLanguages(['pl-PL', 'ja'])
    renderApp()
    expect(screen.getByTestId('probe')).toHaveAttribute('data-language', 'en')
    expect(screen.getByTestId('probe')).toHaveTextContent('Language')
  })
})

describe('LanguagePicker', () => {
  it('lists all six languages by their own names and switches + persists the choice', async () => {
    localStorage.setItem('vp_lang', 'de')
    renderApp()

    await userEvent.click(screen.getByRole('button', { name: /Sprache/ }))
    const items = screen.getAllByRole('menuitemradio')
    expect(items.map((i) => i.textContent)).toEqual([
      'Deutsch',
      'English',
      'Français',
      'Español',
      'Nederlands',
      'Italiano',
    ])
    expect(screen.getByRole('menuitemradio', { name: 'Deutsch' })).toHaveAttribute(
      'aria-checked',
      'true',
    )

    await userEvent.click(screen.getByRole('menuitemradio', { name: 'Español' }))

    await waitFor(() =>
      expect(screen.getByTestId('probe')).toHaveTextContent(commonEs['language.label']),
    )
    expect(localStorage.getItem('vp_lang')).toBe('es')
    expect(document.documentElement.lang).toBe('es')
  })
})

describe('pickLanguage', () => {
  it('returns the entry for the language, or the English one when it is missing', () => {
    const label: Localized<string> = { de: 'Abrechnung', en: 'Billing', fr: 'Facturation' }
    expect(pickLanguage(label, 'fr')).toBe('Facturation')
    expect(pickLanguage(label, 'de')).toBe('Abrechnung')
    expect(pickLanguage(label, 'nl')).toBe('Billing')
  })
})

describe('dictionary completeness', () => {
  const dictionaryModules = import.meta.glob('../lib/i18n/dictionaries/*.ts', {
    eager: true,
  }) as Record<string, { default: Record<string, string> }>
  const files = Object.keys(dictionaryModules)
  const namespaces = new Set(
    files.map((f) => f.replace(/^.*\/dictionaries\//, '').replace(/\.[a-z]{2}\.ts$/, '')),
  )

  it.each([...LANGUAGES])('every namespace has a %s dictionary', (language) => {
    const missing = [...namespaces].filter(
      (ns) => !files.some((f) => f.endsWith(`/dictionaries/${ns}.${language}.ts`)),
    )
    expect(missing).toEqual([])
  })

  // Empty strings are allowed (sentence fragments such as `doneBodyAfterPath`
  // are intentionally empty in some locales), so only key sets and value types
  // are compared.
  it.each([...namespaces])('%s has the same keys in every locale, with string values', (ns) => {
    const reference = Object.keys(
      dictionaryModules[`../lib/i18n/dictionaries/${ns}.en.ts`].default,
    ).sort()
    for (const language of LANGUAGES) {
      const dictionary =
        dictionaryModules[`../lib/i18n/dictionaries/${ns}.${language}.ts`]?.default ?? {}
      const keys = Object.keys(dictionary).sort()
      expect(keys, `${ns}.${language} keys`).toEqual(reference)
      expect(
        keys.filter((k) => typeof dictionary[k] !== 'string'),
        `${ns}.${language} non-string values`,
      ).toEqual([])
    }
  })

  // Every translation must carry the same placeholders as the English source
  // ({name}, {count}, …): a dropped or renamed one renders as literal braces
  // or loses a value. Plural forms are not modelled (no ICU), so a count is
  // always an explicit `{count}` placeholder in the sentence.
  const placeholdersOf = (text: string) => [...new Set(text.match(/\{\w+\}/g) ?? [])].sort()

  it.each([...namespaces])('%s has the same placeholders as English in every locale', (ns) => {
    const reference = dictionaryModules[`../lib/i18n/dictionaries/${ns}.en.ts`].default
    const mismatches: string[] = []
    for (const language of LANGUAGES) {
      const dictionary =
        dictionaryModules[`../lib/i18n/dictionaries/${ns}.${language}.ts`]?.default ?? {}
      for (const [key, english] of Object.entries(reference)) {
        const translated = dictionary[key] ?? ''
        const expected = placeholdersOf(english).join(',')
        const actual = placeholdersOf(translated).join(',')
        if (expected !== actual)
          mismatches.push(`${ns}.${language} ${key}: [${expected}] vs [${actual}]`)
      }
    }
    expect(mismatches).toEqual([])
  })

  // An empty string is only legitimate where the English source is empty too
  // (sentence fragments that the locale does not need).
  it.each([...namespaces])('%s has no empty translations where English is filled', (ns) => {
    const reference = dictionaryModules[`../lib/i18n/dictionaries/${ns}.en.ts`].default
    const empty: string[] = []
    for (const language of LANGUAGES) {
      const dictionary =
        dictionaryModules[`../lib/i18n/dictionaries/${ns}.${language}.ts`]?.default ?? {}
      for (const [key, english] of Object.entries(reference)) {
        if (english !== '' && (dictionary[key] ?? '').trim() === '')
          empty.push(`${ns}.${language} ${key}`)
      }
    }
    expect(empty).toEqual([])
  })

  // Non-English copy that is byte-identical to the English source is almost
  // always an untranslated string. The list below holds the legitimate
  // cognates, technical terms and brand names that stay the same in the
  // target language. Adding a value here is a reviewed decision.
  const IDENTICAL_TO_ENGLISH_ALLOWED = new Set<string>([
    'Votepit',
    // Brand badge, kept in English in every locale (owner wording).
    'Powered by Votepit',
    'MCP',
    'API',
    'Webhook',
    'SMTP',
    'Slug',
    'Cloud',
    'Community',
    'Lite',
    'Pro',
    'Free',
    'GitHub',
    'Linear',
    'OAuth',
    'YouTube',
    'X (Twitter)',
    'Gmail',
    'Outlook / Microsoft 365',
    'Slack & Discord',
    'Claude Code (MCP)',
    'Claude Desktop',
    'Cursor / VS Code',
    'Board',
    'Boards',
    // Worklist filter group label: the German and Dutch word is the same.
    'Filter',
    'Boards ({count})',
    'Roadmap',
    'Branding',
    'Widget',
    'Feedback',
    'Logo',
    'Mail',
    'Desktop',
    'Tablet',
    'Port',
    'Password',
    'Token',
    'Status',
    'Account',
    'Account & board',
    'Account / Slug',
    'Accounts',
    'Accounts ({count})',
    'Admin',
    'Owner',
    'Moderator',
    'Moderation',
    'Operator',
    'Support',
    'Team',
    'Plan',
    'Privacy',
    'Name',
    'Label',
    'Code',
    'Contact',
    'Message',
    'Conversation',
    'Description',
    'Actions',
    'Filters',
    'Gantt',
    'General',
    'Idea',
    'idea',
    'Ideas',
    'ideas',
    'Ideas: {count}',
    'Inbox',
    'Issue tracker',
    'Linear & GitHub',
    'Magic link',
    'Menu',
    'Net',
    'Normal',
    'Notifications',
    'Notifications ({count})',
    'open',
    'Open',
    'Page',
    'Pages',
    'Personal',
    'Platform',
    'Poster',
    'Repository',
    'Sections',
    'Source',
    'SSL/TLS (port 465)',
    'Start',
    'System',
    'Tags',
    'Tags (optional)',
    'Tickets',
    'Tickets ({count})',
    'Tokens ({count})',
    'Top',
    'Votes',
    'vote',
    'votes',
    'Voter',
    'Website',
    'Compact',
    'Deadlines',
    'Bokeh',
    'Powered by Votepit',
    '{count} idea',
    '{count} ideas',
    '{count} votes',
    // French: "vote" is the same word (singular count key).
    '{count} vote',
    '{name} via Votepit',
    'Person #{id}',
    'Person #{id} ({username})',
    'contact@your-domain.com',
    'name@example.com',
    'XXXX-XXXX',
    // Colour palette names that are the same word in these languages (Sand, Indigo, Coral,
    // Glacier, Graphite, Citrus).
    'Sand',
    'Indigo',
    'Coral',
    'Glacier',
    'Graphite',
    'Citrus',
  ])

  it.each([...namespaces])('%s has no untranslated English copy outside the allowlist', (ns) => {
    const reference = dictionaryModules[`../lib/i18n/dictionaries/${ns}.en.ts`].default
    const identical: string[] = []
    for (const language of LANGUAGES) {
      if (language === 'en') continue
      const dictionary =
        dictionaryModules[`../lib/i18n/dictionaries/${ns}.${language}.ts`]?.default ?? {}
      for (const [key, english] of Object.entries(reference)) {
        const translated = dictionary[key]
        if (
          english !== '' &&
          translated === english &&
          !IDENTICAL_TO_ENGLISH_ALLOWED.has(english) &&
          /[a-zA-Z]{3,}/.test(english)
        ) {
          identical.push(`${ns}.${language} ${key}: ${english}`)
        }
      }
    }
    expect(identical).toEqual([])
  })
})
