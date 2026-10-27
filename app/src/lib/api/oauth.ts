/**
 * MCP OAuth consent (issue 21): the session-authenticated backend of the
 * /oauth/authorize page. The MCP client opens /oauth/authorize?… in the
 * browser; this page reads the request context, lets the user pick one
 * account, its boards and a level per board, and sends the decision. The
 * server answers with `redirect_to` — the client's redirect URI carrying
 * either the authorization code or an OAuth error — and the page navigates
 * there.
 */

import type { ApiTokenScope } from './apiTokens'
import { request } from './client'

export interface OAuthConsentAccount {
  slug: string
  name: string
  /** null = may be delegated; otherwise why not (e.g. 'plan_limit_agent_api'). */
  reason: string | null
  boards: Array<{ slug: string; name: string }>
}

export interface OAuthConsentContext {
  client: {
    client_id: string
    /** Registered by the client itself — untrusted plaintext, always rendered as text. */
    name: string
    redirect_uri: string
  }
  /** Highest level the client asked for; the user may grant less, never more. */
  max_scope: ApiTokenScope
  accounts: OAuthConsentAccount[]
}

/** The OAuth authorization request parameters, passed through verbatim. */
export type OAuthAuthorizeParams = Record<string, string>

/**
 * GET /api/oauth/authorize — validates the request and returns what the
 * consent screen shows. Throws ApiError(401) without a session and
 * ApiError(400) for an invalid request (payload.redirect_to set when the
 * error may be returned to the client).
 */
export async function getOAuthConsent(params: OAuthAuthorizeParams): Promise<OAuthConsentContext> {
  return request<OAuthConsentContext>(
    'GET',
    `/api/oauth/authorize?${new URLSearchParams(params).toString()}`,
  )
}

/**
 * POST /api/oauth/authorize — the decision. Requires CSRF (call
 * bootstrap() first). Resolves with the client redirect for both approve
 * and deny.
 */
export async function decideOAuthConsent(
  params: OAuthAuthorizeParams,
  decision:
    | { decision: 'deny' }
    | {
        decision: 'approve'
        account: string
        boards: Array<{ slug: string; scope: ApiTokenScope }>
      },
): Promise<{ redirect_to: string }> {
  return request<{ redirect_to: string }>('POST', '/api/oauth/authorize', {
    ...params,
    ...decision,
  })
}
