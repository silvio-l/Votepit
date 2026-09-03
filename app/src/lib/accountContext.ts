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
