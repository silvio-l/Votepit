/**
 * Cloudflare Turnstile script loader, shared by the on-demand challenge
 * modal (TurnstileChallengeModal) and the always-on guest-submission widget
 * (TurnstileWidget, ADR 0006). The script is loaded lazily and at most once.
 */

const TURNSTILE_SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js'

interface TurnstileRenderOptions {
  sitekey: string
  callback: (token: string) => void
  /** Widget action — the server checks it (e.g. `anon_idea_submit`). */
  action?: string
  'error-callback'?: () => void
  'expired-callback'?: () => void
}

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: TurnstileRenderOptions) => string
      remove: (widgetId: string) => void
    }
  }
}

let scriptLoadPromise: Promise<void> | null = null

export function loadTurnstileScript(): Promise<void> {
  if (window.turnstile !== undefined) return Promise.resolve()
  scriptLoadPromise ??= new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = TURNSTILE_SCRIPT_SRC
    script.async = true
    script.defer = true
    script.addEventListener('load', () => resolve())
    script.addEventListener('error', () => {
      // Allow a later retry instead of caching the failure forever.
      scriptLoadPromise = null
      reject(new Error('turnstile script load failed'))
    })
    document.head.appendChild(script)
  })
  return scriptLoadPromise
}
