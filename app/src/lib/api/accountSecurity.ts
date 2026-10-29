/**
 * Account security self-service: password + TOTP 2FA.
 */

import { request } from './client'

// ── Account security: password + TOTP 2FA (ProfilePage) ─────────────────────

/**
 * POST /account/password — sets or changes the current user's password.
 * AuthZ: user (any logged-in user, no special role required). Omit
 * currentPassword on a first-time set (the active session already proves the
 * user clicked a magic link); required when a password is already set.
 * newPasswordConfirmation must match newPassword — server-side authoritative,
 * throws ApiError(400, key=password_mismatch) otherwise (also checked
 * client-side in SecuritySettings.tsx for immediate feedback).
 * Throws ApiError(400) with key=invalid_current_password, key=password_mismatch,
 * or key=weak_password. Requires CSRF — call bootstrap() first.
 */
export async function setPassword(
  newPassword: string,
  newPasswordConfirmation: string,
  currentPassword?: string,
): Promise<{ ok: boolean }> {
  const body: Record<string, string> = {
    new_password: newPassword,
    new_password_confirmation: newPasswordConfirmation,
  }
  if (currentPassword) body.current_password = currentPassword
  return request<{ ok: boolean }>('POST', '/account/password', body)
}

/**
 * POST /password/reset/request — "forgot password" step A (AuthZ: anon).
 * Always resolves to {ok: true} regardless of whether the address matches an
 * account (anti-enumeration — see PasswordResetRequestAction class doc).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function requestPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/password/reset/request', { email })
}

/**
 * POST /password/reset/confirm — "forgot password" step B (AuthZ: anon — the
 * single-use reset token itself is the capability). On success, every other
 * active session for this user is invalidated (same mechanism as logout).
 * Throws ApiError(400) with key=invalid_token, key=password_mismatch, or
 * key=weak_password. CSRF-exempt is NOT the case here — this is a mutating
 * POST, so call bootstrap() first to seed the CSRF token.
 */
export async function confirmPasswordReset(
  token: string,
  newPassword: string,
  newPasswordConfirmation: string,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/password/reset/confirm', {
    token,
    new_password: newPassword,
    new_password_confirmation: newPasswordConfirmation,
  })
}

/**
 * POST /account/password-reset — logged-in self-service "send me a reset
 * link" (AuthZ: user). The caller re-types their own email as a
 * confirmation step (ADR 0002: no plaintext email is ever stored, not even
 * for the caller's own account, so it can't be pre-filled). Throws
 * ApiError(422, key=email_mismatch) if it doesn't match the account.
 */
export async function requestOwnPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/account/password-reset', { email })
}

/**
 * POST /admin/members/password-reset — Owner/Admin triggers a mail-based
 * reset link for one of the account's members, identified by re-typed
 * email (AuthZ: accountAdmin). Throws ApiError(404, key=not_found) if no
 * member of this account matches that email.
 */
export async function requestMemberPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/admin/members/password-reset', { email })
}

/**
 * POST /operator/users/password-reset — Operator/Support triggers a
 * mail-based reset link for any platform user, identified by re-typed
 * email (AuthZ: support — is_support or is_operator). Throws
 * ApiError(404, key=not_found) if no user matches that email.
 */
export async function requestOperatorPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/operator/users/password-reset', { email })
}

/**
 * POST /operator/users/block — Operator/Support sets or clears the global,
 * installation-wide kill switch for a platform user, identified by re-typed
 * email (AuthZ: support). Blocking also invalidates every existing session
 * of that user immediately. Throws ApiError(404, key=not_found) if no user
 * matches that email.
 */
export async function setOperatorUserBlocked(
  email: string,
  blocked: boolean,
): Promise<{ ok: boolean; blocked: boolean }> {
  return request<{ ok: boolean; blocked: boolean }>('POST', '/operator/users/block', {
    email,
    blocked,
  })
}

/**
 * POST /operator/users/end-sessions — Operator/Support force-ends every
 * active session of a platform user (re-typed email), without blocking the
 * account — used to respond to a suspected account takeover. Throws
 * ApiError(404, key=not_found) if no user matches that email.
 */
export async function endOperatorUserSessions(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/operator/users/end-sessions', { email })
}

export interface TotpSetupData {
  secret: string
  provisioning_uri: string
  /** Signed blob to echo back unchanged in confirmTotpSetup — the secret is never persisted before confirmation. */
  setup_token: string
}

/**
 * POST /account/totp/setup — begins TOTP enrollment: generates a fresh
 * secret (NOT persisted yet) and a QR provisioning URI to render
 * client-side. AuthZ: user. Requires CSRF — call bootstrap() first.
 */
export async function beginTotpSetup(): Promise<TotpSetupData> {
  return request<TotpSetupData>('POST', '/account/totp/setup', {})
}

/**
 * POST /account/totp/confirm — verifies the 6-digit code against the
 * setup-token's secret; on success activates 2FA and returns 10 backup
 * codes in plaintext — shown to the user exactly once, never retrievable
 * again. AuthZ: user. Throws ApiError(400, key=invalid_code) on a wrong or
 * expired code. Requires CSRF — call bootstrap() first.
 */
export async function confirmTotpSetup(
  setupToken: string,
  code: string,
): Promise<{ ok: boolean; backup_codes: string[] }> {
  return request<{ ok: boolean; backup_codes: string[] }>('POST', '/account/totp/confirm', {
    setup_token: setupToken,
    code,
  })
}

/** Either the current password or a valid TOTP code — required to disable 2FA or regenerate backup codes. */
export type TotpConfirmation = { currentPassword: string } | { code: string }

function totpConfirmationBody(confirmation: TotpConfirmation): Record<string, string> {
  return 'currentPassword' in confirmation
    ? { current_password: confirmation.currentPassword }
    : { code: confirmation.code }
}

/**
 * POST /account/totp/disable — turns 2FA off and deletes the secret + all
 * backup codes. AuthZ: user. Requires the current password OR a valid TOTP
 * code as confirmation. Throws ApiError(400, key=confirmation_failed) on a
 * wrong confirmation. Requires CSRF — call bootstrap() first.
 */
export async function disableTotp(confirmation: TotpConfirmation): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    '/account/totp/disable',
    totpConfirmationBody(confirmation),
  )
}

/**
 * POST /account/totp/backup-codes/regenerate — invalidates all existing
 * backup codes and issues 10 fresh ones (plaintext, shown once). AuthZ:
 * user. Same confirmation requirement as disableTotp. Requires CSRF — call
 * bootstrap() first.
 */
export async function regenerateBackupCodes(
  confirmation: TotpConfirmation,
): Promise<{ ok: boolean; backup_codes: string[] }> {
  return request<{ ok: boolean; backup_codes: string[] }>(
    'POST',
    '/account/totp/backup-codes/regenerate',
    totpConfirmationBody(confirmation),
  )
}
