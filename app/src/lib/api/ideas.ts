/**
 * Idea submission, duplicate search, editing/withdrawal, status, pin, merge and voting.
 */

import { accountPath } from '../accountContext'
import type { BrandingPro } from '../branding'
import { request } from './client'
import type { BoardPhasesInfo, BoardVisual, Idea, IdeaStatus, StatusDecision, Tag } from './model'

// ── Idea submission ───────────────────────────────────────────────────────────

/**
 * Response shape for GET /{boardSlug}/ideas/new.
 * form_at is the server-signed Time-Trap stamp; send it back as _form_at in the POST.
 */
export interface SubmitFormData {
  /** `phases` (issue 04) is absent on older payloads (= submissions open). */
  board: {
    id: number
    slug: string
    name: string
    phases?: BoardPhasesInfo
    /** Pro branding the public payload applies (absent = Votepit defaults). */
    branding_pro?: BrandingPro | null
    /**
     * Guest idea submission (ADR 0006): true when a visitor without an
     * account may submit here (board switch ∧ plan ∧ Turnstile). Absent on
     * older payloads (= false).
     */
    anonymous_submission_available?: boolean
    /** Public Turnstile site key for the guest form — only when available. */
    turnstile_site_key?: string
    /** The board's existing tags for the optional tag picker (P-34). Absent = none. */
    tags?: Tag[]
    /** AI tag suggestion availability for the signed-in account (P-35); absent = off. */
    tag_suggestion?: { availability: TagSuggestionAvailability }
  } & BoardVisual
  is_authenticated: boolean
  form_at: string
}

export interface CreateIdeaResponse {
  ok: boolean
  id: number
  /**
   * Members: present only when the idea awaits approval ('pending').
   * Guests (ADR 0006): always present — 'pending' or 'published'.
   */
  moderation_state?: 'pending' | 'published'
}

/**
 * GET /{boardSlug}/ideas/new — fetch board context, auth state, and Time-Trap stamp.
 * Must be called before rendering the submit form.
 * Throws ApiError(404) if the board slug is unknown.
 */
export async function getSubmitForm(boardSlug: string): Promise<SubmitFormData> {
  return request<SubmitFormData>('GET', accountPath(`/${boardSlug}/ideas/new`))
}

/**
 * A reranked duplicate-search hit — FULLTEXT recall + Jaro–Winkler
 * rerank, board-scoped. `similarity` is 0..1 (higher = closer match).
 */
export interface DuplicateCandidate {
  id: number
  title: string
  status: IdeaStatus
  up_count: number
  down_count: number
  similarity: number
  /**
   * Optional confirmation step of an extension (issue 11): true/false once it
   * gave a verdict (only with `confirm`), null otherwise. Absent on servers
   * that predate it.
   */
  confirmed?: boolean | null
}

export interface SearchDuplicatesResponse {
  candidates: DuplicateCandidate[]
}

/**
 * GET /{boardSlug}/ideas/search-duplicates?title=... — as-you-type duplicate
 * recall for the submit form. Board-scoped; surfacing only, no
 * auto-merge. Requires auth — or, for a guest, a board that accepts ideas
 * without an account (published titles only, never confirmed; ADR 0006).
 * Returns an empty candidate list for very short titles or when nothing clears
 * the similarity threshold — never throws for "no results".
 * `confirm` asks the server's optional confirmation step (if installed) to
 * double-check the candidates — send it once per title, not per keystroke.
 */
export async function searchDuplicates(
  boardSlug: string,
  title: string,
  options: { confirm?: boolean } = {},
): Promise<SearchDuplicatesResponse> {
  const params = new URLSearchParams({ title })
  if (options.confirm === true) params.set('confirm', '1')
  return request<SearchDuplicatesResponse>(
    'GET',
    accountPath(`/${boardSlug}/ideas/search-duplicates?${params.toString()}`),
  )
}

/** Availability of AI tag suggestions for the signed-in account (P-35). */
export type TagSuggestionAvailability = 'available' | 'upgrade_required' | 'disabled' | 'off'

export interface SuggestTagsResponse {
  suggestions: number[]
  availability: TagSuggestionAvailability
}

/**
 * POST /{boardSlug}/ideas/tag-suggestions — AI tag suggestions while the idea
 * is written (P-35). Signed-in only; the server answers with ids of this
 * board's existing tags only, never with new tags.
 */
