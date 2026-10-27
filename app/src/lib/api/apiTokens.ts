/**
 * Agent API / MCP token administration.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

// ── Admin: API tokens (Agent API / Votepit MCP) ───────────────────────────────

export type ApiTokenScope = 'read' | 'write' | 'moderate'

/** A single board grant on a token, as returned by list()/create() — one scope PER board. */
export interface ApiTokenBoardGrant {
  board_id: number
  scope: ApiTokenScope
}

export interface ApiTokenSummary {
  id: number
  label: string
  boards: ApiTokenBoardGrant[]
  created_by_user_id: number
  last_used_at: string | null
  revoked_at: string | null
  created_at: string
  /** An MCP OAuth grant (issue 21) rather than a copied static token. */
  is_oauth: boolean
  /** The registered OAuth client's name — untrusted plaintext; null for static tokens. */
  oauth_client_name: string | null
}

export interface ApiTokenListResponse {
  tokens: ApiTokenSummary[]
}

/**
 * GET /admin/tokens — list the account's API tokens, across all boards
 * (never includes the token hash or plaintext).
 * AuthZ: accountAdmin (owner AND admin) — throws ApiError(401) for anon,
 * ApiError(403) for moderator.
 */
export async function listApiTokens(): Promise<ApiTokenListResponse> {
  return request<ApiTokenListResponse>('GET', accountPath('/admin/tokens'))
}

export interface CreateApiTokenResponse {
  ok: boolean
  id: number
  label: string
  boards: ApiTokenBoardGrant[]
  /** Plaintext bearer token — shown ONLY in this response, never retrievable again. */
  token: string
}

/**
 * POST /admin/tokens — create a new account-scoped API token, granting
 * access to one or more of the account's boards, each with its OWN
 * 'read'|'write' scope (one token can write on board A and only read
 * board B).
 * The plaintext token is returned exactly once in this response — the caller
 * MUST show it to the admin immediately; it can never be retrieved again.
 * AuthZ: accountAdmin. Throws ApiError(422) with fields.label/boards
 * for invalid input. Requires CSRF — call bootstrap() first.
 */
export async function createApiToken(
  boards: Array<{ slug: string; scope: ApiTokenScope }>,
  label: string,
): Promise<CreateApiTokenResponse> {
  return request<CreateApiTokenResponse>('POST', accountPath('/admin/tokens'), {
    label,
    boards,
  })
}

/**
 * POST /admin/tokens/{id}/revoke — revoke an API token.
 * AuthZ: accountAdmin. Throws ApiError(404) for an unknown/foreign-account token id.
 */
export async function revokeApiToken(tokenId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/tokens/${tokenId}/revoke`), {})
}
