/**
 * Account-scoped board administration: board list/create/onboarding,
 * branding, operator acknowledgment, rename/delete, moderation settings,
 * user blocking, the active-board choice and the board's statuses.
 */

import { accountPath } from '../accountContext'
import { trackEvent } from '../analytics'
import { getEdition } from '../edition'
import type { Entitlements } from '../entitlements'
import { downloadFile, request } from './client'
import type { DuplicateConfirmation } from './ideas'
import type { BoardPhasesInfo, BoardStatusInfo, BoardThemeMode, IdeaStatus } from './model'

// ── Admin: Board list ─────────────────────────────────────────────────────────

export interface AdminBoardSummary {
  id: number
  slug: string
  name: string
  /** Upgrade/downgrade/cancellation lifecycle — set when a downgrade froze this board over the plan limit. */
  frozen_at: string | null
  /** Board-operator-compliance follow-up — set when this board is frozen for missing legal-operator name/acknowledgment. */
  compliance_frozen_at: string | null
  /** Onboarding activation checklist — total ideas ever submitted to this board. */
  idea_count: number
  /** Onboarding activation checklist — total votes ever cast on this board's ideas. */
  vote_count: number
}

/** Onboarding — piggy-backs on GET /admin/boards (see BoardListAction). */
export interface AdminAccountInfo {
  /** ISO-8601, or null until the Setup Wizard is finished/skipped. */
  onboarding_completed_at: string | null
  /** Visibility values allowed on the account's current plan — same source as BrandingData.allowed_visibilities. */
  allowed_visibilities: BoardVisibility[]
  /** Safest visibility the plan allows (PlanPolicy::defaultVisibility) — pre-selected in the create-board form. */
  default_visibility: BoardVisibility
  /**
   * Board-operator-compliance follow-up: whether the account has a COMPLETE
   * legal operator identity (name + address + contact email, migrations/
   * 0064_add_legal_operator_contact.sql — AccountRepository::
   * hasCompleteLegalOperatorIdentity()). Lets a `compliance_frozen_at`
   * board's badge tell apart "fix on the Account page" from "fix on this
   * board's own settings" instead of one generic, non-actionable message.
   */
  has_complete_legal_operator_identity: boolean
}

export interface AdminBoardListResponse {
  boards: AdminBoardSummary[]
  account: AdminAccountInfo
}

/**
 * GET /admin/boards — account-scoped board overview.
 * AuthZ: accountAdmin — throws ApiError(401) for anon, ApiError(403) without
 * account membership (owner/moderator).
 */
export async function listAdminBoards(): Promise<AdminBoardListResponse> {
  return request<AdminBoardListResponse>('GET', accountPath('/admin/boards'))
}

/**
 * POST /admin/onboarding/complete — dismisses the first-run Setup Wizard for
 * the current account (finished or explicitly skipped — the backend doesn't
 * distinguish the two). Idempotent; safe to call more than once.
 * AuthZ: accountAdmin. Requires CSRF — call bootstrap() first.
 */
export async function completeOnboarding(): Promise<{ ok: boolean }> {
  const result = await request<{ ok: boolean }>(
    'POST',
    accountPath('/admin/onboarding/complete'),
    {},
  )
  // Activation goal (measurement plan: "install_completed", site 11) —
  // self-host only; Cloud accounts also call this (same wizard component)
  // but "install_completed" isn't a Cloud goal, see bin/goals.php site 10.
  if (getEdition() === 'community') {
    trackEvent('Community', 'install_completed')
  }
  return result
}

export interface CreateAdminBoardPayload {
  name: string
  slug: string
  /** Optional — omitting it makes the server fall back to the safest visibility the plan allows (fail-secure). */
  visibility?: BoardVisibility
  /**
   * Board-operator-responsibility acknowledgment (board-operator-compliance
   * ticket 05) — required in Cloud (server rejects with 422
   * `operator_acknowledgment_required` if missing/false); ignored in
   * self-host (NullBoardOperatorAcknowledgmentGate never checks it).
   */
  operator_acknowledged?: boolean
  /** Optional — voting without an account (ADR 0004); omitted/false keeps the safe default off. */
  anonymous_voting_enabled?: boolean
  /** Optional — P-03 reason follow-up after a vote against; omitted/false keeps it off. */
  vote_reasons_enabled?: boolean
}

export interface CreateAdminBoardResponse {
  ok: boolean
  slug: string
  name: string
  visibility: BoardVisibility
}

