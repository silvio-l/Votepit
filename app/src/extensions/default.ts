import type { AppExtensions } from './types'

/** Community build: no extensions. Resolved through the `@votepit/app-extensions` alias. */
export const appExtensions: AppExtensions = {
  scopedRoutes: [],
  adminNavLinks: [],
  globalRoutes: [],
  platformNavLinks: [],
  dictionaries: {},
  slots: {},
}
