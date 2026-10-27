/**
 * Comments (create/edit/moderate/react), idea moderation and moderation appeals.
 */

import { accountPath } from '../accountContext'
import { request } from './client'
import type { CommentReaction, CommentReactionsSummary } from './model'

// ── Comments ──────────────────────────────────────────────────────────────────

export interface CreateCommentResponse {
  ok: boolean
  id: number
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments — post a plaintext comment on an idea.
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 * Throws ApiError(422) with fields map on validation / moderation failure,
 * ApiError(403) if blocked, ApiError(404) if the board/idea is unknown, or
 * ApiError(422) with key `turnstile_required` + `site_key` once the
 * requester's IP is flagged as suspicious (see TurnstileGuard) — resend
 * with turnstileToken set once solved.
 */
export async function createComment(
  boardSlug: string,
  ideaId: string | number,
  body: string,
  turnstileToken?: string,
): Promise<CreateCommentResponse> {
  return request<CreateCommentResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments`),
    { body, ...(turnstileToken !== undefined ? { turnstile_token: turnstileToken } : {}) },
  )
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments/{commentId}/edit — the author
 * edits their own comment within CommentUpdateAction::EDIT_WINDOW_SECONDS
 * (60s) of posting.
 * Requires auth + CSRF — call bootstrap() before this.
 * Throws ApiError(403) if not the author, ApiError(404) for unknown/cross-board
 * comment, ApiError(422) with error.key 'edit_window_expired' once the window
 * has passed, or with a fields map on validation/moderation failure.
 */
export async function updateComment(
  boardSlug: string,
  ideaId: string | number,
  commentId: string | number,
  body: string,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments/${commentId}/edit`),
    { body },
  )
}

/** The 7 reason codes content_moderation_actions.reason_code accepts
 * (board-operator-compliance issue 06, generalized to ideas in issue 07)
 * — shared with the reason-code form (ContentModerationModal). */
export type ModerationReasonCode =
  | 'community_rule'
  | 'illegal_copyright'
  | 'illegal_privacy'
  | 'illegal_threat'
  | 'illegal_fraud'
  | 'illegal_other'
  | 'other'

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments/{commentId}/delete — remove a
 * comment (moderation, board-operator-compliance issue 06: reason-coded,
 * soft delete + author notification — replaces the previous plain delete).
 * Requires accountModerate AuthZ (owner/admin/moderator) + CSRF — call
 * bootstrap() before this.
 * Throws ApiError(422) for a missing/invalid reason_code, ApiError(403) for
 * a non-moderator, ApiError(404) for an unknown/cross-board/already-
 * moderated comment.
 */
export async function deleteComment(
  boardSlug: string,
  ideaId: string | number,
  commentId: string | number,
  reasonCode: ModerationReasonCode,
  reasonNote?: string,
): Promise<{ ok: boolean; moderation_action_id: number }> {
  return request<{ ok: boolean; moderation_action_id: number }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments/${commentId}/delete`),
    { reason_code: reasonCode, reason_note: reasonNote },
  )
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/moderate — remove an idea (moderation,
 * board-operator-compliance issue 07: reason-coded, soft delete + author
 * notification — the idea-side counterpart of deleteComment() above). NOT
 * the same as withdrawIdea() — that is the author's own hard-delete of
 * their own idea. Requires accountModerate AuthZ (owner/admin/moderator) +
 * CSRF — call bootstrap() before this.
 * Throws ApiError(422) for a missing/invalid reason_code, ApiError(403) for
 * a non-moderator, ApiError(404) for an unknown/cross-board/already-
 * moderated idea.
 */
export async function moderateIdea(
  boardSlug: string,
  ideaId: string | number,
  reasonCode: ModerationReasonCode,
  reasonNote?: string,
): Promise<{ ok: boolean; moderation_action_id: number }> {
  return request<{ ok: boolean; moderation_action_id: number }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/moderate`),
    { reason_code: reasonCode, reason_note: reasonNote },
  )
}

export type ModerationAppealStatus = 'pending' | 'upheld' | 'overturned'

export interface ModerationAppealSummary {
  id: number
  moderation_action_id: number
  submitted_by_user_id: number
  message: string
  status: ModerationAppealStatus
  resolved_by_user_id: number | null
  resolved_at: string | null
  created_at: string
  entity_type: 'idea' | 'comment'
  entity_id: number
  board_id: number
  reason_code: ModerationReasonCode
  content_snapshot: string
}

/**
 * POST {accountPath}/appeals — the original content author appeals a
 * moderation action (board-operator-compliance issue 09). Requires `user`
 * AuthZ (any logged-in user) + CSRF — call bootstrap() before this; only
 * the content's actual author gets past the in-action ownership check.
 * Throws ApiError(403) for a non-author, ApiError(404) for an unknown/
 * cross-account moderation_action_id, ApiError(409) if an appeal is
 * already pending for it, ApiError(422) for a missing/too-short message,
 * ApiError(429) once the appeal:submit rate limit is exhausted.
 */
export async function submitAppeal(
  moderationActionId: number,
  message: string,
): Promise<{ ok: boolean; id: number }> {
  return request<{ ok: boolean; id: number }>('POST', accountPath('/appeals'), {
    moderation_action_id: moderationActionId,
    message,
  })
}

/**
 * GET {accountPath}/moderation/appeals — the account's appeal review inbox.
 * Requires accountModerate AuthZ (owner/admin/moderator).
 */
export async function listModerationAppeals(): Promise<{ appeals: ModerationAppealSummary[] }> {
  return request<{ appeals: ModerationAppealSummary[] }>('GET', accountPath('/moderation/appeals'))
}

/**
 * POST {accountPath}/moderation/appeals/{id}/resolve — a moderator/admin
 * resolves an appeal. Requires accountModerate AuthZ. Throws ApiError(404)
 * for an unknown/cross-account appeal id.
 */
export async function resolveModerationAppeal(
  id: number,
  status: 'upheld' | 'overturned',
): Promise<{ ok: boolean; status: ModerationAppealStatus }> {
  return request<{ ok: boolean; status: ModerationAppealStatus }>(
    'POST',
    accountPath(`/moderation/appeals/${id}/resolve`),
    { status },
  )
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments/{commentId}/react — toggle an
 * emoji reaction on a comment. Reacting with the SAME emoji again retracts
 * it; a DIFFERENT emoji switches it in place (one active reaction per user
 * per comment, mirrors the idea-vote toggle).
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 * Throws ApiError(422) for an invalid reaction, ApiError(403) if blocked,
 * ApiError(404) if the board/idea/comment is unknown.
 */
export async function reactToComment(
  boardSlug: string,
  ideaId: string | number,
  commentId: string | number,
  reaction: CommentReaction,
): Promise<CommentReactionsSummary> {
  return request<CommentReactionsSummary>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments/${commentId}/react`),
    { reaction },
  )
}