/**
 * POST /admin/boards — creates a new board in the current account.
 * AuthZ: accountAdmin — throws ApiError(401) for anon, ApiError(403) without
 * account membership (owner/moderator). Validation errors (empty/too-long
 * name, invalid/reserved/colliding slug, invalid/plan-disallowed visibility)
 * → ApiError(422) with payload.fields.
 */
export async function createAdminBoard(
  payload: CreateAdminBoardPayload,
): Promise<CreateAdminBoardResponse> {
  const result = await request<CreateAdminBoardResponse>(
    'POST',
    accountPath('/admin/boards'),
    payload,
  )
  // Activation goal (measurement plan: "board_created", sites 10/11) — single
  // call site for both entry points (Setup Wizard + BoardsAdminPage "New
  // board"), so this fires exactly once per actual creation regardless of
  // which UI triggered it.
  trackEvent(getEdition() === 'cloud' ? 'Cloud' : 'Community', 'board_created')
  return result
}

// ── Admin: Branding ───────────────────────────────────────────────────────────

export type BoardVisibility = 'public' | 'unlisted' | 'private'

/** Staged branding fields gated by plan (branding tiers). `primary_color`/board name are ungated. */
export type BrandingField =
  | 'secondary_color'
  | 'logo_url'
  | 'intro'
  | 'hide_badge'
  | 'cover_pattern'
  | 'accent_color'
  | 'primary_color_dark'
  | 'accent_color_dark'
  | 'logo_plate'

/** Logo plate choice on the topbar (v0.1.5); null is the default (automatic). */
export type LogoPlateChoice = 'auto' | 'brand' | 'white' | 'none'

export interface BrandingData {
  board_slug: string
  board_name: string
  /** Upgrade/downgrade/cancellation lifecycle — set when a downgrade froze this board over the plan limit. */
  frozen_at: string | null
  /** Board-operator-compliance freeze (missing legal-operator identity), see BoardBrandingAction. */
  compliance_frozen_at: string | null
  primary_color: string | null
  secondary_color: string | null
  /** Highlight colour (Lite+); null = the primary colour is used. */
  accent_color?: string | null
  /** Per-mode values (Lite+); null = contrast-safe derivation. */
  primary_color_dark?: string | null
  accent_color_dark?: string | null
  /** Plate behind the round logo (Lite+); null = automatic. */
  logo_plate?: LogoPlateChoice | null
  /** Hides the thin edge around the round logo (Lite+). */
  logo_ring_hidden?: boolean
  logo_url: string | null
  /** Uploaded logo rendition id (wins over logo_url); null when none. */
  logo_public_id: string | null
  /** Selected generated cover pattern (P-28c); null keeps the default cover. */
  cover_pattern: string | null
  /** P-26 display mode; not plan-gated. Null on older payloads = system. */
  theme_mode?: BoardThemeMode | null
  intro: string | null
  hide_badge: boolean
  /**
   * Per-board machine-translation opt-in (idea-comment-translation
   * follow-up) — defaults to false, ungated on every plan (disabling it
   * only ever reduces API usage/cost).
   */
  translation_enabled: boolean
  /**
   * Per-board opt-in for voting without an account (ADR 0004) — defaults to
   * false, ungated on every plan. See VoteAction's class doc for the
   * anti-fraud triad (forced Turnstile on a new dedup cookie, IP rate-limit,
   * audit logging) that applies once enabled.
   */
  anonymous_voting_enabled: boolean
  /** P-03: follow-up reason after a vote against (operators see it in aggregate only). Default off. */
  vote_reasons_enabled: boolean
  /** A status change on this board needs a reason (migration 0115). Default off; owner/admin setting. */
  status_reason_required: boolean
  visibility: BoardVisibility
  /** Visibility values allowed on the account's current plan (tier enforcement). */
  allowed_visibilities: BoardVisibility[]
  /** Staged branding fields allowed on the account's current plan (branding tiers). */
  allowed_branding_fields: BrandingField[]
  /** P-38: plan name and per-feature lock state (account members only). */
  entitlements?: Entitlements
  /**
   * Optional confirmation of duplicate suggestions by a server extension
   * (issue 11): 'available' | 'upgrade_required' | 'disabled' (opt-in, the
   * account owner has not switched it on, issue 25); null/absent when none is
   * installed (Community) — then nothing is shown.
   */
  duplicate_confirmation?: DuplicateConfirmation | null
}

