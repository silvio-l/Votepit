/**
 * Low-level HTTP client: JSON `request()` with CSRF header + cookie
 * credentials, `ApiError`, `bootstrap()` (seeds the CSRF token) and the raw
 * `api` helpers. The only module holding mutable state (the CSRF token).
 */

import { translateActive } from '../i18n/context'
import { setViewer } from '../viewer'
import type { BootstrapData } from './model'

export const API_BASE = '/api'

/** Module-level CSRF token — populated by bootstrap(). */
let cachedCsrfToken: string | null = null

/**
 * Current CSRF token for the raw-fetch callers (multipart uploads) that
 * cannot go through `request()`. Internal to lib/api — not re-exported.
 */
export function getCsrfToken(): string | null {
  return cachedCsrfToken
}

export interface ApiErrorPayload {
  key: string
  message: string
  fields?: Record<string, string>
  /** Present only for `key === 'turnstile_required'` — the public Turnstile site key to render the challenge widget with. */
  site_key?: string
  /** Present only for `key === 'import_invalid'` — why the uploaded import file was rejected (closed vocabulary). */
  reason?: string
  /** MCP OAuth consent errors that may go back to the client: the client redirect carrying the OAuth error. */
  redirect_to?: string
}

export class ApiError extends Error {
  readonly status: number
  readonly payload: ApiErrorPayload

  constructor(status: number, payload: ApiErrorPayload) {
    super(payload.message)
    this.name = 'ApiError'
    this.status = status
    this.payload = payload
  }
}

// ── Request helper ────────────────────────────────────────────────────────────

/**
 * JSON request with CSRF header + cookie credentials. Exported for SPA
 * extensions (`@votepit/app-extensions`) so their API clients share the
 * same error handling and CSRF seeding; core pages use the typed wrappers
 * below.
 */
export async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    if (cachedCsrfToken !== null) {
      headers['X-CSRF-Token'] = cachedCsrfToken
    }
  }

  const resp = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (!resp.ok) {
    let errPayload: ApiErrorPayload = {
      key: 'http_error',
      message: resp.statusText || `HTTP ${resp.status}`,
    }
    try {
      const json = (await resp.json()) as { error?: ApiErrorPayload }
      if (json.error && typeof json.error.key === 'string') {
        errPayload = json.error
      }
    } catch {
      // leave default payload
    }
    if (errPayload.key === 'totp_required') {
      // Pages render payload.message as-is; the API's is English-only.
      errPayload = {
        ...errPayload,
        message: translateActive('common', 'state.totpRequired') ?? errPayload.message,
      }
    }
    throw new ApiError(resp.status, errPayload)
  }

  return resp.json() as Promise<T>
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Fetches CSRF token + current user.
 * Must be called before any mutating request to seed cachedCsrfToken.
 */
export async function bootstrap(): Promise<BootstrapData> {
  const data = await request<BootstrapData>('GET', `${API_BASE}/bootstrap`)
  cachedCsrfToken = data.csrf_token
  setViewer(data.user)
  return data
}

/** Low-level helpers re-exported for pages that need raw GET/POST/PUT/DELETE. */
export const api = {
  get: <T>(path: string) => request<T>('GET', `${API_BASE}${path}`),
  post: <T>(path: string, body?: unknown) => request<T>('POST', `${API_BASE}${path}`, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', `${API_BASE}${path}`, body),
  delete: <T>(path: string) => request<T>('DELETE', `${API_BASE}${path}`),
}

/**
 * Downloads a file response and triggers a browser save (fetch → Blob →
 * temporary object URL → invisible `<a download>` click → revoke). For
 * file-response endpoints that cannot go through the JSON-only request().
 * The server's `Content-Disposition` filename is used when present.
 * Throws ApiError with the server's `{error}` payload on a non-2xx status.
 */
export async function downloadFile(
  url: string,
  accept: string,
  fallbackName: string,
): Promise<void> {
  const resp = await fetch(url, {
    method: 'GET',
    headers: { Accept: accept },
    credentials: 'include',
  })

  if (!resp.ok) {
    let errPayload: ApiErrorPayload = {
      key: 'http_error',
      message: resp.statusText || `HTTP ${resp.status}`,
    }
    try {
      const json = (await resp.json()) as { error?: ApiErrorPayload }
      if (json.error && typeof json.error.key === 'string') {
        errPayload = json.error
      }
    } catch {
      // leave default payload
    }
    throw new ApiError(resp.status, errPayload)
  }

  const blob = await resp.blob()
  const disposition = resp.headers.get('Content-Disposition') ?? ''
  const match = /filename="([^"]+)"/.exec(disposition)
  const filename = match?.[1] ?? fallbackName

  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(objectUrl)
}
