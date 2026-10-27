import { useEffect, useSyncExternalStore } from 'react'

/**
 * Colour scheme of the app (P-26, dark mode).
 *
 * Three inputs decide the scheme on every page:
 * - the viewer's choice (`system` | `light` | `dark`), kept per viewer in localStorage;
 * - the board's display mode (`light` | `dark`, or null for "System"), set while a board page is mounted;
 * - the system preference (`prefers-color-scheme`).
 *
 * The board mode wins when it is explicit; otherwise the viewer's choice applies. The result is written to
 * `<html data-theme>` (absent = follow the system preference through the DS media query). The document element
 * is the only place that can switch every `:root` alias, see packages/ui/DESIGN.md "Dark theme".
 */

export type ThemePref = 'system' | 'light' | 'dark'
export type ResolvedTheme = 'light' | 'dark'

const THEME_STORAGE_KEY = 'vp_theme'
/**
 * Board display modes last seen per board page, so the pre-paint script (public/theme-init.js) can apply
 * a board's mode before the first paint. A JSON map from cache key to "light" | "dark"; a board on
 * "System" has no entry. Keys: the board's base path (`/account/board` cloud, `/board` self-host), or
 * `<root>` + the account root for the default board opened at its root URL. See `boardCacheKeys`.
 */
const BOARD_CACHE_KEY = 'vp_board_theme'
const ROOT_PREFIX = '<root>'
const PREFS: readonly ThemePref[] = ['system', 'light', 'dark']

interface ThemeState {
  pref: ThemePref
  /** Board display mode while a board page is mounted; null = follow the viewer. */
  board: ResolvedTheme | null
  systemDark: boolean
}

function readStoredPref(): ThemePref {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY)
    return PREFS.includes(value as ThemePref) ? (value as ThemePref) : 'system'
  } catch {
    return 'system'
  }
}

function writeStoredPref(pref: ThemePref): void {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, pref)
  } catch {
    // Storage blocked: the choice lasts for this page view only.
  }
}

type BoardModeCache = Record<string, ResolvedTheme>

function readBoardCache(): BoardModeCache {
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(BOARD_CACHE_KEY) ?? 'null')
    if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return {}
    const entries: BoardModeCache = {}
    for (const [key, value] of Object.entries(parsed)) {
      if (value === 'light' || value === 'dark') entries[key] = value
    }
    return entries
  } catch {
    return {}
  }
}

function writeBoardCache(cache: BoardModeCache): void {
  try {
    window.localStorage.setItem(BOARD_CACHE_KEY, JSON.stringify(cache))
  } catch {
    // Storage blocked: the board mode is applied after load, as without the cache.
  }
}

/**
 * Cache keys a board page is stored under, for one board. `boardBase` is the board's base path
 * (`accountPath('/' + slug)`). The default board at the account root (`/account` in cloud, `/` self-host)
 * also gets a root entry, so the pre-paint lookup of a root URL finds it without matching admin pages.
 */
export function boardCacheKeys(boardBase: string, pathname: string, accountRoot: string): string[] {
  return pathname === accountRoot ? [boardBase, ROOT_PREFIX + accountRoot] : [boardBase]
}

/** Keys to look up for a URL, most specific first (the same rule as public/theme-init.js). */
export function boardCacheLookupKeys(pathname: string): string[] {
  const segments = pathname.split('/').filter(Boolean)
  if (segments.length === 0) return [ROOT_PREFIX + '/']
  if (segments.length === 1) return [ROOT_PREFIX + pathname, `/${segments[0]}`]
  return [`/${segments[0]}/${segments[1]}`, `/${segments[0]}`]
}

function cachedBoardMode(pathname: string): ResolvedTheme | null {
  const cache = readBoardCache()
  for (const key of boardCacheLookupKeys(pathname)) {
    const mode = cache[key]
    if (mode !== undefined) return mode
  }
  return null
}

function systemPrefersDark(): boolean {
  try {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
  } catch {
    return false
  }
}

let state: ThemeState = {
  pref: readStoredPref(),
  board: cachedBoardMode(window.location.pathname),
  systemDark: systemPrefersDark(),
}
const listeners = new Set<() => void>()

/** The scheme a viewer would see right now, for code that computes colours (board accent). */
export function resolveTheme(s: ThemeState = state): ResolvedTheme {
  if (s.board !== null) return s.board
  if (s.pref === 'system') return s.systemDark ? 'dark' : 'light'
  return s.pref
}

function applyToDocument(): void {
  const root = document.documentElement
  const explicit = state.board ?? (state.pref === 'system' ? null : state.pref)
  if (explicit === null) delete root.dataset.theme
  else root.dataset.theme = explicit
}

function update(next: ThemeState): void {
  state = next
  applyToDocument()
  for (const listener of listeners) listener()
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

try {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
    update({ ...state, systemDark: event.matches })
  })
} catch {
  // No media query support: the system preference stays at its load-time value.
}
applyToDocument()

/** Stores the viewer's choice and applies it immediately. */
export function setThemePref(pref: ThemePref): void {
  writeStoredPref(pref)
  update({ ...state, pref })
}

/** Current viewer choice and the resolved scheme, re-rendered on every change. */
export function useThemeState(): { pref: ThemePref; resolved: ResolvedTheme } {
  const snapshot = useSyncExternalStore(
    subscribe,
    () => state,
    () => state,
  )
  return { pref: snapshot.pref, resolved: resolveTheme(snapshot) }
}

/** The scheme in effect on this page (board mode, else viewer choice, else system). */
export function useResolvedTheme(): ResolvedTheme {
  return useThemeState().resolved
}

/**
 * Applies a board's display mode while the board page is mounted. `mode` null or `system`
 * leaves the viewer's choice in charge. Restores the viewer's choice on unmount.
 * `cacheKeys` (see boardCacheKeys) store the mode for the next pre-paint, or clear it for "system".
 */
export function useBoardThemeMode(
  mode: string | null | undefined,
  cacheKeys: readonly string[] = [],
): void {
  const board: ResolvedTheme | null = mode === 'light' || mode === 'dark' ? mode : null
  const keyList = cacheKeys.join('\n')
  useEffect(() => {
    const keys = keyList === '' ? [] : keyList.split('\n')
    if (keys.length > 0) {
      const cache = readBoardCache()
      for (const key of keys) {
        if (board === null) delete cache[key]
        else cache[key] = board
      }
      writeBoardCache(cache)
    }
    update({ ...state, board })
    return () => {
      update({ ...state, board: null })
    }
  }, [board, keyList])
}
