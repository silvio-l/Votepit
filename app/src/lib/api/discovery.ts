/**
 * Public, cross-tenant board discovery.
 */

import { request } from './client'

// ── Public board discovery ──────────────────────────────────────────────────────

export interface PublicDiscoveryBoard {
  slug: string
  name: string
  account_slug: string
  intro: string | null
  idea_count: number
  vote_count: number
}

export interface PublicDiscoveryResponse {
  ok: boolean
  boards: PublicDiscoveryBoard[]
  total: number
  page: number
  limit: number
}

/**
 * GET /discover — public, cross-tenant, anon listing of `visibility: 'public'`
 * boards across every account (BoardDiscoveryAction). Deliberately NOT run
 * through accountPath() — this route is not account-scoped, it lists across
 * accounts, and the backend registers it without an account prefix.
 */
export async function fetchPublicBoardDiscovery(
  page = 1,
  limit = 24,
): Promise<PublicDiscoveryResponse> {
  return request<PublicDiscoveryResponse>(
    'GET',
    `/discover?page=${encodeURIComponent(String(page))}&limit=${encodeURIComponent(String(limit))}`,
  )
}
