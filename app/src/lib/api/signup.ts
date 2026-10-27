/**
 * Cloud signup/onboarding and referral capture.
 */

import { trackEvent } from '../analytics'
import { request } from './client'

// ── Cloud signup / onboarding ──────────────────────────────────────────────────

export interface SignupStatus {
  /** true = this user already owns/belongs to an account (one account per signup, decision 17). */
  has_account: boolean
}

/**
 * GET /signup/account — tells the SPA whether this (already logged-in) user
 * already belongs to an account, so the picker form can be skipped.
 * AuthZ: user — throws ApiError(401) for anon.
 */
export async function getSignupStatus(): Promise<SignupStatus> {
  return request<SignupStatus>('GET', '/signup/account')
}

export interface CreateSignupAccountPayload {
  account_name: string
  account_slug: string
  /**
   * Legal-operator identity (board-operator-compliance follow-up, extended
   * by migrations/0064_add_legal_operator_contact.sql for international
   * legal-notice completeness) — name, address and contact email are all
   * mandatory at signup, validated exactly like account_name/board_name
   * (see SignupAccountAction's class doc). `legal_operator_notice` is not
   * collected here — optional, added later on the account settings page.
   */
  legal_operator_name: string
  legal_operator_address: string
  legal_operator_email: string
  board_name: string
  board_slug: string
  /**
   * Terms/AUP/Privacy consent (board-operator-compliance ticket 04) — must
   * be `true` or the server rejects with 422 `error.key: 'terms_not_accepted'`
   * and creates nothing. The server already knows the current version of
   * each document; the client only confirms acceptance, never echoes
   * versions back (see SignupTermsAcceptanceGate class doc).
   */
  terms_accepted: boolean
}

export interface CreateSignupAccountResponse {
  ok: boolean
  account_slug: string
  board_slug: string
}

/**
 * POST /signup/account — creates the account (plan='free'), makes the caller
 * its owner, and creates the first board — all in one step.
 * AuthZ: user — throws ApiError(401) for anon, ApiError(409, key=
 * 'already_has_account') for a user who already belongs to an account
 * (one account per signup), ApiError(422) with a fields map for validation
 * failures (empty/too-long name, invalid/reserved/taken slug).
 */
export async function createSignupAccount(
  payload: CreateSignupAccountPayload,
): Promise<CreateSignupAccountResponse> {
  const result = await request<CreateSignupAccountResponse>('POST', '/signup/account', payload)
  // Activation goal (measurement plan: "signup_completed", site 10) — this
  // endpoint only exists in cloud mode, no edition check needed.
  trackEvent('Cloud', 'signup_completed')
  return result
}

export interface CaptureReferralResponse {
  ok: boolean
  recorded: boolean
}

/**
 * POST /referrals/capture — cloud-only (social-features ticket 01). Records
 * that the CALLER's own, just-created account was signed up via another
 * account's personal referral link (votepit.com/r/<account-slug>). The
 * referred account is always derived server-side from the session, never
 * sent by the client. `recorded: false` covers every non-error outcome
 * (self-referral, unknown/missing referrer slug, already referred) — none
 * of these need special handling by the caller.
 * AuthZ: user — throws ApiError(401) for anon.
 */
export async function captureReferral(referrerSlug: string): Promise<CaptureReferralResponse> {
  return request<CaptureReferralResponse>('POST', '/referrals/capture', {
    referrer_slug: referrerSlug,
  })
}
