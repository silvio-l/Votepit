import * as Sentry from '@sentry/react'

// Guards against a double init from React 19 StrictMode's deliberate
// double-invoke of effects in dev, and from AppRoutes' bootstrap() effect
// re-firing on remount — Sentry.init() itself is not idempotent-safe to
// call twice (it would re-register global handlers).
let initialized = false

/**
 * Initializes the frontend Sentry SDK once a real DSN is known (from
 * /api/bootstrap's sentry_dsn_frontend — see Config::sentryDsnFrontend).
 * A no-op when dsn is '' (self-host default, or Cloud with monitoring not
 * configured) — matches the backend's NullErrorReporter fallback.
 *
 * Deliberately minimal: only the default error-capturing integrations
 * (uncaught exceptions, unhandled promise rejections — wired in
 * automatically by Sentry.init() itself). No tracesSampleRate/replay
 * integration — this closes the "frontend errors vanish silently" gap
 * without adding a second, much higher-volume event stream against the
 * shared org-wide Sentry quota (CLAUDE.md).
 */
export function initSentryFrontend(dsn: string): void {
  if (initialized || dsn === '') return
  initialized = true

  Sentry.init({
    dsn,
    environment: import.meta.env.MODE,
  })
}

export { Sentry }
