/**
 * SPA extension seam — the frontend counterpart of core/src/Extension/
 * AppExtension.php.
 *
 * Core resolves the module specifier `@votepit/app-extensions` to
 * `./default.ts` (an empty registry). A downstream distribution points the
 * alias at its own module via the `VOTEPIT_APP_EXTENSIONS` build variable
 * (see vite.config.ts) to add pages, admin-nav entries and i18n strings
 * without touching core. Everything here is declarative so core can mount
 * it in the right place (routes under the account prefix, nav entries next
 * to core's own, dictionaries into the i18n catalog).
 */

import type { ReactElement } from 'react'
import type { Language } from '../lib/i18n/context'

interface ExtensionScopedRoute {
  /** Relative to the account prefix, no leading slash — e.g. `admin/billing`. */
  subpath: string
  /** Rendered element; use `lazy()` so the page stays its own chunk. */
  element: ReactElement
}

interface ExtensionAdminNavLink {
  /** Same convention as `subpath` above; core prefixes it with the account path. */
  subpath: string
  label: Record<Language, string>
}

/**
 * A global (unprefixed, non account-scoped) route — the analogue of
 * ExtensionScopedRoute for pages that live above the account tier, like the
 * operator panel (e.g. a platform-wide SaaS admin dashboard). Mounted inside
 * App.tsx's <GlobalLayout>, absolute `path` and all (no account-prefix
 * rewriting, unlike scopedRoutes).
 */
interface ExtensionGlobalRoute {
  /** Absolute route path, e.g. `/admin/overview`. */
  path: string
  /** Rendered element; use `lazy()` so the page stays its own chunk. */
  element: ReactElement
}

/**
 * Fixed mount points inside core's own pages — the SPA analogue of
 * AppExtension::routeMiddleware() on the backend: core stays in charge of
 * the page, the extension fills a named hole. Each slot renders at most one
 * element; leave a slot out to render nothing there.
 */
interface ExtensionSlots {
  /**
   * Rendered once above the whole route tree (App.tsx), on every page —
   * e.g. an installation-wide notice bar. Rendered inside the i18n
   * provider, so `useT()` works, but outside any router-scoped layout.
   */
  appBanner?: ReactElement
  /**
   * Rendered on the login page below core's own sign-in forms (LoginPage,
   * idle state only — not on the "link sent" or 2FA screens), e.g. an
   * alternative sign-in an extension provides.
   */
  loginFooter?: ReactElement
}

export interface AppExtensions {
  scopedRoutes: ExtensionScopedRoute[]
  /** Appended to the account-admin section nav after core's entries. */
  adminNavLinks: ExtensionAdminNavLink[]
  /** Global (unprefixed) routes, mounted inside <GlobalLayout> alongside /operator. */
  globalRoutes: ExtensionGlobalRoute[]
  /** namespace → language → key → text; merged into the i18n catalog (extension keys win). */
  dictionaries: Record<string, Record<Language, Record<string, string>>>
  /** Elements for core's fixed mount points; `{}` for none. */
  slots: ExtensionSlots
}
