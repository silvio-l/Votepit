/**
 * Board tags and idea tag assignment.
 */

import { accountPath } from '../accountContext'
import { request } from './client'
import type { Tag } from './model'

// ── Tags (board-scoped) ────────────────────────────────────────────────────────

/**
 * GET /{boardSlug}/tags — list a board's tags. Public (anon) — read-only for
 * every voter; the mutating endpoints below require accountModerate.
 */
export async function listTags(boardSlug: string): Promise<{ tags: Tag[] }> {
  return request<{ tags: Tag[] }>('GET', accountPath(`/${boardSlug}/tags`))
}

/**
 * POST /{boardSlug}/tags — create a tag (moderator only).
 * Requires accountModerate AuthZ + CSRF — call bootstrap() first.
 * Throws ApiError(422) with fields map on validation failure (empty name,
 * invalid color, duplicate name in the board).
 */
export async function createTag(
  boardSlug: string,
  data: { name: string; color?: string },
): Promise<{ ok: boolean; tag: Tag }> {
  return request<{ ok: boolean; tag: Tag }>('POST', accountPath(`/${boardSlug}/tags`), data)
}

/**
 * POST /{boardSlug}/tags/{tagId} — rename/recolor a tag (moderator only).
 * Requires accountModerate AuthZ + CSRF. Throws ApiError(404) for a foreign
 * tag, ApiError(422) on validation failure/name collision.
 */
export async function renameTag(
  boardSlug: string,
  tagId: number,
  data: { name: string; color?: string },
): Promise<{ ok: boolean; tag: Tag }> {
  return request<{ ok: boolean; tag: Tag }>(
    'POST',
    accountPath(`/${boardSlug}/tags/${tagId}`),
    data,
  )
}

/**
 * POST /{boardSlug}/tags/{tagId}/delete — delete a tag (moderator only).
 * Requires accountModerate AuthZ + CSRF. Cascades to every idea assignment.
 */
export async function deleteTag(boardSlug: string, tagId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/${boardSlug}/tags/${tagId}/delete`), {})
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/tags — assign a tag to an idea (moderator only).
 * Requires accountModerate AuthZ + CSRF. Returns the idea's full, current tag list.
 */
export async function assignIdeaTag(
  boardSlug: string,
  ideaId: string | number,
  tagId: number,
): Promise<{ ok: boolean; tags: Tag[] }> {
  return request<{ ok: boolean; tags: Tag[] }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/tags`),
    { tag_id: tagId },
  )
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/tags/{tagId}/remove — remove a tag from
 * an idea (moderator only). Requires accountModerate AuthZ + CSRF. Returns
 * the idea's full, current tag list.
 */
export async function removeIdeaTag(
  boardSlug: string,
  ideaId: string | number,
  tagId: number,
): Promise<{ ok: boolean; tags: Tag[] }> {
  return request<{ ok: boolean; tags: Tag[] }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/tags/${tagId}/remove`),
    {},
  )
}
