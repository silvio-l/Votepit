import { accountPath } from '../lib/accountContext'
import { boardCacheKeys, useBoardThemeMode } from '../lib/theme'

/**
 * Applies a board's display mode (P-26) while the board's page is mounted, and remembers it per board
 * for the next visit's pre-paint script (public/theme-init.js), so the first paint already has the
 * board's scheme. Renders nothing; mounted inside the page once the board is loaded, so the mode is known.
 */
export function BoardThemeMode({
  mode,
  boardSlug,
}: {
  mode: string | null | undefined
  boardSlug: string
}) {
  const keys = boardCacheKeys(
    accountPath(`/${boardSlug}`),
    window.location.pathname,
    accountPath('/'),
  )
  useBoardThemeMode(mode, keys)
  return null
}