export async function suggestTags(
  boardSlug: string,
  draft: { title: string; body: string },
): Promise<SuggestTagsResponse> {
  return request<SuggestTagsResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/tag-suggestions`),
    draft,
  )
}

/**
 * POST /{boardSlug}/ideas — create a new idea.
 * website must always be '' (honeypot — server rejects non-empty).
 * _form_at must be the stamp returned by getSubmitForm() (Time-Trap — server validates HMAC + elapsed time).
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 * Throws ApiError(422) with fields map on validation / moderation / anti-spam failure,
 * or with key `turnstile_required` + `site_key` once the requester's IP is flagged
 * as suspicious (see TurnstileGuard) — resend with `turnstile_token` set once solved.
 */
export async function createIdea(
  boardSlug: string,
  payload: {
    title: string
    body: string
    website: string
    _form_at: string
    turnstile_token?: string
    /** Optional existing board tags (P-34, max 5). Omit when none are picked. */
    tag_ids?: number[]
  },
): Promise<CreateIdeaResponse> {
  return request<CreateIdeaResponse>('POST', accountPath(`/${boardSlug}/ideas`), payload)
}

// ── Guest ideas on this device (ADR 0006 §7) ─────────────────────────────────

/** One idea this device submitted without an account. */
export interface GuestIdea {
  id: number
  title: string
  moderation_state: 'pending' | 'published'
  status: string
  /** Removed by a moderator — `reason_code` then carries the reason. */
  rejected: boolean
  reason_code: string | null
  created_at: string
}

/**
 * GET /{boardSlug}/ideas/guest-mine — the ideas this device (cookie
 * `vp_anon_author`) submitted on the board, newest first, max 50. Empty
 * without the cookie; 404 when the board does not accept guest ideas.
 */
export async function getGuestIdeas(boardSlug: string): Promise<{ ideas: GuestIdea[] }> {
  return request<{ ideas: GuestIdea[] }>('GET', accountPath(`/${boardSlug}/ideas/guest-mine`))
}

/**
 * POST /{boardSlug}/ideas/{id}/guest-withdraw — deletes one of this device's
 * ideas while it still awaits approval. 404 for anything else.
 */
export async function withdrawGuestIdea(
  boardSlug: string,
  ideaId: number,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/guest-withdraw`),
    {},
  )
}

// ── Idea editing & withdrawal ─────────────────────────────────────────────────

export interface GetIdeaForEditResponse {
  board: { id: number; slug: string; name: string } & BoardVisual
  idea: Idea
  /** The board's existing tags for the edit picker (P-34). */
  tags?: Tag[]
  is_authenticated: boolean
  form_at: string
}

export interface UpdateIdeaPayload {
  title: string
  body: string
  website: string // honeypot — always ''
  _form_at: string // time-trap stamp from GET /edit
  /** The idea's full tag set (P-34); omitted = tags unchanged. */
  tag_ids?: number[]
}

/**
 * GET /{boardSlug}/ideas/{ideaId}/edit — fetch pre-filled idea for editing.
 * Returns idea data + form_at stamp for the edit POST.
 * Throws ApiError(401) for anon, ApiError(403) for non-owner, ApiError(404) if not found.
 */
export async function getIdeaForEdit(
  boardSlug: string,
  ideaId: string | number,
): Promise<GetIdeaForEditResponse> {
  return request<GetIdeaForEditResponse>('GET', accountPath(`/${boardSlug}/ideas/${ideaId}/edit`))
}

/**
 * POST /{boardSlug}/ideas/{ideaId} — update an existing idea (author only).
 * website must always be '' (honeypot — server rejects non-empty).
 * _form_at must be the stamp returned by getIdeaForEdit() (Time-Trap).
 * Requires auth + ownership (403 for non-owner) and a valid CSRF token.
 * Throws ApiError(422) with fields map on validation / moderation / anti-spam failure.
 */
export async function updateIdea(
  boardSlug: string,
  ideaId: string | number,
  payload: UpdateIdeaPayload,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/${boardSlug}/ideas/${ideaId}`), payload)
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/withdraw — hard-delete own idea.
 * Requires auth + ownership (403 for non-owner) and a valid CSRF token.
 * Throws ApiError(403) for non-owner, ApiError(404) if not found.
 */
export async function withdrawIdea(
  boardSlug: string,
  ideaId: string | number,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/${boardSlug}/ideas/${ideaId}/withdraw`), {})
}

// ── Idea status (admin) ───────────────────────────────────────────────────────

export interface SetIdeaStatusResponse {
  ok: boolean
  status: IdeaStatus
  status_key?: string
  allowed_next?: string[]
}

