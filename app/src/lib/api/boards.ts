/**
 * Public board reads: board home, default board slug, idea detail.
 */

import { accountPath } from '../accountContext'
import { API_BASE, request } from './client'
import type { BoardResponse, IdeaDetailResponse } from './model'

export interface GetBoardParams {
  sort?: string
  status?: string
  /** Exact status key of a custom status (wins over `status` server-side). */
  statusKey?: string
  page?: number
  /** Free-text title search (board search, competitor-parity H6). */
  q?: string
  /** Only ideas carrying this board-scoped tag (`tag_id`). */
  tagId?: number
  /** Active list only: leaves out done, declined and archived ideas (`exclude_closed=1`). */
  excludeClosed?: boolean
}

/**
 * GET /{boardSlug} — board home + paginated idea list.
 * Query params: sort, status (category), status_key (exact status), page, q, tag_id.
 */
export async function getBoard(boardSlug: string, params?: GetBoardParams): Promise<BoardResponse> {
  const search = new URLSearchParams()
  if (params?.sort) search.set('sort', params.sort)
  if (params?.status) search.set('status', params.status)
  if (params?.statusKey) search.set('status_key', params.statusKey)
  if (params?.page && params.page > 1) search.set('page', String(params.page))
  if (params?.q) search.set('q', params.q)
  if (params?.tagId !== undefined) search.set('tag_id', String(params.tagId))
  if (params?.excludeClosed) search.set('exclude_closed', '1')
  const qs = search.toString()
  return request<BoardResponse>('GET', accountPath(`/${boardSlug}${qs ? `?${qs}` : ''}`))
}

/**
 * GET /api/board/default — slug of the account's default (or oldest public) board, for
 * the root route `/` (BoardPage.tsx has no :boardSlug there). Throws ApiError(404,
 * key=no_board) on a fresh/empty install with no public board yet.
 */
export async function getDefaultBoardSlug(): Promise<{ slug: string }> {
  return request<{ slug: string }>('GET', accountPath(`${API_BASE}/board/default`))
}

/**
 * GET /{boardSlug}/ideas/{ideaId} — single idea detail.
 * Returns board context, full idea object, and auth state.
 * Throws ApiError(404) if the board slug is unknown or the idea does not
 * belong to that board (cross-board leak prevention).
 */
export async function getIdea(
  boardSlug: string,
  ideaId: string | number,
): Promise<IdeaDetailResponse> {
  return request<IdeaDetailResponse>('GET', accountPath(`/${boardSlug}/ideas/${ideaId}`))
}
