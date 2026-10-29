import { request } from './client'

/**
 * Product-news / promotion consent of the signed-in user (migration 0112).
 * Only exists on a hosted installation (feature `marketing_consent`); the
 * routes answer 404 elsewhere. Status values match MarketingConsent on the server.
 */

export type MarketingConsentStatus = 'pending' | 'confirmed' | 'withdrawn'

export interface MarketingConsentState {
  /** null = no consent has been requested or given yet. */
  status: MarketingConsentStatus | null
  text_version: string
}

/** GET /account/marketing-consent — the caller's own consent state. */
export async function getMarketingConsent(): Promise<MarketingConsentState> {
  return request<MarketingConsentState>('GET', '/account/marketing-consent')
}

/**
 * PUT /account/marketing-consent — enabled=true sends a confirmation mail to
 * the confirmed notification e-mail (double opt-in, status becomes pending);
 * enabled=false withdraws at once. Rejects with key=notification_email_required
 * when no confirmed notification e-mail exists yet.
 */
export async function setMarketingConsent(
  enabled: boolean,
): Promise<{ status: MarketingConsentStatus }> {
  return request<{ status: MarketingConsentStatus }>('PUT', '/account/marketing-consent', {
    enabled,
  })
}

/** GET /account/marketing-consent/confirm?token=<plaintext> — redeems the e-mailed link. */
export async function confirmMarketingConsent(token: string): Promise<{ ok: boolean }> {
  const params = new URLSearchParams({ token })
  return request<{ ok: boolean }>('GET', `/account/marketing-consent/confirm?${params.toString()}`)
}
