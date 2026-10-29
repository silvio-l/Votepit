import { appExtensions } from '@votepit/app-extensions'
import type { ReactNode } from 'react'
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

/** Every UI language, in picker order. */
export const LANGUAGES = ['de', 'en', 'fr', 'es', 'nl', 'it'] as const

export type Language = (typeof LANGUAGES)[number]

/**
 * The two languages bundled eagerly into the main chunk. Every other one is
 * fetched on demand (see `lazyCatalogs`), and anything a non-base language
 * lacks — e.g. an SPA extension that only ships de/en strings — falls back
 * to English at runtime.
 */
type BaseLanguage = 'de' | 'en'

/** A per-language value that must cover the base languages; the rest are optional. */
export type Localized<T> = Record<BaseLanguage, T> & Partial<Record<Language, T>>

/** Endonyms for the picker — a language is always listed in its own name. */
export const LANGUAGE_NAMES: Record<Language, string> = {
  de: 'Deutsch',
  en: 'English',
  fr: 'Français',
  es: 'Español',
  nl: 'Nederlands',
  it: 'Italiano',
}

const STORAGE_KEY = 'vp_lang'
/** Used outside the provider (unit tests) — the app's historical default. */
const DEFAULT_LANGUAGE: Language = 'de'
/** Detection result when no browser language is supported, and the missing-key fallback. */
const FALLBACK_LANGUAGE: Language = 'en'

type Catalog = Record<string, Record<string, string>>
type DictionaryModules = Record<string, { default: Record<string, string> }>

const deModules = import.meta.glob('./dictionaries/*.de.ts', { eager: true }) as DictionaryModules
const enModules = import.meta.glob('./dictionaries/*.en.ts', { eager: true }) as DictionaryModules

/** One chunk per non-base language (each module bundles that language's dictionaries). */
const lazyCatalogs: Record<
  Exclude<Language, BaseLanguage>,
  () => Promise<{ default: DictionaryModules }>
> = {
  fr: () => import('./lazy/fr'),
  es: () => import('./lazy/es'),
  nl: () => import('./lazy/nl'),
  it: () => import('./lazy/it'),
}

function buildCatalog(modules: DictionaryModules, language: Language): Catalog {
  const catalog: Catalog = {}
  for (const [path, mod] of Object.entries(modules)) {
    const namespace = path.replace(/^.*\/dictionaries\//, '').replace(`.${language}.ts`, '')
    catalog[namespace] = mod.default
  }
  // SPA extensions contribute their own namespaces (and may override keys).
  for (const [namespace, byLanguage] of Object.entries(appExtensions.dictionaries)) {
    const strings = byLanguage[language]
    if (strings) catalog[namespace] = { ...catalog[namespace], ...strings }
  }
  return catalog
}

const catalogs: Partial<Record<Language, Catalog>> = {
  de: buildCatalog(deModules, 'de'),
  en: buildCatalog(enModules, 'en'),
}

/** Loads a language's catalog once; resolves immediately for base/already-loaded ones. */
async function ensureCatalog(language: Language): Promise<void> {
  if (catalogs[language]) return
  const load = lazyCatalogs[language as Exclude<Language, BaseLanguage>]
  const mod = await load()
  catalogs[language] = buildCatalog(mod.default, language)
}

function isLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (LANGUAGES as readonly string[]).includes(value)
}

/**
 * Picks the entry for `language` from a per-language record that is only
 * guaranteed to carry the base languages (extension labels, FAQ fields, …),
 * falling back to English.
 */
export function pickLanguage<T>(byLanguage: Localized<T>, language: Language): T {
  return byLanguage[language] ?? byLanguage.en
}

function readStoredLanguage(): Language | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return isLanguage(value) ? value : null
  } catch {
    return null
  }
}

function writeStoredLanguage(lang: Language) {
  try {
    localStorage.setItem(STORAGE_KEY, lang)
  } catch {
    // Storage unavailable (private mode/quota) — language just resets on reload.
  }
}

/** First supported primary subtag of the browser's preferred languages ("fr-CA" → "fr"). */
function detectBrowserLanguage(): Language | null {
  const preferred =
    typeof navigator === 'undefined'
      ? []
      : navigator.languages?.length
        ? navigator.languages
        : [navigator.language]
  for (const tag of preferred) {
    const primary = tag?.toLowerCase().split('-')[0]
    if (isLanguage(primary)) return primary
  }
  return null
}

function detectInitialLanguage(): Language {
  return readStoredLanguage() ?? detectBrowserLanguage() ?? FALLBACK_LANGUAGE
}

interface I18nContextValue {
  language: Language
  setLanguage: (lang: Language) => void
  t: (namespace: string, key: string, vars?: Record<string, string | number>) => string
}

const I18nContext = createContext<I18nContextValue | null>(null)

function lookup(language: Language, namespace: string, key: string): string | undefined {
  return (
    catalogs[language]?.[namespace]?.[key] ??
    catalogs[FALLBACK_LANGUAGE]?.[namespace]?.[key] ??
    catalogs[DEFAULT_LANGUAGE]?.[namespace]?.[key]
  )
}

