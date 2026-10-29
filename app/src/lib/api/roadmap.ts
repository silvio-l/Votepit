/**
 * Board roadmap read + roadmap date edits.
 */

import { accountPath } from '../accountContext'
import { request } from './client'
import type { BoardData, IdeaStatus } from './model'

// ── Roadmap ───────────────────────────────────────────────────────────────────

/**
 * A read-only Roadmap idea (no voter PII, no user-specific fields).
 * Aggregates: score_cache, up_count, down_count (for consensus calculation), comment_count.
 */
export interface RoadmapIdea {
  id: number
  title: string
  body: string
  status: IdeaStatus
  /** Exact status key (custom statuses: `s<base36>`), additive. */
  status_key?: string
  score_cache: number
  created_at: string
  comment_count: number
  up_count: number
  down_count: number
  /** Roadmap Gantt bar start (YYYY-MM-DD), board-admin-set — null unless set. */
  start_date: string | null
  /** Roadmap Gantt bar end (YYYY-MM-DD), board-admin-set — null unless set. */
  target_date: string | null
}

export interface RoadmapGroups {
  planned: RoadmapIdea[]
  in_progress: RoadmapIdea[]
  done: RoadmapIdea[]
}

export interface RoadmapResponse {
  board: BoardData
  groups: RoadmapGroups
  /**
   * Whether this board's account plan may use the Gantt view — resolved
   * against the BOARD's account, not the viewer's own. The roadmap-gantt
   * extension route (setIdeaDates) only exists at all when the extension
   * package is installed (ADR 0003); on a self-host without it, this is
   * always false since the PATCH route 404s.
   */
  roadmap_gantt_allowed: boolean
}

/**
 * GET /{boardSlug}/roadmap — board-scoped, read-only Roadmap.
 * Returns ideas grouped by planned / in_progress / done (each score_cache DESC).
 * No voter PII; accessible anonymously.
 */
export async function getRoadmap(boardSlug: string): Promise<RoadmapResponse> {
  return request<RoadmapResponse>('GET', accountPath(`/${boardSlug}/roadmap`))
}

/**
 * PATCH /{boardSlug}/ideas/{ideaId}/dates — sets an idea's Roadmap Gantt
 * start_date/target_date (board-admin-only, plan-gated — see
 * RoadmapResponse.roadmap_gantt_allowed). Pass null to clear a field.
 * Throws ApiError(422, key=plan_limit_roadmap_gantt|validation_error|invalid_date_range)
 * or ApiError(404) for an unknown board/idea.
 */
export async function setIdeaDates(
  boardSlug: string,
  ideaId: string | number,
  startDate: string | null,
  targetDate: string | null,
): Promise<{ ok: boolean; start_date: string | null; target_date: string | null }> {
  return request<{ ok: boolean; start_date: string | null; target_date: string | null }>(
    'PATCH',
    accountPath(`/${boardSlug}/ideas/${ideaId}/dates`),
    { start_date: startDate, target_date: targetDate },
  )
}