/** Optional reasoning for a status change (P-01). Blank values are sent as empty strings and stored as absent. */
export interface StatusDecisionInput {
  reason?: string
  nextStep?: string
  responsibleRole?: 'team' | 'moderation' | ''
}

export type { StatusDecision }

/**
 * POST /{boardSlug}/ideas/{ideaId}/status — set idea status (admin only).
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 * Throws ApiError(403) for non-admin, ApiError(422) for invalid transition.
 * `status` is a status KEY of the board (owner-defined statuses: `s<base36>`,
 * issue 03). Returns the canonical category as `status`, plus the key and
 * the keys the idea may move to next.
 */
export async function setIdeaStatus(
  boardSlug: string,
  ideaId: string | number,
  status: string,
  decision?: StatusDecisionInput,
): Promise<SetIdeaStatusResponse> {
  const body: Record<string, string> = { status }
  if (decision !== undefined) {
    body.reason = decision.reason ?? ''
    body.next_step = decision.nextStep ?? ''
    body.responsible_role = decision.responsibleRole ?? ''
  }
  return request<SetIdeaStatusResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/status`),
    body,
  )
}

// ── Idea pin (admin) ──────────────────────────────────────────────────────────

/**
 * POST /{boardSlug}/ideas/{ideaId}/pin — pin/unpin an idea (admin only).
 * Requires admin AuthZ + CSRF — call bootstrap() before this.
 * Throws ApiError(403) for non-admin, ApiError(404) for unknown/cross-board idea.
 * Returns { ok: true, pinned: boolean } on success.
 */
export async function setIdeaPinned(
  boardSlug: string,
  ideaId: string | number,
  pinned: boolean,
): Promise<{ ok: boolean; pinned: boolean }> {
  return request<{ ok: boolean; pinned: boolean }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/pin`),
    { pinned },
  )
}

// ── Merging duplicates (moderators) ───────────────────────────────────────────

/** A merge-picker entry; `similarity` is set for suggestions, null for search hits. */
export interface MergeCandidate {
  id: number
  title: string
  status: IdeaStatus
  up_count: number
  down_count: number
  similarity: number | null
  /** true/false once the server's optional confirmation step gave a verdict, null otherwise. */
  confirmed?: boolean | null
}

/**
 * Whether duplicate suggestions get the optional confirmation step of a
 * server extension (issue 11); null/absent = not installed (no UI at all).
 */
export type DuplicateConfirmation = 'available' | 'upgrade_required' | 'disabled'

export interface MergeCandidatesResponse {
  candidates: MergeCandidate[]
  /** Set for suggestions only (never for a title search). */
  confirmation?: DuplicateConfirmation | null
}

/**
 * GET /{boardSlug}/ideas/{ideaId}/merge-candidates?q= — without `query`:
 * deterministic similar-title suggestions (optionally confirmed, see
 * `confirmation`); with `query`: title search.
 * Never contains the idea itself or merged ideas. Moderators only.
 */
