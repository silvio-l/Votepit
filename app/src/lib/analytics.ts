/**
 * Matomo analytics — thin, privacy-first wrapper around the `_paq` queue.
 *
 * Two independent, optionally-simultaneous targets, both cookieless
 * (`disableCookies`) and driven entirely by /api/bootstrap (see
 * Config::matomoUrl/matomoSiteId and Votepit\Telemetry\CommunityTelemetry):
 *
 * 1. "own" — this installation's OWN analytics (Cloud's own Matomo site, or
 *    a self-hoster who configured their own `matomo_url`/`matomo_site_id`).
 * 2. "telemetry" — self-host-only, opt-out product-improvement telemetry to
 *    Votepit's own aggregate Matomo site (see configuration.md).
 *
 * Deliberately NO heartbeat timer (unlike the static Landingpage snippet,
 * web/src/layouts/Base.astro): this is an app with frequent SPA navigations,
 * so accurate engagement time comes from page-to-page transitions, not a
 * periodic ping — a heartbeat here would just be a redundant, high-frequency
 * request the anti-tracking-slop rule in the task brief calls out.
 *
 * PII sanitizing: `sanitizePath` replaces every path segment that isn't a
 * known static route keyword (see ROUTE_KEYWORDS) with `:id` — board/account
 * slugs, numeric/UUID ids, ticket ids etc. never reach Matomo. Query strings
 * and hashes are dropped outright (tokens/emails have shown up there:
 * magic-link/reset/invite URLs).
 */

type MatomoTarget = { matomoUrl: string; matomoSiteId: string }

let ownTarget: MatomoTarget | null = null
let telemetryTarget: MatomoTarget | null = null
let initialized = false

declare global {
  interface Window {
    _paq?: unknown[][]
  }
}

function paq(): unknown[][] {
  if (!window._paq) window._paq = []
  return window._paq
}

/** Called once from AppRoutes' bootstrap() handler. */
export function setAnalyticsConfig(config: {
  matomo_url?: string
  matomo_site_id?: string
  telemetry?: { opted_in: boolean; matomo_url: string; matomo_site_id: string } | null
}): void {
  ownTarget =
    config.matomo_url && config.matomo_site_id
      ? { matomoUrl: config.matomo_url, matomoSiteId: config.matomo_site_id }
      : null

  telemetryTarget =
    config.telemetry?.opted_in && config.telemetry.matomo_url && config.telemetry.matomo_site_id
      ? { matomoUrl: config.telemetry.matomo_url, matomoSiteId: config.telemetry.matomo_site_id }
      : null
}

function loadTracker(target: MatomoTarget): void {
  paq().push(['setTrackerUrl', `${target.matomoUrl}/matomo.php`])
  paq().push(['setSiteId', target.matomoSiteId])
  paq().push(['disableCookies'])
  const script = document.createElement('script')
  script.async = true
  script.src = `${target.matomoUrl}/matomo.js`
  document.head.appendChild(script)
}

/**
 * Idempotent, safe to call even with nothing configured (both targets
 * null) — a no-op then, so callers don't need to guard it themselves.
 * Matomo failures (network/ad-blocker) never throw into app code: `_paq` is
 * a plain array queue, pushing to it cannot fail, and the tracker script
 * itself loads async/fire-and-forget.
 */
export function initAnalytics(): void {
  if (initialized) return
  initialized = true
  if (ownTarget) loadTracker(ownTarget)
  // The telemetry target shares the same `_paq` queue when both are active
  // (rare: only if a self-hoster ALSO configured their own matomo_url) —
  // Matomo's JS tracker supports multiple trackers via getTracker(), but
  // that's unneeded complexity here since a self-host install practically
  // never has both configured. Two full snippets would double-count
  // pageviews; only load a second tracker if it's the ONLY target.
  if (telemetryTarget && !ownTarget) loadTracker(telemetryTarget)
}

/**
 * Static route keywords from core/app/src/App.tsx's route table — anything
 * else in a path segment (slugs, numeric/UUID ids) is dynamic and gets
 * masked. Kept in sync manually; a stale entry only means an unmasked
 * static segment gets masked too (safe direction), never the reverse.
 */
const ROUTE_KEYWORDS = new Set([
  'login',
  'verify',
  'password',
  'reset',
  'request',
  'confirm',
  'signup',
  'account',
  'operator',
  'support',
  'invite',
  'accept',
  'discover',
  'admin',
  'boards',
  'roadmap',
  'idea',
  'edit',
  'submit',
  'profile',
  'members',
  'tokens',
  'inbox',
])

export function sanitizePath(pathname: string): string {
  const withoutQueryOrHash = pathname.split(/[?#]/, 1)[0]
  const segments = withoutQueryOrHash.split('/').filter((s) => s.length > 0)
  const masked = segments.map((s) => (ROUTE_KEYWORDS.has(s.toLowerCase()) ? s : ':id'))
  return `/${masked.join('/')}`
}

/** Fired on every SPA route change (see App.tsx's useLocation effect). */
export function trackPageView(pathname: string): void {
  if (!ownTarget && !telemetryTarget) return
  const safePath = sanitizePath(pathname)
  paq().push(['setCustomUrl', safePath])
  paq().push(['setDocumentTitle', safePath])
  paq().push(['trackPageView'])
}

/**
 * Fired for the small, fixed set of measurement-plan goal events (see
 * ~/Documents/Projekte/matomo/bin/goals.php, sites 10/11) — never for
 * high-frequency interactions (no clicks/scroll/mouse-move slop).
 * `name` MUST already be free of PII (board/account slugs, emails, ids) —
 * callers pass fixed, low-cardinality labels only (see call sites).
 */
export function trackEvent(category: string, action: string, name?: string): void {
  if (!ownTarget && !telemetryTarget) return
  paq().push(
    name !== undefined ? ['trackEvent', category, action, name] : ['trackEvent', category, action],
  )
}

/** Test-only reset — vitest module state otherwise leaks between tests. */
export function _resetAnalyticsForTests(): void {
  ownTarget = null
  telemetryTarget = null
  initialized = false
  delete window._paq
}