/**
 * GET /admin/boards/{slug}/branding — fetch current branding settings.
 * AuthZ: admin — throws ApiError(401) for anon, ApiError(403) for non-admin.
 */
export async function getAdminBranding(slug: string): Promise<BrandingData> {
  return request<BrandingData>('GET', accountPath(`/admin/boards/${slug}/branding`))
}

/**
 * POST /admin/boards/{slug}/branding — persist branding.
 * Pass empty string to clear a color/logo/intro field (server treats '' as
 * "reset to default"); `hide_badge: false` clears the badge-hide switch.
 * `visibility`, `translation_enabled` and `anonymous_voting_enabled` are
 * optional — omit any of them to leave it unchanged. ApiError(422) with
 * fields.{secondary_color,logo_url,intro,hide_badge,visibility} if the plan
 * does not allow the requested value (tier enforcement) —
 * `translation_enabled`/`anonymous_voting_enabled` are never plan-gated.
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function saveAdminBranding(
  slug: string,
  data:
    | {
        primary_color: string
        /** Omit a plan-locked field: the server keeps its stored value (Q19). */
        secondary_color?: string
        accent_color?: string
        primary_color_dark?: string
        accent_color_dark?: string
        logo_plate?: LogoPlateChoice
        logo_ring_hidden?: boolean
        logo_url?: string
        cover_pattern?: string
        /** P-26 display mode; never plan-gated. */
        theme_mode?: BoardThemeMode
        intro?: string
        hide_badge?: boolean
        visibility?: BoardVisibility
        translation_enabled?: boolean
        anonymous_voting_enabled?: boolean
        vote_reasons_enabled?: boolean
        status_reason_required?: boolean
      }
    // Frozen board (Q1=b): the single accepted write is switching to private.
    | { visibility: 'private' },
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/boards/${slug}/branding`), data)
}

// ── Admin: Board-operator acknowledgment ────────────────────────────────────

/**
 * board-operator-compliance ticket 05 follow-up — the ongoing counterpart to
 * BoardCreateAction's one-time acknowledgment. Cloud-only concept: on
 * self-host, `acknowledged` is always false and the POST below always
 * succeeds without persisting anything.
 */
export interface OperatorAcknowledgmentStatus {
  acknowledged: boolean
  acknowledged_at: string | null
  acknowledged_by_user_id: number | null
}

/**
 * GET /admin/boards/{slug}/operator-acknowledgment — fetch the board's
 * current acknowledgment status.
 * AuthZ: admin — throws ApiError(401) for anon, ApiError(403) for non-admin.
 */
export async function getAdminOperatorAcknowledgment(
  slug: string,
): Promise<OperatorAcknowledgmentStatus> {
  return request<OperatorAcknowledgmentStatus>(
    'GET',
    accountPath(`/admin/boards/${slug}/operator-acknowledgment`),
  )
}

/**
 * POST /admin/boards/{slug}/operator-acknowledgment — retroactively records
 * the acknowledgment. Idempotent: a no-op (still 200, current status) once
 * already acknowledged. ApiError(422) `error.key === 'operator_acknowledgment_required'`
 * if the flag is missing/false.
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function acknowledgeAdminBoardOperator(
  slug: string,
): Promise<OperatorAcknowledgmentStatus> {
  return request<OperatorAcknowledgmentStatus>(
    'POST',
    accountPath(`/admin/boards/${slug}/operator-acknowledgment`),
    { operator_acknowledged: true },
  )
}

/**
 * PUT /admin/boards/{slug} — renames a board's title and/or slug. Both
 * fields are independent and optional — pass only the one(s) being
 * changed; an omitted field keeps its current value (name reuse across
 * slugs is allowed, only the slug is unique per account). On success the
 * board now lives at `slug` (the caller must update its own routing/state
 * to the returned slug). ApiError(422) with fields.{name,slug} on
 * validation/collision, ApiError(423) if the board is downgrade-frozen.
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function renameAdminBoard(
  slug: string,
  data: { name?: string; slug?: string },
): Promise<{ ok: boolean; slug: string; name: string }> {
  return request<{ ok: boolean; slug: string; name: string }>(
    'PUT',
    accountPath(`/admin/boards/${slug}`),
    data,
  )
}

/**
 * POST /admin/boards/{slug}/delete — permanently deletes the board (ideas,
 * votes, comments, tokens — everything hanging off it) and frees up the
 * plan's board-count slot (billing is by boards created, not by how many
 * are currently active/frozen). `confirmSlug` must equal `slug` exactly —
 * re-validated server-side, the client-typed text is a UX affordance only.
 * AuthZ: accountOwner (stricter than branding/moderation's accountAdmin) +
 * CSRF — call bootstrap() first. ApiError(422, 'confirmation_mismatch') if
 * confirmSlug doesn't match, ApiError(404) if the board doesn't exist.
 */
export async function deleteAdminBoard(
  slug: string,
  confirmSlug: string,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/boards/${slug}/delete`), {
    confirm_slug: confirmSlug,
  })
}