export async function getMergeCandidates(
  boardSlug: string,
  ideaId: string | number,
  query = '',
): Promise<MergeCandidatesResponse> {
  const qs = query.trim() !== '' ? `?q=${encodeURIComponent(query.trim())}` : ''
  return request<MergeCandidatesResponse>(
    'GET',
    accountPath(`/${boardSlug}/ideas/${ideaId}/merge-candidates${qs}`),
  )
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/merge — fold this idea into `targetId`
 * (same board). Throws ApiError(422) `merge_invalid_target` /
 * `merge_has_merged_children`. Requires CSRF — call bootstrap() first.
 */
export async function mergeIdea(
  boardSlug: string,
  ideaId: string | number,
  targetId: number,
): Promise<{ ok: boolean; idea_id: number; merged_into: { id: number; title: string } }> {
  return request('POST', accountPath(`/${boardSlug}/ideas/${ideaId}/merge`), {
    target_id: targetId,
  })
}

/** POST /{boardSlug}/ideas/{ideaId}/unmerge — undo a merge, restoring the idea. */
export async function unmergeIdea(
  boardSlug: string,
  ideaId: string | number,
): Promise<{ ok: boolean; idea_id: number }> {
  return request('POST', accountPath(`/${boardSlug}/ideas/${ideaId}/unmerge`), {})
}

// ── Voting ────────────────────────────────────────────────────────────────────

export interface VoteResponse {
  score: number
  my_vote: 'up' | 'down' | 'none'
  up_count: number
  down_count: number
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/vote — cast / flip / retract a vote.
 * direction is sent as `value` field (server accepts 'up' / 'down').
 * Retract = send same direction twice; server handles the toggle.
 * Throws ApiError(401) for anon, ApiError(403) for blocked/CSRF, etc., or
 * ApiError(422) with key `turnstile_required` + `site_key` once the
 * requester's IP is flagged as suspicious (see TurnstileGuard) — resend
 * with turnstileToken set once solved. On a board with
 * anonymous_voting_enabled, an unauthenticated caller gets the same
 * turnstile_required flow on their very first vote (forced challenge for a
 * brand-new dedup cookie, see ADR 0004) rather than only after repeated
 * suspicious activity.
 * `notifyEmail` is relevant only for the anonymous-voting path — a purely
 * optional address for board-update notifications, never used for auth or
 * vote deduplication (ADR 0004).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function vote(
  boardSlug: string,
  ideaId: string | number,
  direction: 'up' | 'down',
  turnstileToken?: string,
  notifyEmail?: string,
): Promise<VoteResponse> {
  return request<VoteResponse>('POST', accountPath(`/${boardSlug}/ideas/${ideaId}/vote`), {
    value: direction,
    ...(turnstileToken !== undefined ? { turnstile_token: turnstileToken } : {}),
    ...(notifyEmail !== undefined && notifyEmail !== '' ? { notify_email: notifyEmail } : {}),
  })
}

/**
 * PATCH /{boardSlug}/ideas/{ideaId}/vote/notify-email — add, change, or
 * clear (pass '') the optional notification email on an anonymous voter's
 * EXISTING vote, decoupled from the vote request itself (ADR 0004 UX
 * follow-up). Requires the anonymous dedup cookie already set by an
 * earlier vote() call — ApiError(404) if no vote exists yet on this idea
 * under that identity, or if the board never opted into anonymous voting.
 * Requires CSRF token — call bootstrap() before this.
 */
export async function updateAnonVoteNotifyEmail(
  boardSlug: string,
  ideaId: string | number,
  email: string,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'PATCH',
    accountPath(`/${boardSlug}/ideas/${ideaId}/vote/notify-email`),
    { notify_email: email },
  )
}

// ── Down-vote reasons (P-03, owner Q4 = 4a) ──────────────────────────────────

/** Fixed reason choices for a vote against. Order = display order. */
export const VOTE_REASON_CHOICES = [
  'not_needed',
  'too_expensive',
  'worsens_workflow',
  'other',
] as const
export type VoteReasonChoice = (typeof VOTE_REASON_CHOICES)[number]

export interface VoteReasonSaved {
  ok: boolean
  reason: VoteReasonChoice
  note: string | null
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/vote/reason — optional reason for the
 * caller's OWN vote against (plain text note, max 200 characters). Refused
 * (409) without a vote against, and on boards that did not enable reasons.
 * The vote itself is never changed. Requires CSRF — call bootstrap() first.
 */
export async function saveVoteReason(
  boardSlug: string,
  ideaId: string | number,
  reason: VoteReasonChoice,
  note: string,
): Promise<VoteReasonSaved> {
  return request<VoteReasonSaved>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/vote/reason`),
    {
      reason,
      note,
    },
  )
}

export interface VoteReasonAggregate {
  enabled: boolean
  down_count: number
  counts: Record<VoteReasonChoice, number>
  notes: { reason: VoteReasonChoice; note: string }[]
}

/**
 * GET /admin/boards/{slug}/ideas/{ideaId}/vote-reasons — operator aggregate
 * for one idea (owner and moderators only). Counts per reason and the free
 * texts, never names or identities.
 */
export async function fetchVoteReasons(
  boardSlug: string,
  ideaId: string | number,
): Promise<VoteReasonAggregate> {
  return request<VoteReasonAggregate>(
    'GET',
    accountPath(`/admin/boards/${boardSlug}/ideas/${ideaId}/vote-reasons`),
  )
}

/**
 * POST /admin/boards/{slug}/ideas/{ideaId}/vote-reasons/note/remove — moderator
 * takedown of one free text (owner answer 22a). The reason choice stays.
 * Addressed by reason and exact text, the values the aggregate returns.
 */
export async function removeVoteReasonNote(
  boardSlug: string,
  ideaId: string | number,
  reason: VoteReasonChoice,
  note: string,
): Promise<void> {
  await request<{ ok: true }>(
    'POST',
    accountPath(`/admin/boards/${boardSlug}/ideas/${ideaId}/vote-reasons/note/remove`),
    { reason, note },
  )
}
