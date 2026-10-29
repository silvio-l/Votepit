/**
 * Login (magic link, password, 2FA), magic-link verification, logout.
 */

import { request } from './client'

// ── Auth ──────────────────────────────────────────────────────────────────────

/**
 * POST /login — requests a magic-link email.
 * Body fields: email (required), r (optional return-to path), product_news
 * (optional boolean, signup step only: the product-news checkbox).
 * Always returns 200 {ok: true} regardless of whether the email is valid
 * (anti-enumeration; AC3/4 in LoginActionTest).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function requestMagicLink(
  email: string,
  returnTo?: string,
  productNews?: boolean,
): Promise<{ ok: boolean }> {
  const body: Record<string, string | boolean> = { email }
  if (returnTo) body.r = returnTo
  // Only the signup step sends it (r=/signup/account); the server ignores it elsewhere.
  if (productNews !== undefined) body.product_news = productNews
  return request<{ ok: boolean }>('POST', '/login', body)
}

/** Either a completed login (session cookie set by the server) or a pending-2FA handoff. */
export type LoginResult =
  | { ok: true; redirect: string }
  | { requires_2fa: true; pending_token: string }

/**
 * GET /login/verify?token=<plaintext>[&r=<returnTo>] — verifies a magic-link token.
 * On success WITHOUT TOTP: 200 {ok: true, redirect} (session cookie set by server).
 * On success WITH TOTP active: 200 {requires_2fa: true, pending_token} — no
 * session yet, the caller must complete POST /login/2fa (see loginWith2fa).
 * On failure: throws ApiError(400) with key=invalid_token.
 * GET is CSRF-exempt (single-use capability token is its own proof).
 */
export async function verifyToken(token: string, returnTo?: string): Promise<LoginResult> {
  const params = new URLSearchParams({ token })
  if (returnTo) params.set('r', returnTo)
  return request<LoginResult>('GET', `/login/verify?${params.toString()}`)
}

/**
 * POST /login/password — email + password login (additive to the magic-link
 * flow, never a replacement). Same LoginResult shape as verifyToken: either
 * an immediate session, or a pending-2FA handoff when TOTP is active.
 * On failure (wrong credentials, unknown user, blocked): throws ApiError(401)
 * with a generic key=invalid_credentials message — never distinguishes
 * "unknown email" from "wrong password" (anti-enumeration).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function loginWithPassword(
  email: string,
  password: string,
  returnTo?: string,
): Promise<LoginResult> {
  const body: Record<string, string> = { email, password }
  if (returnTo) body.r = returnTo
  return request<LoginResult>('POST', '/login/password', body)
}

/**
 * POST /login/2fa — second factor after verifyToken/loginWithPassword
 * returned {requires_2fa: true, pending_token}. Pass EITHER a 6-digit TOTP
 * code OR a backup code, never both. On success: 200 {ok: true, redirect}.
 * On failure: throws ApiError(400) with key=invalid_code or
 * key=invalid_pending_token — no side effect, the pending token stays usable.
 * Requires CSRF token — call bootstrap() before this.
 */
export async function loginWith2fa(
  pendingToken: string,
  credential: { code: string } | { backupCode: string },
  returnTo?: string,
): Promise<{ ok: boolean; redirect: string }> {
  const body: Record<string, string> = { pending_token: pendingToken }
  if ('code' in credential) body.code = credential.code
  else body.backup_code = credential.backupCode
  if (returnTo) body.r = returnTo
  return request<{ ok: boolean; redirect: string }>('POST', '/login/2fa', body)
}

/**
 * POST /logout — invalidates the current session (bumps token_version).
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 */
export async function logout(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/logout', {})
}
