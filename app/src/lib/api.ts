/**
 * Votepit API client
 *
 * All requests use `credentials: 'include'` (session cookie auth).
 * Mutating requests send the cached CSRF token as `X-CSRF-Token`.
 * Call `bootstrap()` on app mount to seed the CSRF token and user state.
 *
 * This file is the public entry point (pages, components, SPA extensions and
 * the cloud layer import from `lib/api`); the implementation lives in
 * per-domain modules under `lib/api/` — add new endpoints to the matching
 * module (or a new one re-exported here), not to this barrel.
 */

export * from './api/account'
export * from './api/accountSecurity'
export * from './api/apiTokens'
export * from './api/auth'
export * from './api/boardAdmin'
export * from './api/boardImport'
export * from './api/boardIntegrations'
export * from './api/boards'
export type { ApiErrorPayload } from './api/client'
export { ApiError, api, bootstrap, request } from './api/client'
export * from './api/comments'
export * from './api/discovery'
export * from './api/ideas'
export * from './api/issueTrackers'
export * from './api/marketingConsent'
export * from './api/members'
export * from './api/model'
export * from './api/notifications'
export * from './api/oauth'
export * from './api/operator'
export * from './api/profile'
export * from './api/roadmap'
export * from './api/signup'
export * from './api/support'
export * from './api/tags'
export * from './api/translation'
export * from './api/widget'
export * from './api/worklist'