// ── Admin: Moderation ─────────────────────────────────────────────────────────

export interface ModerationWord {
  id: number
  word: string
}

export interface ModerationBaseLists {
  de: number
  en: number
}

export interface ModerationData {
  board_slug: string
  board_name: string
  moderation_enabled: boolean
  words: ModerationWord[]
  /** Word counts of the always-on vendored DE/EN base blocklists (transparency, never the words themselves). */
  base_lists: ModerationBaseLists
  /**
   * Pre-moderation mode (migration 0083): 'all' = every new idea waits for
   * approval, 'anonymous' = only ideas submitted without an account wait.
   */
  premoderation: PremoderationMode
  /** Ideas currently waiting in the approval queue. */
  pending_count: number
  /**
   * Guest idea submission (ADR 0006): the board switch plus the
   * preconditions to switch it on. Absent on older payloads;
   * `instance_allowed` is absent on payloads from before the
   * installation-wide kill switch.
   */
  anonymous_submission?: {
    enabled: boolean
    instance_allowed?: boolean
    turnstile_configured: boolean
    plan_allowed: boolean
  }
}

/** Pre-moderation modes the server accepts (Premoderation::MODES). */
export type PremoderationMode = 'off' | 'all' | 'anonymous'

export interface ModerationBulkAddResult {
  ok: boolean
  added: number
  skipped: number
}

export type ModerationAction =
  | { action: 'toggle'; moderation_enabled: '1' | '0' }
  | { action: 'add'; new_word: string }
  | { action: 'bulk_add'; words_blob: string }
  | { action: 'remove'; word_id: number }
  | {
      action: 'premoderation'
      premoderation: PremoderationMode
      /** Required to switch review off while guests can submit (ADR 0006 §8). */
      confirm_guest_public?: boolean
    }
  | { action: 'anonymous_submission'; anonymous_submission_enabled: '1' | '0' }

/**
 * GET /admin/boards/{slug}/moderation — fetch moderation settings.
 * AuthZ: admin — throws ApiError(401) for anon, ApiError(403) for non-admin.
 */
export async function getAdminModeration(slug: string): Promise<ModerationData> {
  return request<ModerationData>('GET', accountPath(`/admin/boards/${slug}/moderation`))
}

/**
 * POST /admin/boards/{slug}/moderation — apply a moderation action.
 *   action='toggle':   saves moderation_enabled flag.
 *   action='add':      adds a word to the board blocklist.
 *   action='bulk_add': adds many words at once (newline/comma/semicolon separated),
 *                       resolves with { ok, added, skipped }.
 *   action='remove':   removes a word by id.
 * Throws ApiError(422) with fields map on validation failure (empty word etc.).
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function saveAdminModeration(
  slug: string,
  data: ModerationAction,
): Promise<ModerationBulkAddResult | { ok: boolean }> {
  return request<ModerationBulkAddResult | { ok: boolean }>(
    'POST',
    accountPath(`/admin/boards/${slug}/moderation`),
    data,
  )
}

// ── Pre-moderation approval queue (broaden-use-cases issue 05) ───────────────

export interface ModerationQueueIdea {
  id: number
  title: string
  body: string
  created_at: string
  /** 'guest' = submitted without an account (ADR 0006). Absent on older payloads. */
  author_type?: 'guest' | 'member'
}

export interface ModerationQueueData {
  board: { slug: string; name: string; premoderation: PremoderationMode }
  count: number
  /** Waiting ideas submitted without an account (ADR 0006). */
  guest_count?: number
  ideas: ModerationQueueIdea[]
}

/**
 * GET /admin/boards/{slug}/queue — the board's ideas waiting for approval.
 * AuthZ: accountModerate — throws ApiError(401) for anon, ApiError(403) for
 * members without owner/admin/moderator role, ApiError(404) for a foreign board.
 */
