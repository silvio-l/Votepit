/**
 * Client-side referral-ref pass-through (social-features ticket 01).
 *
 * A prospect clicks a personal referral link (votepit.com/r/<account-slug>,
 * see web/public/_redirects), which lands them on the SPA's /signup page
 * with `?ref=<account-slug>` in the URL. From there the ref must survive:
 *
 *   1. the magic-link round trip (email client → /login/verify, possibly a
 *      different tab/device — the URL itself is not carried across that),
 *   2. the account-name/slug picker on /signup/account.
 *
 * sessionStorage is the right tool here, NOT the return-to URL/query string:
 * this is purely a per-browser UX nicety, not business data the server
 * needs mid-flow — the server only ever learns the referrer slug once, via
 * the explicit POST /referrals/capture call right after account creation
 * succeeds (ReferralCaptureAction re-validates/derives everything from the
 * authenticated session server-side; nothing here is trusted as-is).
 *
 * Same defensive try/catch convention as ActivationChecklist's localStorage
 * use — storage can throw (private browsing, quota) and must never break
 * the signup flow.
 */

const STORAGE_KEY = 'vp_referral_ref'

/**
 * Reads `ref` from a location.search string and stores it for later
 * consumption. Called once, on /signup mount. A missing/empty `ref` is a
 * no-op — it deliberately does NOT clear an already-stored ref, so
 * revisiting /signup (e.g. the "resend" link) without `ref` never discards
 * one captured moments earlier.
 */
export function captureReferralRef(search: string): void {
  const ref = new URLSearchParams(search).get('ref')?.trim()
  if (ref === undefined || ref === '') {
    return
  }

  try {
    sessionStorage.setItem(STORAGE_KEY, ref)
  } catch {
    // Storage unavailable — the referral link simply won't be captured.
  }
}

/**
 * Returns the stored referrer slug and clears it (one-shot) — called
 * exactly once, right after POST /signup/account succeeds. Clearing
 * regardless of what the caller does with the value prevents a stray retry/
 * refresh from re-sending the same ref for an unrelated later signup.
 */
export function consumeReferralRef(): string | null {
  try {
    const ref = sessionStorage.getItem(STORAGE_KEY)
    sessionStorage.removeItem(STORAGE_KEY)
    return ref
  } catch {
    return null
  }
}
