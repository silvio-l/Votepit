/**
 * Cloud multi-tenant routing (cloud path routing, SPA half).
 *
 * Mirrors the backend's `$accountPrefix` (core/src/Http/AppFactory.php):
 * in cloud mode, every board-/admin-scoped path carries a leading
 * `/{accountSlug}` segment; in self-host mode (default), it stays empty.
 *
 * Module-level state (same pattern as `cachedCsrfToken` in api.ts) so
 * api.ts — which is not a React module — can read the current account
 * slug without threading it through every function signature. The router
 * layout route sets it synchronously during render (not in a useEffect)
 * before its child route renders, so any child-route effect that calls
 * an api.ts function on mount already sees the correct prefix.
 */

let currentAccountSlug: string | null = null

export function setAccountSlug(slug: string | null): void {
  currentAccountSlug = slug
}

export function getAccountSlug(): string | null {
  return currentAccountSlug
}

/**
 * Config::routingMode itself (not the account slug currently in the URL) —
 * set once from AppRoutes's bootstrap() call, same module-level-state
 * pattern as currentAccountSlug above and edition.ts's currentEdition.
 * Needed by pages that must build a link to an account NOT reflected in the
 * current URL/route params (e.g. InviteAcceptPage linking to the account
 * the invite belongs to) — unlike edition.ts, this always reflects the
 * server's actual tenancy mode, never the product_edition override.
 */
let currentRoutingMode: 'self-host' | 'cloud' | null = null

export function setRoutingMode(mode: 'self-host' | 'cloud'): void {
  currentRoutingMode = mode
}

export function getRoutingMode(): 'self-host' | 'cloud' | null {
  return currentRoutingMode
}

/** `/{accountSlug}` in cloud mode, `''` in self-host mode. */
export function getAccountPrefix(): string {
  return currentAccountSlug !== null ? `/${currentAccountSlug}` : ''
}

/**
 * Prefixes an absolute path (leading `/`) with the current account
 * segment, if any. Used both for api.ts request URLs and for building
 * `<Link>`/`navigate()` targets, so both stay in lockstep.
 *
 * Special-cases the bare root `/`: the cloud-mode root route is
 * `/:accountSlug` (no trailing slash — mirrors the backend's `/{account}`
 * default-board route), not `/${slug}/`.
 */
export function accountPath(path: string): string {
  const prefix = getAccountPrefix()
  if (path === '/') return prefix === '' ? '/' : prefix
  return prefix + path
}

/** Absolute, shareable URL to a board's public page (for copy-to-clipboard). */
export function fullBoardUrl(boardSlug: string): string {
  return `${window.location.origin}${accountPath(`/${boardSlug}`)}`
}
