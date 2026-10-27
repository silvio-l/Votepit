/**
 * Operator worklist (P-06): one account-wide list of ideas that wait for a
 * decision or an answer, and its header counts. The list and counts are read
 * endpoints; the row actions reuse the existing endpoints (slice 04). The
 * dismissal (part B) is the only new write: it hides a row, and undismiss
 * reverts it.
 */

import { accountPath } from '../accountContext'
import { request } from './client'
import type { IdeaStatus } from './model'

export type WorklistCategory = 'all' | 'unanswered' | 'pending_moderation' | 'decision_due'
export type WorklistAge = 'all' | 'over_7_days'
export type WorklistSort = 'oldest' | 'votes'

export interface WorklistCounts {
  unanswered: number
  unanswered_over_7_days: number
  pending_moderation: number
  decision_due: number
  /** Distinct ideas in any category (an idea can be waiting and due at once). */
  total: number
}

export interface WorklistIdea {
  id: number
  title: string
  board_slug: string
  board_name: string
  /** Status key as stored on the board (canonical or custom). */
  status: string
  /** Category of that status (one of the six canonical slugs). */
  status_category: IdeaStatus
  /** True for the six default statuses; false for an owner-defined one. */
  status_is_system: boolean
  /** The board's own plaintext label overrides (null = none, use the default). */
  status_label_de: string | null
  status_label_en: string | null
  moderation_state: string
  up_count: number
  down_count: number
  voter_count: number
  created_at: string
  age_days: number
  categories: {
    unanswered: boolean
    pending_moderation: boolean
    decision_due: boolean
  }
  /** Only for pending ideas: whether the author is a guest or a member. */
  author_type: 'member' | 'guest' | null
  /** The board is frozen (read-only): decisions and hiding are not offered. */
  board_frozen: boolean
}

export interface WorklistListResponse {
  counts: WorklistCounts
  /** Slug of the board filter that was applied, or null for all boards. */
  board: string | null
  ideas: WorklistIdea[]
  next_cursor: string | null
}

export interface WorklistFilters {
  category?: WorklistCategory
  age?: WorklistAge
  sort?: WorklistSort
  /** Board slug; omit or null for all boards. */
  board?: string | null
  cursor?: string | null
}

export interface WorklistSummaryResponse {
  counts: WorklistCounts
  board: string | null
}

/** GET {accountPrefix}/admin/worklist — counts and one page of rows (accountModerate). */
export async function getWorklist(filters: WorklistFilters = {}): Promise<WorklistListResponse> {
  const search = new URLSearchParams()
  search.set('category', filters.category ?? 'all')
  search.set('age', filters.age ?? 'all')
  search.set('sort', filters.sort ?? 'oldest')
  if (filters.board) search.set('board', filters.board)
  if (filters.cursor) search.set('cursor', filters.cursor)
  return request<WorklistListResponse>('GET', accountPath(`/admin/worklist?${search.toString()}`))
}

/** GET {accountPrefix}/admin/worklist/summary — header counts only (accountModerate). */
export async function getWorklistSummary(board?: string | null): Promise<WorklistSummaryResponse> {
  const search = new URLSearchParams()
  if (board) search.set('board', board)
  const qs = search.toString()
  return request<WorklistSummaryResponse>(
    'GET',
    accountPath(`/admin/worklist/summary${qs ? `?${qs}` : ''}`),
  )
}

/** Window event sent after any worklist change; the admin shell reloads its nav badge on it. */
export const WORKLIST_CHANGED_EVENT = 'votepit:worklist-changed'

/** Tells the admin shell that the waiting numbers changed (badge reload). */
export function notifyWorklistChanged(): void {
  try {
    window.dispatchEvent(new Event(WORKLIST_CHANGED_EVENT))
  } catch {
    // No window (tests without a DOM): the badge simply keeps its last value.
  }
}

/** POST {accountPrefix}/admin/worklist/ideas/{id}/dismiss — hide an idea that needs no reaction (accountModerate). */
export async function dismissWorklistIdea(
  ideaId: number,
): Promise<{ ok: boolean; idea_id: number; dismissed: boolean }> {
  return request('POST', accountPath(`/admin/worklist/ideas/${ideaId}/dismiss`), {})
}

/** POST {accountPrefix}/admin/worklist/ideas/{id}/undismiss — undo of dismissWorklistIdea (accountModerate). */
export async function restoreWorklistIdea(
  ideaId: number,
): Promise<{ ok: boolean; idea_id: number; dismissed: boolean }> {
  return request('POST', accountPath(`/admin/worklist/ideas/${ideaId}/undismiss`), {})
}
