import { appExtensions } from '@votepit/app-extensions'
import type { ReactNode } from 'react'
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

export type Language = 'de' | 'en'

const STORAGE_KEY = 'vp_lang'
const DEFAULT_LANGUAGE: Language = 'de'

const deModules = import.meta.glob('./dictionaries/*.de.ts', { eager: true }) as Record<
  string,
  { default: Record<string, string> }
>
const enModules = import.meta.glob('./dictionaries/*.en.ts', { eager: true }) as Record<
  string,
  { default: Record<string, string> }
>

function buildCatalog(
  modules: Record<string, { default: Record<string, string> }>,
  suffix: string,
  language: Language,
) {
  const catalog: Record<string, Record<string, string>> = {}
  for (const [path, mod] of Object.entries(modules)) {
    const namespace = path.replace('./dictionaries/', '').replace(suffix, '')
    catalog[namespace] = mod.default
  }
  // SPA extensions contribute their own namespaces (and may override keys).
  for (const [namespace, byLanguage] of Object.entries(appExtensions.dictionaries)) {
    catalog[namespace] = { ...catalog[namespace], ...byLanguage[language] }
  }
  return catalog
}

const catalogs: Record<Language, Record<string, Record<string, string>>> = {
  de: buildCatalog(deModules, '.de.ts', 'de'),
  en: buildCatalog(enModules, '.en.ts', 'en'),
}

function readStoredLanguage(): Language | null {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    return value === 'de' || value === 'en' ? value : null
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

function detectInitialLanguage(): Language {
  const stored = readStoredLanguage()
  if (stored) return stored
  return DEFAULT_LANGUAGE
}

interface I18nContextValue {
  language: Language
  setLanguage: (lang: Language) => void
  t: (namespace: string, key: string, vars?: Record<string, string | number>) => string
}

const I18nContext = createContext<I18nContextValue | null>(null)

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
    const value = catalogs[DEFAULT_LANGUAGE]?.[namespace]?.[key]
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

  useEffect(() => {
    document.documentElement.lang = language
  }, [language])

  const setLanguage = useCallback((lang: Language) => {
    setLanguageState(lang)
    writeStoredLanguage(lang)
  }, [])

  const t = useCallback(
    (namespace: string, key: string, vars?: Record<string, string | number>): string => {
      const value =
        catalogs[language]?.[namespace]?.[key] ?? catalogs[DEFAULT_LANGUAGE]?.[namespace]?.[key]
      if (value === undefined) return `${namespace}.${key}`
      return interpolate(value, vars)
    },
    [language],
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