export async function getModerationQueue(slug: string): Promise<ModerationQueueData> {
  return request<ModerationQueueData>('GET', accountPath(`/admin/boards/${slug}/queue`))
}

/**
 * POST /admin/boards/{slug}/queue/approve — publish the given pending ideas.
 * Resolves with the ids that were actually approved (ids already handled or
 * not belonging to this board are skipped). Rejecting uses moderateIdea().
 * Requires accountModerate AuthZ + CSRF — call bootstrap() first.
 */
export async function approveModerationQueue(
  slug: string,
  ids: number[],
): Promise<{ ok: boolean; approved: number[] }> {
  return request<{ ok: boolean; approved: number[] }>(
    'POST',
    accountPath(`/admin/boards/${slug}/queue/approve`),
    { ids },
  )
}

/**
 * POST /admin/boards/{slug}/queue/approve-and-trust — publish one pending
 * idea and mark its author as trusted in this account (broaden-use-cases
 * issue 11): their next ideas skip the queue. Never for guest ideas — the
 * server answers 422 `author_not_trustable` (also for a deleted author) or
 * `author_not_in_good_standing`. Requires accountModerate AuthZ + CSRF.
 */
export async function approveAndTrustAuthor(
  slug: string,
  id: number,
): Promise<{ ok: boolean; approved: number[]; trusted: boolean }> {
  return request<{ ok: boolean; approved: number[]; trusted: boolean }>(
    'POST',
    accountPath(`/admin/boards/${slug}/queue/approve-and-trust`),
    { id },
  )
}

/**
 * POST /admin/boards/{slug}/moderation/guest-reject-all — rejects every
 * waiting idea submitted without an account (ADR 0006 §5a); members' ideas
 * are never touched. Requires accountModerate AuthZ + CSRF.
 */
export async function rejectAllGuestIdeas(
  slug: string,
): Promise<{ ok: boolean; rejected: number }> {
  return request<{ ok: boolean; rejected: number }>(
    'POST',
    accountPath(`/admin/boards/${slug}/moderation/guest-reject-all`),
    { confirm: true },
  )
}

// ── User block (admin, account-wide) ──────────────────────────────────────────

export type BlockScope = 'account' | 'board'

/**
 * POST /admin/boards/{boardSlug}/block — block/unblock a user (admin only).
 * Requires accountAdmin AuthZ + CSRF — call bootstrap() before this.
 * `boardSlug` resolves the account/AuthZ context. `scope` selects the block
 * range: 'account' (default) applies to every board of the account, 'board'
 * restricts it to this one board.
 * Throws ApiError(403) for non-admin, ApiError(404) for unknown board/user.
 * Returns { ok: true, blocked: boolean } on success.
 */
export async function blockUser(
  boardSlug: string,
  userId: number,
  blocked: boolean,
  scope: BlockScope = 'account',
): Promise<{ ok: boolean; blocked: boolean }> {
  return request<{ ok: boolean; blocked: boolean }>(
    'POST',
    accountPath(`/admin/boards/${boardSlug}/block`),
    { user_id: userId, blocked, scope },
  )
}

// ── Account: active boards ────────────────────────────────────────────────────

/**
 * POST /admin/boards/active-set — board picker for installations whose plan
 * policy caps active boards: pick exactly the board IDs that should stay
 * unfrozen; every other board of this account gets frozen. AuthZ:
 * accountOwner. ApiError(422, 'plan_limit_boards') if more boards are chosen
 * than the current plan allows. Core ships no UI for this (its default plan
 * policy is unlimited); a plan-limiting extension renders the picker.
 */
export async function setActiveBoards(
  boardIds: number[],
): Promise<{ ok: boolean; active_board_ids: number[] }> {
  return request<{ ok: boolean; active_board_ids: number[] }>(
    'POST',
    accountPath('/admin/boards/active-set'),
    { board_ids: boardIds },
  )
}

// ── Admin: Statuses (broaden-use-cases issue 03) ─────────────────────────────

export interface BoardStatusPayload {
  category?: IdeaStatus
  label_de?: string | null
  label_en?: string | null
  is_hidden?: boolean
  allow_votes?: boolean
  allow_comments?: boolean
}

/**
 * GET /admin/boards/{slug}/statuses — the board's status registry incl. idea
 * counts. AuthZ: accountAdmin (moderators only change an idea's status).
 */