/**
 * Translates outside React (e.g. the API client) in the page's active
 * language — the one I18nProvider mirrors into `<html lang>` — with the same
 * en → de fallback as `t`. A lazily loaded language serves English until its
 * catalog has arrived.
 */
export function translateActive(namespace: string, key: string): string | undefined {
  const active = typeof document === 'undefined' ? null : document.documentElement.lang
  return lookup(isLanguage(active) ? active : FALLBACK_LANGUAGE, namespace, key)
}

/**
 * Fallback used when a component renders outside I18nProvider — chiefly unit
 * tests that mount a single page in isolation rather than the full App tree.
 * Production always renders inside the provider (mounted once in App.tsx),
 * so this never masks a real integration gap; it just spares every test file
 * from adding provider boilerplate unrelated to what it's actually testing.
 */
const fallbackContextValue: I18nContextValue = {
  language: DEFAULT_LANGUAGE,
  setLanguage: () => {},
  t: (namespace, key, vars) => {
    const value = lookup(DEFAULT_LANGUAGE, namespace, key)
    if (value === undefined) return `${namespace}.${key}`
    return interpolate(value, vars)
  },
}

function interpolate(template: string, vars?: Record<string, string | number>): string {
  if (!vars) return template
  return template.replace(/\{(\w+)\}/g, (match, name) =>
    Object.hasOwn(vars, name) ? String(vars[name]) : match,
  )
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [language, setLanguageState] = useState<Language>(detectInitialLanguage)
  // Bumped when a lazily loaded catalog lands, so `t` re-renders with it.
  // Until then `t` serves the English fallback (one brief frame at most).
  const [loadedLanguage, setLoadedLanguage] = useState<Language | null>(() =>
    catalogs[language] ? language : null,
  )

  useEffect(() => {
    document.documentElement.lang = language
    let cancelled = false
    ensureCatalog(language)
      .then(() => {
        if (!cancelled) setLoadedLanguage(language)
      })
      .catch(() => {
        // Chunk failed to load (offline, stale deploy) — stay on the English
        // fallback rather than breaking the page.
      })
    return () => {
      cancelled = true
    }
  }, [language])

  const setLanguage = useCallback((lang: Language) => {
    setLanguageState(lang)
    writeStoredLanguage(lang)
  }, [])

  // biome-ignore lint/correctness/useExhaustiveDependencies: loadedLanguage is a render trigger — the catalogs live in module state.
  const t = useCallback(
    (namespace: string, key: string, vars?: Record<string, string | number>): string => {
      const value = lookup(language, namespace, key)
      if (value === undefined) return `${namespace}.${key}`
      return interpolate(value, vars)
    },
    [language, loadedLanguage],
  )

  const contextValue = useMemo(() => ({ language, setLanguage, t }), [language, setLanguage, t])

  return <I18nContext.Provider value={contextValue}>{children}</I18nContext.Provider>
}

export function useI18n(): I18nContextValue {
  return useContext(I18nContext) ?? fallbackContextValue
}

/** Namespaced translator: `t('save')` instead of `t('boardsAdminPage', 'save')`. */
export function useT(namespace: string) {
  const { t } = useI18n()
  return useCallback(
    (key: string, vars?: Record<string, string | number>) => t(namespace, key, vars),
    [t, namespace],
  )
}

/**
 * Renders its subtree in another UI language, e.g. the branding editor preview while a
 * per-locale text is being edited. Without `language` the subtree keeps the page language.
 * A lazily loaded catalog serves English until it has arrived, like the page language does.
 */
export function LanguageScope({
  language,
  children,
}: {
  language?: Language
  children: ReactNode
}) {
  const parent = useI18n()
  const [loadedLanguage, setLoadedLanguage] = useState<Language | null>(() =>
    language !== undefined && catalogs[language] ? language : null,
  )

  useEffect(() => {
    if (language === undefined || catalogs[language]) return
    let cancelled = false
    ensureCatalog(language)
      .then(() => {
        if (!cancelled) setLoadedLanguage(language)
      })
      .catch(() => {
        // Chunk failed to load: stay on the English fallback.
      })
    return () => {
      cancelled = true
    }
  }, [language])

  // biome-ignore lint/correctness/useExhaustiveDependencies: loadedLanguage is a render trigger — the catalogs live in module state.
  const scoped = useMemo<I18nContextValue | null>(() => {
    if (language === undefined) return null
    return {
      language,
      setLanguage: parent.setLanguage,
      t: (namespace, key, vars) => {
        const value = lookup(language, namespace, key)
        if (value === undefined) return `${namespace}.${key}`
        return interpolate(value, vars)
      },
    }
  }, [language, parent.setLanguage, loadedLanguage])

  if (scoped === null) return children
  return <I18nContext.Provider value={scoped}>{children}</I18nContext.Provider>
}