export async function getAdminStatuses(slug: string): Promise<{ statuses: BoardStatusInfo[] }> {
  return request<{ statuses: BoardStatusInfo[] }>(
    'GET',
    accountPath(`/admin/boards/${slug}/statuses`),
  )
}

/**
 * POST /admin/boards/{slug}/statuses — create a custom status (needs
 * `category` + at least one label). ApiError(422) with `fields`, or
 * `status_limit_reached`.
 */
export async function createAdminStatus(
  slug: string,
  data: BoardStatusPayload,
): Promise<{ statuses: BoardStatusInfo[]; key: string }> {
  return request<{ statuses: BoardStatusInfo[]; key: string }>(
    'POST',
    accountPath(`/admin/boards/${slug}/statuses`),
    data,
  )
}

/** PUT /admin/boards/{slug}/statuses/{key} — labels, hidden, vote/comment flags. */
export async function updateAdminStatus(
  slug: string,
  key: string,
  data: BoardStatusPayload,
): Promise<{ statuses: BoardStatusInfo[] }> {
  return request<{ statuses: BoardStatusInfo[] }>(
    'PUT',
    accountPath(`/admin/boards/${slug}/statuses/${key}`),
    data,
  )
}

/**
 * POST /admin/boards/{slug}/statuses/{key}/delete — delete a custom status.
 * Ideas still in it need `moveTo` (another visible status of the same
 * category); otherwise ApiError(409, 'status_in_use').
 */
export async function deleteAdminStatus(
  slug: string,
  key: string,
  moveTo?: string,
): Promise<{ statuses: BoardStatusInfo[] }> {
  return request<{ statuses: BoardStatusInfo[] }>(
    'POST',
    accountPath(`/admin/boards/${slug}/statuses/${key}/delete`),
    moveTo !== undefined ? { move_to: moveTo } : {},
  )
}

// ── Admin: Deadlines (broaden-use-cases issue 04) ────────────────────────────

/** The four optional bounds — ISO 8601 with an explicit offset, null = no bound. */
export interface BoardPhaseBounds {
  submissions_opens_at: string | null
  submissions_closes_at: string | null
  voting_opens_at: string | null
  voting_closes_at: string | null
}

export interface BoardPhasesSettings extends BoardPhaseBounds {
  /** Server-computed state per phase (server clock is authoritative). */
  phases: BoardPhasesInfo
  /** The server's "now" (ISO UTC) the phases were computed against. */
  now: string
  /** Statuses whose allow_votes flag closes voting regardless of the window. */
  statuses_closed_for_votes: BoardStatusInfo[]
}

/**
 * GET /admin/boards/{slug}/phases — the board's optional submission/voting
 * windows plus their effective state. AuthZ: accountAdmin.
 */
export async function getAdminPhases(slug: string): Promise<BoardPhasesSettings> {
  return request<BoardPhasesSettings>('GET', accountPath(`/admin/boards/${slug}/phases`))
}

/**
 * POST /admin/boards/{slug}/phases — full replacement of the four bounds
 * (null clears one). ApiError(422, 'validation_error') with `fields` when a
 * value is invalid or a start is not before its end.
 */
export async function saveAdminPhases(
  slug: string,
  bounds: BoardPhaseBounds,
): Promise<BoardPhasesSettings> {
  return request<BoardPhasesSettings>('POST', accountPath(`/admin/boards/${slug}/phases`), bounds)
}

// ── Admin: Results export (broaden-use-cases issue 07) ───────────────────────

export type ResultsExportSort = 'top' | 'newest'

export interface ResultsExportOptions {
  sort: ResultsExportSort
  /** Category filter; omitted = every status except archived (board-list rule). */
  status?: IdeaStatus
  /** UI language: 'de' → German status labels and `;` (German Excel), else English + `,`. */
  lang: string
}

/**
 * GET /admin/boards/{slug}/export — the board's published ideas with their
 * results as one CSV download (no author identities). AuthZ: accountAdmin,
 * rate limited (`board:export`). Throws ApiError on a non-2xx status.
 */
export async function downloadBoardResultsCsv(
  slug: string,
  options: ResultsExportOptions,
): Promise<void> {
  const params = new URLSearchParams({ sort: options.sort, lang: options.lang })
  if (options.status) params.set('status', options.status)
  await downloadFile(
    accountPath(`/admin/boards/${slug}/export?${params.toString()}`),
    'text/csv',
    `votepit-results-${slug}.csv`,
  )
}
