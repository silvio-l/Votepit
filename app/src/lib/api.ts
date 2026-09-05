/**
 * Votepit API client
 *
 * All requests use `credentials: 'include'` (session cookie auth).
 * Mutating requests send the cached CSRF token as `X-CSRF-Token`.
 * Call `bootstrap()` on app mount to seed the CSRF token and user state.
 */

import { accountPath } from './accountContext'
import { trackEvent } from './analytics'
import { getEdition } from './edition'
import type { Features } from './features'

const API_BASE = '/api'

/** Module-level CSRF token — populated by bootstrap(). */
let cachedCsrfToken: string | null = null

// ── Types ────────────────────────────────────────────────────────────────────

/** Status values as returned by the PHP backend (underscore variant). */
export type IdeaStatus = 'open' | 'planned' | 'in_progress' | 'done' | 'declined'

export interface Idea {
  id: number
  board_id: number
  author_id: number
  title: string
  body: string
  status: IdeaStatus
  is_pinned: boolean
  score_cache: number
  /**
   * View counter (migrations/0049_add_ideas_view_count.sql) — an
   * X/Twitter-style "impressions" signal, deduped server-side without
   * cookies (IdeaViewTracker). Reflects state as of the read, i.e. lags one
   * view behind on a visitor's own first request to this page.
   */
  view_count: number
  created_at: string
  updated_at: string
  comment_count: number
  up_count: number
  down_count: number
  my_vote?: 'up' | 'down' | 'none'
}

export interface BoardData {
  id: number
  slug: string
  name: string
  intro: string
  /**
   * Whether the "Powered by Votepit" badge should render (branding
   * tiers). Already re-checks the account's CURRENT plan
   * server-side (BoardHomeAction) — true unless the account is on a plan
   * that allows hiding the badge AND has actually set `hide_badge`.
   */
  show_badge: boolean
  /**
   * Branding tokens, already re-checked against the account's CURRENT plan
   * server-side (BoardHomeAction) — null when unset OR when the account is
   * on a plan that doesn't allow the field (downgrade safeguard).
   * `primary_color` is ungated on every plan. Only --vp-* brand tokens;
   * semantic vote/status colors are never overridable here.
   */
  primary_color: string | null
  secondary_color: string | null
  logo_url: string | null
}

export interface PaginationMeta {
  page: number
  total_pages: number
}

/** Board-wide "this week" aggregates for the FeaturedIdeaCard, plus the all-time idea total for milestone celebrations. */
export interface BoardStats {
  weekly_votes: number
  weekly_new_ideas: number
  avg_consensus: number
  total_ideas: number
}

export interface BoardResponse {
  board: BoardData
  ideas: Idea[]
  stats: BoardStats
  active_status: string | null
  active_sort: string
  page: number
  total_pages: number
  is_authenticated: boolean
}

export interface AccountMembership {
  account_slug: string
  role: AccountRole
}

export interface User {
  id: number
  /** Random opaque handle, safe to display — `id` above is internal-only. */
  public_id: string | null
  /** Optional, globally unique display name the user chose (Account settings) — prefer this over public_id when set. */
  username: string | null
  is_admin: boolean
  is_operator: boolean
  /** Trusted-helper tier below is_operator — customer support (tickets, FAQ) only. */
  is_support: boolean
  /** Dedicated QA/E2E account, never a real customer — App.tsx skips Matomo entirely for it. */
  is_test_account: boolean
  /** Whether a password is already set — drives "set" vs "change" in ProfilePage. */
  has_password: boolean
  /** Whether TOTP 2FA is active — drives whether ProfilePage shows the setup wizard or the manage view. */
  totp_enabled: boolean
  /** Own avatar URL (profile-avatar-social), or null if none set — same value as ProfileData.avatar_url. */
  avatar_url: string | null
  /**
   * Own privacy setting (profile-visibility feature) — false (default) means
   * anonymous: other users see only a generic placeholder for this user's
   * idea/comment authorship and profile, never the avatar or social links.
   */
  profile_visible: boolean
  /** Account role per slug — use this (not is_admin) to gate account-owner/admin UI. */
  memberships: AccountMembership[]
}

/**
 * Looks up the caller's role for the current account slug (null in
 * self-host mode — self-host is always exactly one account, so its single
 * membership applies regardless of slug), or null if not a member.
 */
export function accountRoleFor(user: User | null, accountSlug: string | null): AccountRole | null {
  if (!user) return null
  const memberships = user.memberships ?? []
  if (accountSlug === null) return memberships[0]?.role ?? null
  return memberships.find((m) => m.account_slug === accountSlug)?.role ?? null
}

export interface BootstrapData {
  csrf_token: string
  user: User | null
  /**
   * 'cloud' means board-/admin-scoped paths carry a leading /{accountSlug}
   * segment (Config::routingMode, cloud path routing) — see
   * accountContext.ts / App.tsx ScopedLayout, which read this once on mount.
   */
  routing_mode: 'self-host' | 'cloud'
  /** Client-facing Sentry DSN for @sentry/react (main.tsx), or '' to disable. Not sensitive. */
  sentry_dsn_frontend: string
  /** This installation's own optional analytics (Config::matomoUrl/matomoSiteId), '' disables it. Not sensitive. */
  matomo_url: string
  matomo_site_id: string
  /**
   * Self-host-only product-improvement telemetry state (null in cloud mode —
   * see Votepit\Telemetry\CommunityTelemetry). `opted_in` reflects the
   * Setup Wizard's opt-out toggle (default true); matomo_url/matomo_site_id
   * are already '' when not opted in, so lib/analytics.ts never needs to
   * re-check `opted_in` itself.
   */
  telemetry: {
    decided: boolean
    opted_in: boolean
    matomo_url: string
    matomo_site_id: string
  } | null
  /** Installation capabilities (per-board SMTP, footer legal links, extension flags) — see lib/features.ts. */
  features: Features
}

export interface Comment {
  id: number
  idea_id: number
  author_id: number
  body: string
  created_at: string
  edited_at: string | null
}

export interface IdeaDetailResponse {
  board: {
    id: number
    slug: string
    name: string
  }
  idea: Idea
  comments: Comment[]
  is_authenticated: boolean
}

export interface ApiErrorPayload {
  key: string
  message: string
  fields?: Record<string, string>
}

export class ApiError extends Error {
  readonly status: number
  readonly payload: ApiErrorPayload

  constructor(status: number, payload: ApiErrorPayload) {
    super(payload.message)
    this.name = 'ApiError'
    this.status = status
    this.payload = payload
  }
}

// ── Request helper ────────────────────────────────────────────────────────────

/**
 * JSON request with CSRF header + cookie credentials. Exported for SPA
 * extensions (`@votepit/app-extensions`) so their API clients share the
 * same error handling and CSRF seeding; core pages use the typed wrappers
 * below.
 */
export async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    if (cachedCsrfToken !== null) {
      headers['X-CSRF-Token'] = cachedCsrfToken
    }
  }

  const resp = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: body !== undefined ? JSON.stringify(body) : undefined,
  })

  if (!resp.ok) {
    let errPayload: ApiErrorPayload = {
      key: 'http_error',
      message: resp.statusText || `HTTP ${resp.status}`,
    }
    try {
      const json = (await resp.json()) as { error?: ApiErrorPayload }
      if (json.error && typeof json.error.key === 'string') {
        errPayload = json.error
      }
    } catch {
      // leave default payload
    }
    throw new ApiError(resp.status, errPayload)
  }

  return resp.json() as Promise<T>
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Fetches CSRF token + current user.
 * Must be called before any mutating request to seed cachedCsrfToken.
 */
export async function bootstrap(): Promise<BootstrapData> {
  const data = await request<BootstrapData>('GET', `${API_BASE}/bootstrap`)
  cachedCsrfToken = data.csrf_token
  return data
}

export interface GetBoardParams {
  sort?: string
  status?: string
  page?: number
}

/**
 * GET /{boardSlug} — board home + paginated idea list.
 * Query params: sort, status, page.
 */
export async function getBoard(boardSlug: string, params?: GetBoardParams): Promise<BoardResponse> {
  const search = new URLSearchParams()
  if (params?.sort) search.set('sort', params.sort)
  if (params?.status) search.set('status', params.status)
  if (params?.page && params.page > 1) search.set('page', String(params.page))
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
  score_cache: number
  created_at: string
  comment_count: number
  up_count: number
  down_count: number
}

export interface RoadmapGroups {
  planned: RoadmapIdea[]
  in_progress: RoadmapIdea[]
  done: RoadmapIdea[]
}

export interface RoadmapResponse {
  board: BoardData
  groups: RoadmapGroups
}

/**
 * GET /{boardSlug}/roadmap — board-scoped, read-only Roadmap.
 * Returns ideas grouped by planned / in_progress / done (each score_cache DESC).
 * No voter PII; accessible anonymously.
 */
export async function getRoadmap(boardSlug: string): Promise<RoadmapResponse> {
  return request<RoadmapResponse>('GET', accountPath(`/${boardSlug}/roadmap`))
}

/** Low-level helpers re-exported for pages that need raw GET/POST/PUT/DELETE. */
export const api = {
  get: <T>(path: string) => request<T>('GET', `${API_BASE}${path}`),
  post: <T>(path: string, body?: unknown) => request<T>('POST', `${API_BASE}${path}`, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', `${API_BASE}${path}`, body),
  delete: <T>(path: string) => request<T>('DELETE', `${API_BASE}${path}`),
}

// ── Auth ──────────────────────────────────────────────────────────────────────

/**
 * POST /login — requests a magic-link email.
 * Body fields: email (required), r (optional return-to path).
 * Always returns 200 {ok: true} regardless of whether the email is valid
 * (anti-enumeration; AC3/4 in LoginActionTest).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function requestMagicLink(email: string, returnTo?: string): Promise<{ ok: boolean }> {
  const body: Record<string, string> = { email }
  if (returnTo) body.r = returnTo
  return request<{ ok: boolean }>('POST', '/login', body)
}

/** Either a completed login (session cookie set by the server) or a pending-2FA handoff. */
export type LoginResult =
  | { ok: true; redirect: string }
  | { requires_2fa: true; pending_token: string }

/**
 * GET /login/verify?token=<plaintext>[&r=<returnTo>] — verifies a magic-link token.
 * On success WITHOUT TOTP: 200 {ok: true, redirect} (session cookie set by server).
 * On success WITH TOTP active: 200 {requires_2fa: true, pending_token} — no
 * session yet, the caller must complete POST /login/2fa (see loginWith2fa).
 * On failure: throws ApiError(400) with key=invalid_token.
 * GET is CSRF-exempt (single-use capability token is its own proof).
 */
export async function verifyToken(token: string, returnTo?: string): Promise<LoginResult> {
  const params = new URLSearchParams({ token })
  if (returnTo) params.set('r', returnTo)
  return request<LoginResult>('GET', `/login/verify?${params.toString()}`)
}

/**
 * POST /login/password — email + password login (additive to the magic-link
 * flow, never a replacement). Same LoginResult shape as verifyToken: either
 * an immediate session, or a pending-2FA handoff when TOTP is active.
 * On failure (wrong credentials, unknown user, blocked): throws ApiError(401)
 * with a generic key=invalid_credentials message — never distinguishes
 * "unknown email" from "wrong password" (anti-enumeration).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function loginWithPassword(
  email: string,
  password: string,
  returnTo?: string,
): Promise<LoginResult> {
  const body: Record<string, string> = { email, password }
  if (returnTo) body.r = returnTo
  return request<LoginResult>('POST', '/login/password', body)
}

/**
 * POST /login/2fa — second factor after verifyToken/loginWithPassword
 * returned {requires_2fa: true, pending_token}. Pass EITHER a 6-digit TOTP
 * code OR a backup code, never both. On success: 200 {ok: true, redirect}.
 * On failure: throws ApiError(400) with key=invalid_code or
 * key=invalid_pending_token — no side effect, the pending token stays usable.
 * Requires CSRF token — call bootstrap() before this.
 */
export async function loginWith2fa(
  pendingToken: string,
  credential: { code: string } | { backupCode: string },
  returnTo?: string,
): Promise<{ ok: boolean; redirect: string }> {
  const body: Record<string, string> = { pending_token: pendingToken }
  if ('code' in credential) body.code = credential.code
  else body.backup_code = credential.backupCode
  if (returnTo) body.r = returnTo
  return request<{ ok: boolean; redirect: string }>('POST', '/login/2fa', body)
}

/**
 * POST /logout — invalidates the current session (bumps token_version).
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 */
export async function logout(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/logout', {})
}

// ── Idea submission ───────────────────────────────────────────────────────────

/**
 * Response shape for GET /{boardSlug}/ideas/new.
 * form_at is the server-signed Time-Trap stamp; send it back as _form_at in the POST.
 */
export interface SubmitFormData {
  board: { id: number; slug: string; name: string }
  is_authenticated: boolean
  form_at: string
}

export interface CreateIdeaResponse {
  ok: boolean
  id: number
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
}

export interface SearchDuplicatesResponse {
  candidates: DuplicateCandidate[]
}

/**
 * GET /{boardSlug}/ideas/search-duplicates?title=... — as-you-type duplicate
 * recall for the submit form. Board-scoped; surfacing only, no
 * auto-merge. Requires auth (matches the surrounding submit flow) and a valid
 * CSRF-authenticated session — call bootstrap() first.
 * Returns an empty candidate list for very short titles or when nothing clears
 * the similarity threshold — never throws for "no results".
 */
export async function searchDuplicates(
  boardSlug: string,
  title: string,
): Promise<SearchDuplicatesResponse> {
  const params = new URLSearchParams({ title })
  return request<SearchDuplicatesResponse>(
    'GET',
    accountPath(`/${boardSlug}/ideas/search-duplicates?${params.toString()}`),
  )
}

/**
 * POST /{boardSlug}/ideas — create a new idea.
 * website must always be '' (honeypot — server rejects non-empty).
 * _form_at must be the stamp returned by getSubmitForm() (Time-Trap — server validates HMAC + elapsed time).
 * Requires auth (AuthZ: user → 401 if anonymous) and a valid CSRF token.
 * Call bootstrap() before this to ensure cachedCsrfToken is populated.
 * Throws ApiError(422) with fields map on validation / moderation / anti-spam failure.
 */
export async function createIdea(
  boardSlug: string,
  payload: { title: string; body: string; website: string; _form_at: string },
): Promise<CreateIdeaResponse> {
  return request<CreateIdeaResponse>('POST', accountPath(`/${boardSlug}/ideas`), payload)
}

// ── Idea editing & withdrawal ─────────────────────────────────────────────────

export interface GetIdeaForEditResponse {
  board: { id: number; slug: string; name: string }
  idea: Idea
  is_authenticated: boolean
  form_at: string
}

export interface UpdateIdeaPayload {
  title: string
  body: string
  website: string // honeypot — always ''
  _form_at: string // time-trap stamp from GET /edit
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
 * ApiError(403) if blocked, ApiError(404) if the board/idea is unknown.
 */
export async function createComment(
  boardSlug: string,
  ideaId: string | number,
  body: string,
): Promise<CreateCommentResponse> {
  return request<CreateCommentResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments`),
    { body },
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

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments/{commentId}/delete — remove a
 * comment (admin-only moderation).
 * Requires accountAdmin AuthZ + CSRF — call bootstrap() before this.
 * Throws ApiError(403) for non-admin, ApiError(404) for unknown/cross-board comment.
 */
export async function deleteComment(
  boardSlug: string,
  ideaId: string | number,
  commentId: string | number,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments/${commentId}/delete`),
    {},
  )
}

// ── Admin: Board list ─────────────────────────────────────────────────────────

export interface AdminBoardSummary {
  id: number
  slug: string
  name: string
  /** Upgrade/downgrade/cancellation lifecycle — set when a downgrade froze this board over the plan limit. */
  frozen_at: string | null
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

// ── Admin: Branding ───────────────────────────────────────────────────────────

export type BoardVisibility = 'public' | 'unlisted' | 'private'

/** Staged branding fields gated by plan (branding tiers). `primary_color`/board name are ungated. */
export type BrandingField = 'secondary_color' | 'logo_url' | 'intro' | 'hide_badge'

export interface BrandingData {
  board_slug: string
  board_name: string
  /** Upgrade/downgrade/cancellation lifecycle — set when a downgrade froze this board over the plan limit. */
  frozen_at: string | null
  primary_color: string | null
  secondary_color: string | null
  logo_url: string | null
  intro: string | null
  hide_badge: boolean
  visibility: BoardVisibility
  /** Visibility values allowed on the account's current plan (tier enforcement). */
  allowed_visibilities: BoardVisibility[]
  /** Staged branding fields allowed on the account's current plan (branding tiers). */
  allowed_branding_fields: BrandingField[]
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
 * `visibility` is optional — omit to leave it unchanged. ApiError(422) with
 * fields.{secondary_color,logo_url,intro,hide_badge,visibility} if the plan
 * does not allow the requested value (tier enforcement).
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function saveAdminBranding(
  slug: string,
  data: {
    primary_color: string
    secondary_color: string
    logo_url: string
    intro: string
    hide_badge: boolean
    visibility?: BoardVisibility
  },
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/boards/${slug}/branding`), data)
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

export interface ModerationData {
  board_slug: string
  board_name: string
  moderation_enabled: boolean
  words: ModerationWord[]
}

export type ModerationAction =
  | { action: 'toggle'; moderation_enabled: '1' | '0' }
  | { action: 'add'; new_word: string }
  | { action: 'remove'; word_id: number }

/**
 * GET /admin/boards/{slug}/moderation — fetch moderation settings.
 * AuthZ: admin — throws ApiError(401) for anon, ApiError(403) for non-admin.
 */
export async function getAdminModeration(slug: string): Promise<ModerationData> {
  return request<ModerationData>('GET', accountPath(`/admin/boards/${slug}/moderation`))
}

/**
 * POST /admin/boards/{slug}/moderation — apply a moderation action.
 *   action='toggle': saves moderation_enabled flag.
 *   action='add':    adds a word to the board blocklist.
 *   action='remove': removes a word by id.
 * Throws ApiError(422) with fields map on validation failure (empty word etc.).
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function saveAdminModeration(
  slug: string,
  data: ModerationAction,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/boards/${slug}/moderation`), data)
}

// ── Idea status (admin) ───────────────────────────────────────────────────────

/**
 * POST /{boardSlug}/ideas/{ideaId}/status — set idea status (admin only).
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 * Throws ApiError(403) for non-admin, ApiError(422) for invalid transition.
 * Returns { ok: true, status: IdeaStatus } on success.
 */
export async function setIdeaStatus(
  boardSlug: string,
  ideaId: string | number,
  status: IdeaStatus,
): Promise<{ ok: boolean; status: IdeaStatus }> {
  return request<{ ok: boolean; status: IdeaStatus }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/status`),
    { status },
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
 * Throws ApiError(401) for anon, ApiError(403) for blocked/CSRF, etc.
 * Requires CSRF token — call bootstrap() before this.
 */
export async function vote(
  boardSlug: string,
  ideaId: string | number,
  direction: 'up' | 'down',
): Promise<VoteResponse> {
  return request<VoteResponse>('POST', accountPath(`/${boardSlug}/ideas/${ideaId}/vote`), {
    value: direction,
  })
}

// ── Admin: Board-SMTP ─────────────────────────────────────────────────────────

export interface SmtpTestBody {
  /** Recipient of the test email — required field (backend no longer reads it from the session). */
  to: string
  host?: string
  port?: number
  user?: string
  encryption?: 'tls' | 'ssl' | ''
  from_email?: string
  from_name?: string
  password?: string
}

export interface BoardSmtpSettingsData {
  board_slug: string
  host: string
  port: number
  user: string
  encryption: 'tls' | 'ssl' | ''
  from_email: string
  from_name: string
  password_set: boolean
  uses_global_default: boolean
  verify_peer: boolean
}

/**
 * GET /admin/boards/{slug}/smtp — reads the board SMTP settings (no password).
 */
export async function getAdminBoardSmtp(boardSlug: string): Promise<BoardSmtpSettingsData> {
  return request<BoardSmtpSettingsData>('GET', accountPath(`/admin/boards/${boardSlug}/smtp`))
}

/**
 * PUT /admin/boards/{slug}/smtp — speichert Board-SMTP-Settings.
 */
export async function saveAdminBoardSmtp(
  boardSlug: string,
  data: Omit<BoardSmtpSettingsData, 'board_slug' | 'password_set' | 'uses_global_default'> & {
    password: string
  },
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('PUT', accountPath(`/admin/boards/${boardSlug}/smtp`), data)
}

// ── Admin: Members & invites ───────────────────────────────────────────────────

export type AccountRole = 'owner' | 'admin' | 'moderator' | 'member'
/** Roles MemberAction::changeRole() accepts as a target — never 'owner' (see its class doc). */
export type AssignableAccountRole = 'admin' | 'moderator' | 'member'

export interface MemberSummary {
  user_id: number
  /** Random opaque handle, safe to display — user_id above is internal-only. */
  public_id: string | null
  username: string | null
  role: AccountRole
  created_at: string
}

export interface PendingInvite {
  id: number
  user_id: number
  role: AccountRole
  expires_at: string
  created_at: string
}

export interface MembersData {
  members: MemberSummary[]
  invites: PendingInvite[]
  viewer_role: AccountRole | null
}

/**
 * GET /admin/members — list account members + pending invites.
 * AuthZ: accountAdmin (owner AND moderator may read) — throws ApiError(401)
 * for anon, ApiError(403) without account membership.
 * `viewer_role` tells the caller's own role so the SPA can gate owner-only UI
 * (invite form, remove, role change, revoke) without a second round trip.
 */
export async function listMembers(): Promise<MembersData> {
  return request<MembersData>('GET', accountPath('/admin/members'))
}

/**
 * POST /admin/invites — invite a new member by email with a given role
 * (server-enforced — owners are never created via invite, only
 * AssignableAccountRole values are accepted).
 * AuthZ: accountOwner — throws ApiError(403) for a moderator (invite is
 * owner-only), ApiError(422) with fields.email for invalid/already-a-member/
 * self-invite, or fields.role for an invalid role. Requires CSRF — call
 * bootstrap() first.
 */
export async function inviteMember(
  email: string,
  role: AssignableAccountRole = 'member',
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath('/admin/invites'), { email, role })
}

/**
 * POST /admin/invites/{id}/revoke — cancel a still-pending invite.
 * AuthZ: accountOwner. Throws ApiError(404) for an unknown/already-resolved/
 * foreign-account invite id.
 */
export async function revokeInvite(inviteId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/invites/${inviteId}/revoke`), {})
}

/**
 * POST /admin/members/{userId}/remove — remove a member from the account.
 * AuthZ: accountOwner. Throws ApiError(404) for unknown/foreign member,
 * ApiError(422, key='last_owner') when removing the account's last owner.
 */
export async function removeMember(userId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/members/${userId}/remove`), {})
}

/**
 * POST /admin/members/{userId}/role — change a member's role
 * (admin|moderator|member; never 'owner' — see AssignableAccountRole).
 * AuthZ: accountOwner.
 */
export async function changeMemberRole(
  userId: number,
  role: AssignableAccountRole,
): Promise<{ ok: boolean; role: AccountRole }> {
  return request<{ ok: boolean; role: AccountRole }>(
    'POST',
    accountPath(`/admin/members/${userId}/role`),
    { role },
  )
}

// ── Support requests & FAQ ──────────────────────────────────────────────────
//
// Shared category vocabulary between the dashboard contact form and the FAQ
// deflection list — see core/src/Support/SupportCategory.php and
// migrations/0023_add_support_and_faq.sql.

export type SupportCategory =
  | 'billing'
  | 'technical'
  | 'account'
  | 'feature_request'
  | 'privacy'
  | 'other'

export const SUPPORT_CATEGORIES: SupportCategory[] = [
  'billing',
  'technical',
  'account',
  'feature_request',
  'privacy',
  'other',
]

export type SupportRequestStatus = 'open' | 'answered' | 'closed'

export interface SupportRequestSummary {
  id: number
  account_id: number
  user_id: number
  category: SupportCategory
  subject: string
  status: SupportRequestStatus
  created_at: string
  updated_at: string
  /** Only present in the operator inbox listing (listOperatorSupportRequests()). */
  account_slug?: string
}

/** One message in a ticket's thread — see migrations/0026_add_support_messages.sql. */
export interface SupportMessage {
  id: number
  request_id: number
  author_type: 'customer' | 'operator'
  author_user_id: number
  body: string
  created_at: string
}

/** Minimal account identity for an operator following up on a ticket — no plaintext email is ever stored, only an HMAC (see the email-pseudonymization design). */
export interface SupportTicketAccountContext {
  id: number
  slug: string
  name: string
  plan: string
  created_at: string
}

export interface SupportTicketRequesterContext {
  id: number
  public_id: string | null
  username: string | null
  created_at: string
}

export interface SupportThreadResponse {
  request: SupportRequestSummary
  messages: SupportMessage[]
  /** Only present on the operator thread endpoint. */
  account?: SupportTicketAccountContext | null
  /** Only present on the operator thread endpoint. */
  requester?: SupportTicketRequesterContext | null
}

export type SupportRequestSort =
  | 'updated_at_desc'
  | 'updated_at_asc'
  | 'created_at_desc'
  | 'created_at_asc'

export interface ListOperatorSupportRequestsParams {
  status?: SupportRequestStatus
  category?: SupportCategory
  q?: string
  sort?: SupportRequestSort
}

export interface FaqEntry {
  id: number
  category: SupportCategory
  question_de: string
  question_en: string
  answer_de: string
  answer_en: string
  sort_order: number
  /** Only present in the operator listing (create/update/delete need it too). */
  is_published?: boolean
  created_at?: string
  updated_at?: string
}

export interface SubmitSupportRequestPayload {
  category: SupportCategory
  subject: string
  message: string
}

/**
 * POST /admin/support — submit a support request from the dashboard.
 * AuthZ: accountAdmin (any team member — owner or moderator). ApiError(422)
 * with a fields map for an invalid category/empty subject/too-short message.
 * Requires CSRF — call bootstrap() first. Entirely in-app — no contact email
 * is ever collected; the reply arrives via the notification inbox (see
 * listNotifications()).
 */
export async function submitSupportRequest(
  payload: SubmitSupportRequestPayload,
): Promise<{ ok: boolean; id: number }> {
  return request<{ ok: boolean; id: number }>('POST', accountPath('/admin/support'), payload)
}

/**
 * GET /admin/support — the caller's own account's tickets.
 * AuthZ: accountAdmin.
 */
export async function listMySupportRequests(): Promise<{ requests: SupportRequestSummary[] }> {
  return request<{ requests: SupportRequestSummary[] }>('GET', accountPath('/admin/support'))
}

/**
 * GET /admin/support/{id} — one of the caller's own account's tickets, with
 * its full message thread. AuthZ: accountAdmin, scoped to the caller's
 * account (ApiError(404) for another tenant's ticket).
 */
export async function getMySupportThread(id: number): Promise<SupportThreadResponse> {
  return request<SupportThreadResponse>('GET', accountPath(`/admin/support/${id}`))
}

/**
 * POST /admin/support/{id}/reply — post a follow-up message on one of the
 * caller's own tickets; reopens it (status → 'open'). AuthZ: accountAdmin.
 * ApiError(422) for an empty/too-short message, ApiError(404) for another
 * tenant's ticket. Requires CSRF — call bootstrap() first.
 */
export async function replyMySupportRequest(
  id: number,
  message: string,
): Promise<{ ok: boolean; status: SupportRequestStatus }> {
  return request<{ ok: boolean; status: SupportRequestStatus }>(
    'POST',
    accountPath(`/admin/support/${id}/reply`),
    { message },
  )
}

/**
 * GET /faq — published FAQ entries, both languages, platform-wide (no
 * account scoping). AuthZ: anon. Used both for a standalone FAQ view and to
 * deflect the contact form: filter this list client-side by the category
 * the customer picked before they submit a ticket.
 */
export async function listFaq(): Promise<{ entries: FaqEntry[] }> {
  return request<{ entries: FaqEntry[] }>('GET', '/faq')
}

export interface AcceptInviteResponse {
  ok: boolean
  account_id: number
  account_slug: string | null
  role: AccountRole
}

/**
 * GET /invite/accept?token=<plaintext> — accept a pending invite.
 * AuthZ: user (anon → 401 — the SPA then redirects to /login?r=… so the
 * invitee can log in with the invited address, landing back here afterwards).
 * Throws ApiError(400, key='invalid_token') for expired/used/unknown tokens,
 * ApiError(403, key='invite_mismatch') if the logged-in user isn't the
 * invited one.
 */
export async function acceptInvite(token: string): Promise<AcceptInviteResponse> {
  const params = new URLSearchParams({ token })
  return request<AcceptInviteResponse>('GET', `/invite/accept?${params.toString()}`)
}

/**
 * PUT /admin/boards/{slug}/smtp — resets the board to the global default.
 */
export async function resetAdminBoardSmtp(
  boardSlug: string,
): Promise<{ ok: boolean; reset: boolean }> {
  return request<{ ok: boolean; reset: boolean }>(
    'PUT',
    accountPath(`/admin/boards/${boardSlug}/smtp`),
    { reset_to_global: true },
  )
}

/**
 * POST /admin/boards/{slug}/smtp/test — sends a test email via the resolved board settings.
 */
export async function testAdminBoardSmtp(
  boardSlug: string,
  data: SmtpTestBody,
): Promise<{ ok: boolean; recipient: string }> {
  return request<{ ok: boolean; recipient: string }>(
    'POST',
    accountPath(`/admin/boards/${boardSlug}/smtp/test`),
    data,
  )
}

// ── Admin: API tokens (Agent API / Votepit MCP) ───────────────────────────────

export type ApiTokenScope = 'read' | 'write'

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

// ── Cloud signup / onboarding ──────────────────────────────────────────────────

export interface SignupStatus {
  /** true = this user already owns/belongs to an account (one account per signup, decision 17). */
  has_account: boolean
}

/**
 * GET /signup/account — tells the SPA whether this (already logged-in) user
 * already belongs to an account, so the picker form can be skipped.
 * AuthZ: user — throws ApiError(401) for anon.
 */
export async function getSignupStatus(): Promise<SignupStatus> {
  return request<SignupStatus>('GET', '/signup/account')
}

export interface CreateSignupAccountPayload {
  account_name: string
  account_slug: string
  board_name: string
  board_slug: string
}

export interface CreateSignupAccountResponse {
  ok: boolean
  account_slug: string
  board_slug: string
}

/**
 * POST /signup/account — creates the account (plan='free'), makes the caller
 * its owner, and creates the first board — all in one step.
 * AuthZ: user — throws ApiError(401) for anon, ApiError(409, key=
 * 'already_has_account') for a user who already belongs to an account
 * (one account per signup), ApiError(422) with a fields map for validation
 * failures (empty/too-long name, invalid/reserved/taken slug).
 */
export async function createSignupAccount(
  payload: CreateSignupAccountPayload,
): Promise<CreateSignupAccountResponse> {
  const result = await request<CreateSignupAccountResponse>('POST', '/signup/account', payload)
  // Activation goal (measurement plan: "signup_completed", site 10) — this
  // endpoint only exists in cloud mode, no edition check needed.
  trackEvent('Cloud', 'signup_completed')
  return result
}

export interface CaptureReferralResponse {
  ok: boolean
  recorded: boolean
}

/**
 * POST /referrals/capture — cloud-only (social-features ticket 01). Records
 * that the CALLER's own, just-created account was signed up via another
 * account's personal referral link (votepit.com/r/<account-slug>). The
 * referred account is always derived server-side from the session, never
 * sent by the client. `recorded: false` covers every non-error outcome
 * (self-referral, unknown/missing referrer slug, already referred) — none
 * of these need special handling by the caller.
 * AuthZ: user — throws ApiError(401) for anon.
 */
export async function captureReferral(referrerSlug: string): Promise<CaptureReferralResponse> {
  return request<CaptureReferralResponse>('POST', '/referrals/capture', {
    referrer_slug: referrerSlug,
  })
}

// ── Operator panel (platform super-admin) ──────────────────────────────────────
//
// AuthZ: every function below hits a route gated by AuthZMiddleware::operator()
// server-side (throws ApiError(401) for anon, ApiError(403) for anyone whose
// `is_operator` flag is false — including installation-wide admins and
// account owners of ANY account). Client-side gating mirrors
// BoardsAdminPage/MembersPage: OperatorPage reads `boot.user.is_operator`
// for UX only.

export interface OperatorAccountSummary {
  id: number
  slug: string
  name: string
  plan: string
  is_default: boolean
  confirmed_at: string | null
  locked_at: string | null
  created_at: string
}

export interface OperatorBoardSummary {
  id: number
  account_id: number
  account_slug: string
  slug: string
  name: string
  status: string
  visibility: BoardVisibility
  locked_at: string | null
  created_at: string
}

export interface OperatorUsageData {
  accounts_total: number
  accounts_by_plan: Record<string, number>
  boards_total: number
  ideas_total: number
  signups_last_7_days: number
  open_reports: number
  open_support_requests: number
}

export type AbuseReportStatus = 'open' | 'reviewed' | 'dismissed'

export interface AbuseReportSummary {
  id: number
  account_id: number | null
  board_id: number | null
  idea_id: number | null
  target_url: string
  reason: string
  reporter_email: string | null
  status: AbuseReportStatus
  reviewed_by: number | null
  reviewed_at: string | null
  created_at: string
}

/**
 * GET /operator/usage — platform-wide counts (accounts by plan, boards,
 * ideas, recent signups, open reports).
 */
export async function getOperatorUsage(): Promise<OperatorUsageData> {
  return request<OperatorUsageData>('GET', '/operator/usage')
}

/** GET /operator/accounts — every account platform-wide, regardless of owner. */
export async function listOperatorAccounts(): Promise<{ accounts: OperatorAccountSummary[] }> {
  return request<{ accounts: OperatorAccountSummary[] }>('GET', '/operator/accounts')
}

/** POST /operator/accounts/{id}/lock — reversible; hides the account's boards from public reads. */
export async function lockOperatorAccount(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/accounts/${id}/lock`, {})
}

/** POST /operator/accounts/{id}/unlock */
export async function unlockOperatorAccount(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/accounts/${id}/unlock`, {})
}

/** POST /operator/accounts/{id}/delete — hard delete, cascades to its boards/ideas. */
export async function deleteOperatorAccount(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/accounts/${id}/delete`, {})
}

/** GET /operator/boards — every board platform-wide, regardless of owning account. */
export async function listOperatorBoards(): Promise<{ boards: OperatorBoardSummary[] }> {
  return request<{ boards: OperatorBoardSummary[] }>('GET', '/operator/boards')
}

/** POST /operator/boards/{id}/lock — reversible; hides the board from public reads. */
export async function lockOperatorBoard(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/boards/${id}/lock`, {})
}

/** POST /operator/boards/{id}/unlock */
export async function unlockOperatorBoard(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/boards/${id}/unlock`, {})
}

/** POST /operator/boards/{id}/delete — hard delete, cascades to its ideas. */
export async function deleteOperatorBoard(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/operator/boards/${id}/delete`, {})
}

/** GET /operator/reports — the abuse-report inbox. */
export async function listOperatorReports(): Promise<{ reports: AbuseReportSummary[] }> {
  return request<{ reports: AbuseReportSummary[] }>('GET', '/operator/reports')
}

/** POST /operator/reports/{id}/review — mark a report reviewed or dismissed. */
export async function reviewOperatorReport(
  id: number,
  status: 'reviewed' | 'dismissed',
): Promise<{ ok: boolean; status: AbuseReportStatus }> {
  return request<{ ok: boolean; status: AbuseReportStatus }>(
    'POST',
    `/operator/reports/${id}/review`,
    { status },
  )
}

/**
 * GET /operator/support — every account's support tickets, filterable and
 * sortable for triage at volume (see SupportRequestAction class doc).
 */
export async function listOperatorSupportRequests(
  params: ListOperatorSupportRequestsParams = {},
): Promise<{ requests: SupportRequestSummary[] }> {
  const qs = new URLSearchParams()
  if (params.status !== undefined) qs.set('status', params.status)
  if (params.category !== undefined) qs.set('category', params.category)
  if (params.q !== undefined && params.q !== '') qs.set('q', params.q)
  if (params.sort !== undefined) qs.set('sort', params.sort)
  const suffix = qs.toString()
  return request<{ requests: SupportRequestSummary[] }>(
    'GET',
    `/operator/support${suffix ? `?${suffix}` : ''}`,
  )
}

/** GET /operator/support/{id} — one ticket's full thread, unscoped. */
export async function getOperatorSupportThread(id: number): Promise<SupportThreadResponse> {
  return request<SupportThreadResponse>('GET', `/operator/support/${id}`)
}

/** POST /operator/support/{id}/reply — answer a ticket (sets status 'answered', creates a notification for the customer). */
export async function replyOperatorSupportRequest(
  id: number,
  reply: string,
): Promise<{ ok: boolean; status: SupportRequestStatus }> {
  return request<{ ok: boolean; status: SupportRequestStatus }>(
    'POST',
    `/operator/support/${id}/reply`,
    { reply },
  )
}

/** POST /operator/support/{id}/status — set a ticket's status directly (e.g. re-open/close). */
export async function setOperatorSupportRequestStatus(
  id: number,
  status: SupportRequestStatus,
): Promise<{ ok: boolean; status: SupportRequestStatus }> {
  return request<{ ok: boolean; status: SupportRequestStatus }>(
    'POST',
    `/operator/support/${id}/status`,
    { status },
  )
}

/** GET /operator/faq — every FAQ entry, including unpublished drafts. */
export async function listOperatorFaq(): Promise<{ entries: FaqEntry[] }> {
  return request<{ entries: FaqEntry[] }>('GET', '/operator/faq')
}

export interface FaqEntryPayload {
  category: SupportCategory
  question_de: string
  question_en: string
  answer_de: string
  answer_en: string
  sort_order: number
  is_published: boolean
}

/** POST /operator/faq — create an entry. ApiError(422) with a fields map for validation errors. */
export async function createFaqEntry(
  payload: FaqEntryPayload,
): Promise<{ ok: boolean; id: number }> {
  return request<{ ok: boolean; id: number }>('POST', '/operator/faq', payload)
}

/** PUT /operator/faq/{id} — update an entry. ApiError(404) for an unknown id. */
export async function updateFaqEntry(
  id: number,
  payload: FaqEntryPayload,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('PUT', `/operator/faq/${id}`, payload)
}

/** DELETE /operator/faq/{id} */
export async function deleteFaqEntry(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', `/operator/faq/${id}`, {})
}

export interface SubmitReportPayload {
  url: string
  reason: string
  account_slug?: string
  board_slug?: string
  idea_id?: number
  reporter_email?: string
}

/**
 * POST /reports — public abuse-report intake (DSA Art. 16). AuthZ: anon —
 * no login required. ApiError(422) with a fields map for validation errors.
 */
export async function submitReport(
  payload: SubmitReportPayload,
): Promise<{ ok: boolean; id: number }> {
  return request<{ ok: boolean; id: number }>('POST', '/reports', payload)
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

// ── Account settings: summary + GDPR account deletion ─────────────────────────

export interface AccountSettingsData {
  account_id: number
  /** The "Delete account" typed-confirmation input compares against this client-side. */
  slug: string
  name: string
  /**
   * True for the self-host installation's single, undeletable account
   * (is_default=1). The SPA hides the "Delete account" danger zone
   * entirely when true — AccountDeleteAction rejects it server-side anyway.
   */
  is_default_account: boolean
  /** Pending deletion grace-period deadline, or null if none is scheduled. */
  deletion_scheduled_at: string | null
}

/** GET /admin/account — owner-only account summary for the account settings page. */
export async function getAccountSettings(): Promise<AccountSettingsData> {
  return request<AccountSettingsData>('GET', accountPath('/admin/account'))
}

/**
 * PUT /admin/account — renames the account's name and/or slug. Both fields
 * are independent and optional — pass only the one(s) being changed; an
 * omitted field keeps its current value. Unlike a board slug, the account
 * slug is unique platform-wide (not just within the account) — see
 * checkAccountSlugAvailable() for the live-typing check. ApiError(422) with
 * fields.{name,slug} on validation/collision. AuthZ: accountOwner + CSRF —
 * call bootstrap() first.
 */
export async function renameAccount(data: {
  name?: string
  slug?: string
}): Promise<{ ok: boolean; slug: string; name: string }> {
  return request<{ ok: boolean; slug: string; name: string }>(
    'PUT',
    accountPath('/admin/account'),
    data,
  )
}

/**
 * GET /admin/account/slug-available?slug=... — live global-uniqueness check
 * for the account-rename form (debounced while typing). Returns
 * `available: false` for both a taken slug and a format-invalid one — the
 * caller shows format errors from SlugValidator client-side separately, so
 * this endpoint only needs to answer "would saving this slug work right
 * now". AuthZ: accountOwner (same tier as the rest of account settings).
 */
export async function checkAccountSlugAvailable(slug: string): Promise<{ available: boolean }> {
  return request<{ available: boolean }>(
    'GET',
    accountPath(`/admin/account/slug-available?slug=${encodeURIComponent(slug)}`),
  )
}

/**
 * POST /admin/account/delete — schedules GDPR account deletion after a 48h
 * grace period. AuthZ: accountOwner. `confirmSlug` must match the account's
 * real slug exactly (re-validated server-side — see AccountDeleteAction
 * class doc); a mismatch returns ApiError(422, 'confirmation_mismatch').
 * ApiError(422, 'default_account_undeletable') on a self-host installation's
 * only account. An extension's deletion precondition may block with its own
 * status/key (e.g. an unsettled paid subscription).
 */
export async function requestAccountDeletion(
  confirmSlug: string,
): Promise<{ ok: boolean; deletion_scheduled_at: string }> {
  return request<{ ok: boolean; deletion_scheduled_at: string }>(
    'POST',
    accountPath('/admin/account/delete'),
    { confirm_slug: confirmSlug },
  )
}

/**
 * POST /admin/account/delete/cancel — undoes a pending deletion while the
 * grace period is still running. AuthZ: accountOwner.
 */
export async function cancelAccountDeletion(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath('/admin/account/delete/cancel'), {})
}

// ── Account data export (customer self-export, GDPR Art. 20) ─────────────────

export type ExportFormat = 'json' | 'csv'

/**
 * GET /admin/export?format=json|csv — downloads the account's full data
 * export and triggers a browser save (standard blob-download flow: fetch →
 * Blob → temporary object URL → invisible `<a download>` click → revoke).
 * AuthZ: accountOwner. No precedent elsewhere in this client for a
 * file-response endpoint, so this bypasses the JSON-only `request()` helper
 * entirely rather than force a non-JSON response through it.
 *
 * The server's `Content-Disposition` header names the file
 * (`votepit-export-<slug>-<timestamp>.json|zip`); it is used verbatim when
 * present, with a generic fallback name otherwise.
 */
export async function downloadAccountExport(format: ExportFormat = 'json'): Promise<void> {
  const resp = await fetch(accountPath(`/admin/export?format=${format}`), {
    method: 'GET',
    headers: { Accept: format === 'csv' ? 'application/zip' : 'application/json' },
    credentials: 'include',
  })

  if (!resp.ok) {
    let errPayload: ApiErrorPayload = {
      key: 'http_error',
      message: resp.statusText || `HTTP ${resp.status}`,
    }
    try {
      const json = (await resp.json()) as { error?: ApiErrorPayload }
      if (json.error && typeof json.error.key === 'string') {
        errPayload = json.error
      }
    } catch {
      // leave default payload
    }
    throw new ApiError(resp.status, errPayload)
  }

  const blob = await resp.blob()
  const disposition = resp.headers.get('Content-Disposition') ?? ''
  const match = /filename="([^"]+)"/.exec(disposition)
  const filename = match?.[1] ?? `votepit-export.${format === 'csv' ? 'zip' : 'json'}`

  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(objectUrl)
}

// ── Profile: avatar + social links (profile-avatar-social) ───────────────────
// User-scoped (NOT account-scoped): a profile is the same across every
// account the user belongs to — routes are never wrapped in accountPath().

/**
 * 4 fixed, named social identifiers (security redesign — replaces the
 * earlier free-form up-to-5-rows label+URL model). Each is a BARE platform
 * identifier, never a URL: a domain for `website_domain`, bare handles/
 * usernames (no leading "@") for the other three. The server builds the
 * actual https:// URL from these at render/use time — the client never
 * constructs or stores a URL for any of them.
 */
export interface SocialLinksData {
  website_domain: string | null
  x_handle: string | null
  youtube_handle: string | null
  github_username: string | null
}

export type ProfileData = SocialLinksData & {
  avatar_url: string | null
  profile_visible: boolean
  /** Optional public display name — null when unset (falls back to "Voter" wherever visible). */
  username: string | null
}

/** GET /account/profile — the caller's own avatar_url + the 4 social identifiers + privacy setting. */
export async function getAccountProfile(): Promise<ProfileData> {
  return request<ProfileData>('GET', '/account/profile')
}

/**
 * PUT /account/privacy — sets the caller's own profile-visibility toggle
 * (profile-visibility feature). Default is anonymous — every user starts
 * with `visible: false` until they opt in here. Requires CSRF — call
 * bootstrap() first.
 */
export async function savePrivacySettings(
  visible: boolean,
): Promise<{ ok: boolean; profile_visible: boolean }> {
  return request<{ ok: boolean; profile_visible: boolean }>('PUT', '/account/privacy', { visible })
}

/**
 * POST /admin/telemetry-opt-in — self-host product-improvement telemetry
 * opt-out toggle (see TelemetryOptInAction, AccountPage.tsx). Default is
 * opted in — every self-host install starts with anonymous, aggregate
 * telemetry ON until this is called with `opted_in: false`. Requires CSRF —
 * call bootstrap() first.
 */
export async function setTelemetryOptIn(
  optedIn: boolean,
): Promise<{ ok: boolean; opted_in: boolean }> {
  return request<{ ok: boolean; opted_in: boolean }>(
    'POST',
    accountPath('/admin/telemetry-opt-in'),
    {
      opted_in: optedIn,
    },
  )
}

/**
 * The caller's own notification preferences (notification-preferences
 * feature). `notification_email` is `null` until a submitted address has
 * been confirmed via the emailed link — a non-null value always means
 * "confirmed" (see core's UserRepository::findNotificationSettings()).
 */
export interface NotificationPreferencesData {
  notification_email: string | null
  idea_comment_inapp: boolean
  idea_comment_email: boolean
  thread_reply_inapp: boolean
  thread_reply_email: boolean
  /** Operator/support-only rows — meaningless (but harmless) for a regular user. */
  abuse_report_inapp: boolean
  abuse_report_email: boolean
  support_ticket_inapp: boolean
  support_ticket_email: boolean
}

/** GET /account/notification-preferences — the caller's own 4 flags + confirmed notification email. */
export async function getNotificationPreferences(): Promise<NotificationPreferencesData> {
  return request<NotificationPreferencesData>('GET', '/account/notification-preferences')
}

/**
 * PUT /account/notification-preferences — sets the 4 per-event-type flags.
 * An `*_email` flag has no effect server-side until `notification_email` is
 * confirmed (never trust the client) — the server still echoes back
 * whatever was sent so the UI can reflect it optimistically.
 */
export async function saveNotificationPreferences(
  prefs: Omit<NotificationPreferencesData, 'notification_email'>,
): Promise<{ ok: boolean } & Omit<NotificationPreferencesData, 'notification_email'>> {
  return request('PUT', '/account/notification-preferences', prefs)
}

/**
 * POST /account/notification-email — submits a candidate address and sends
 * a confirmation link. Does not change `notification_email` until the link
 * is clicked. ApiError(422, key: 'invalid_email') for a malformed address.
 */
export async function requestNotificationEmail(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/account/notification-email', { email })
}

/** DELETE /account/notification-email — removes the confirmed address and disables both email flags. */
export async function deleteNotificationEmail(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', '/account/notification-email', {})
}

/**
 * GET /account/notification-email/confirm?token=<plaintext> — confirms the
 * pending notification email from the emailed link. Requires the same user
 * to already be logged in (AuthZ: user) — ApiError(400, key: 'invalid_token')
 * for an unknown/expired/mismatched-user token.
 */
export async function confirmNotificationEmail(token: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'GET',
    `/account/notification-email/confirm?token=${encodeURIComponent(token)}`,
  )
}

/**
 * POST /account/avatar — uploads a new avatar (multipart/form-data, replaces
 * any existing one). Server re-encodes to a fixed-size JPEG and rejects
 * anything that isn't a decodable raster image (incl. SVG) with
 * ApiError(422, key: 'invalid_image' | 'file_too_large' | 'invalid_upload').
 * Uses raw fetch (not the JSON `request()` helper) — a File can't go through
 * JSON.stringify(). Requires CSRF — call bootstrap() first.
 */
export async function uploadAvatar(file: File): Promise<{ ok: boolean; avatar_url: string }> {
  const form = new FormData()
  form.append('avatar', file)

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (cachedCsrfToken !== null) {
    headers['X-CSRF-Token'] = cachedCsrfToken
  }

  const resp = await fetch('/account/avatar', {
    method: 'POST',
    headers,
    credentials: 'include',
    body: form,
  })

  if (!resp.ok) {
    let errPayload: ApiErrorPayload = {
      key: 'http_error',
      message: resp.statusText || `HTTP ${resp.status}`,
    }
    try {
      const json = (await resp.json()) as { error?: ApiErrorPayload }
      if (json.error && typeof json.error.key === 'string') {
        errPayload = json.error
      }
    } catch {
      // leave default payload
    }
    throw new ApiError(resp.status, errPayload)
  }

  return resp.json() as Promise<{ ok: boolean; avatar_url: string }>
}

/** DELETE /account/avatar — removes the caller's avatar (falls back to initials). */
export async function deleteAvatar(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', '/account/avatar', {})
}

/**
 * PUT /account/social-links — sets/clears the caller's 4 fixed social
 * identifiers (bare domain/handles/username, never a URL — see
 * SocialLinksData doc). An empty string for any field explicitly CLEARS it
 * (mirrors saveAdminBranding's "empty string resets" convention) — the
 * caller always sends its full current form state, so a field the user
 * cleared is submitted as `''`, not omitted.
 * ApiError(422) with key in {invalid_website_domain, invalid_x_handle,
 * invalid_youtube_handle, invalid_github_username}.
 */
export async function saveSocialLinks(data: SocialLinksData): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('PUT', '/account/social-links', {
    website_domain: data.website_domain ?? '',
    x_handle: data.x_handle ?? '',
    youtube_handle: data.youtube_handle ?? '',
    github_username: data.github_username ?? '',
  })
}

/**
 * PUT /account/username — sets or clears the caller's optional public
 * display name. An empty string clears it. Globally unique (case-
 * insensitive) — ApiError(409, key: 'username_taken') if already in use,
 * ApiError(422, key: 'invalid_username') if it fails the character/length
 * rules (UsernameValidator on the server is authoritative).
 */
export async function saveUsername(
  username: string,
): Promise<{ ok: boolean; username: string | null }> {
  return request<{ ok: boolean; username: string | null }>('PUT', '/account/username', { username })
}

// ── Public profile view (profile-visibility feature) ─────────────────────────

/**
 * Another user's public-safe profile (profile-visibility feature).
 * `visible: false` (the default — every user starts anonymous) means the
 * server has already withheld avatar_url/social links entirely — those
 * fields are simply absent, never present-but-empty, so there is no way for
 * client code to accidentally render anonymous-user data.
 */
export type PublicProfile =
  | {
      id: number
      visible: false
      is_admin: boolean
      is_operator: boolean
      role: AccountRole | null
    }
  | (SocialLinksData & {
      id: number
      visible: true
      is_admin: boolean
      is_operator: boolean
      role: AccountRole | null
      avatar_url: string | null
      username: string | null
      /**
       * Contribution stats (social-features issue 06) — pure live counts,
       * strictly scoped to the account this profile was fetched from (never
       * aggregated platform-wide). Always present when visible: true, 0 for
       * a user with no activity in this account.
       */
      ideas_submitted: number
      ideas_shipped: number
      votes_cast: number
    })

/**
 * GET {account}/members/{userId}/profile — public profile view. AuthZ: anon
 * (same trust level as the idea/comment it's attached to). `role` resolves
 * the target's membership in the CURRENT account (owner|moderator|null) for
 * the forum-style badge — shown regardless of the anonymity setting.
 * Throws ApiError(404) for an unknown user id.
 */
export async function getPublicProfile(userId: number): Promise<PublicProfile> {
  return request<PublicProfile>('GET', accountPath(`/members/${userId}/profile`))
}

// ── Account security: password + TOTP 2FA (ProfilePage) ─────────────────────

/**
 * POST /account/password — sets or changes the current user's password.
 * AuthZ: user (any logged-in user, no special role required). Omit
 * currentPassword on a first-time set (the active session already proves the
 * user clicked a magic link); required when a password is already set.
 * newPasswordConfirmation must match newPassword — server-side authoritative,
 * throws ApiError(400, key=password_mismatch) otherwise (also checked
 * client-side in SecuritySettings.tsx for immediate feedback).
 * Throws ApiError(400) with key=invalid_current_password, key=password_mismatch,
 * or key=weak_password. Requires CSRF — call bootstrap() first.
 */
export async function setPassword(
  newPassword: string,
  newPasswordConfirmation: string,
  currentPassword?: string,
): Promise<{ ok: boolean }> {
  const body: Record<string, string> = {
    new_password: newPassword,
    new_password_confirmation: newPasswordConfirmation,
  }
  if (currentPassword) body.current_password = currentPassword
  return request<{ ok: boolean }>('POST', '/account/password', body)
}

/**
 * POST /password/reset/request — "forgot password" step A (AuthZ: anon).
 * Always resolves to {ok: true} regardless of whether the address matches an
 * account (anti-enumeration — see PasswordResetRequestAction class doc).
 * Requires CSRF token — call bootstrap() before this.
 */
export async function requestPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/password/reset/request', { email })
}

/**
 * POST /password/reset/confirm — "forgot password" step B (AuthZ: anon — the
 * single-use reset token itself is the capability). On success, every other
 * active session for this user is invalidated (same mechanism as logout).
 * Throws ApiError(400) with key=invalid_token, key=password_mismatch, or
 * key=weak_password. CSRF-exempt is NOT the case here — this is a mutating
 * POST, so call bootstrap() first to seed the CSRF token.
 */
export async function confirmPasswordReset(
  token: string,
  newPassword: string,
  newPasswordConfirmation: string,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/password/reset/confirm', {
    token,
    new_password: newPassword,
    new_password_confirmation: newPasswordConfirmation,
  })
}

/**
 * POST /account/password-reset — logged-in self-service "send me a reset
 * link" (AuthZ: user). The caller re-types their own email as a
 * confirmation step (ADR 0002: no plaintext email is ever stored, not even
 * for the caller's own account, so it can't be pre-filled). Throws
 * ApiError(422, key=email_mismatch) if it doesn't match the account.
 */
export async function requestOwnPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/account/password-reset', { email })
}

/**
 * POST /admin/members/password-reset — Owner/Admin triggers a mail-based
 * reset link for one of the account's members, identified by re-typed
 * email (AuthZ: accountAdmin). Throws ApiError(404, key=not_found) if no
 * member of this account matches that email.
 */
export async function requestMemberPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/admin/members/password-reset', { email })
}

/**
 * POST /operator/users/password-reset — Operator/Support triggers a
 * mail-based reset link for any platform user, identified by re-typed
 * email (AuthZ: support — is_support or is_operator). Throws
 * ApiError(404, key=not_found) if no user matches that email.
 */
export async function requestOperatorPasswordReset(email: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/operator/users/password-reset', { email })
}

export interface TotpSetupData {
  secret: string
  provisioning_uri: string
  /** Signed blob to echo back unchanged in confirmTotpSetup — the secret is never persisted before confirmation. */
  setup_token: string
}

/**
 * POST /account/totp/setup — begins TOTP enrollment: generates a fresh
 * secret (NOT persisted yet) and a QR provisioning URI to render
 * client-side. AuthZ: user. Requires CSRF — call bootstrap() first.
 */
export async function beginTotpSetup(): Promise<TotpSetupData> {
  return request<TotpSetupData>('POST', '/account/totp/setup', {})
}

/**
 * POST /account/totp/confirm — verifies the 6-digit code against the
 * setup-token's secret; on success activates 2FA and returns 10 backup
 * codes in plaintext — shown to the user exactly once, never retrievable
 * again. AuthZ: user. Throws ApiError(400, key=invalid_code) on a wrong or
 * expired code. Requires CSRF — call bootstrap() first.
 */
export async function confirmTotpSetup(
  setupToken: string,
  code: string,
): Promise<{ ok: boolean; backup_codes: string[] }> {
  return request<{ ok: boolean; backup_codes: string[] }>('POST', '/account/totp/confirm', {
    setup_token: setupToken,
    code,
  })
}

/** Either the current password or a valid TOTP code — required to disable 2FA or regenerate backup codes. */
export type TotpConfirmation = { currentPassword: string } | { code: string }

function totpConfirmationBody(confirmation: TotpConfirmation): Record<string, string> {
  return 'currentPassword' in confirmation
    ? { current_password: confirmation.currentPassword }
    : { code: confirmation.code }
}

/**
 * POST /account/totp/disable — turns 2FA off and deletes the secret + all
 * backup codes. AuthZ: user. Requires the current password OR a valid TOTP
 * code as confirmation. Throws ApiError(400, key=confirmation_failed) on a
 * wrong confirmation. Requires CSRF — call bootstrap() first.
 */
export async function disableTotp(confirmation: TotpConfirmation): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    '/account/totp/disable',
    totpConfirmationBody(confirmation),
  )
}

/**
 * POST /account/totp/backup-codes/regenerate — invalidates all existing
 * backup codes and issues 10 fresh ones (plaintext, shown once). AuthZ:
 * user. Same confirmation requirement as disableTotp. Requires CSRF — call
 * bootstrap() first.
 */
export async function regenerateBackupCodes(
  confirmation: TotpConfirmation,
): Promise<{ ok: boolean; backup_codes: string[] }> {
  return request<{ ok: boolean; backup_codes: string[] }>(
    'POST',
    '/account/totp/backup-codes/regenerate',
    totpConfirmationBody(confirmation),
  )
}

// ── Notifications (in-app inbox) ──────────────────────────────────────────────
//
// Entirely in-app replacement for the old support-request email channel — see
// migrations/0024_add_notifications_remove_support_email.sql. Four kinds
// share one shape: a 'support_reply' notification (account-scoped, system-
// generated), an 'announcement' notification (broadcast, operator-authored —
// see the Operator panel section below), and 'idea_comment'/'thread_reply'
// (user-scoped, notification-preferences feature — see
// NotificationPreferencesSection in ProfilePage.tsx for the opt-in flags).

export type NotificationType = 'support_reply' | 'announcement' | 'idea_comment' | 'thread_reply'
export type NotificationScope = 'account' | 'broadcast' | 'user'

export interface NotificationSummary {
  id: number
  scope: NotificationScope
  type: NotificationType
  title: string
  body: string
  /** In-app relative path the SPA navigates to when clicked, or null. */
  link_path: string | null
  created_at: string
  is_read: boolean
}

/**
 * GET /notifications — the caller's inbox: broadcasts + every account-scoped
 * notification for the accounts they belong to. AuthZ: user (not
 * account-scoped — a user's memberships already determine visibility).
 */
export async function listNotifications(): Promise<{ notifications: NotificationSummary[] }> {
  return request<{ notifications: NotificationSummary[] }>('GET', '/notifications')
}

/** POST /notifications/{id}/read — marks one notification read. Idempotent. */
export async function markNotificationRead(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/notifications/${id}/read`, {})
}

/**
 * DELETE /notifications/{id} — removes one notification from the caller's
 * own inbox, permanently. Idempotent. Only hides it for the caller — a
 * dismissed broadcast stays visible in every other user's inbox.
 */
export async function dismissNotification(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', `/notifications/${id}`, {})
}

// ── Operator panel: announcements (broadcast to every customer's inbox) ──────

export interface AnnouncementSummary {
  id: number
  title: string
  body: string
  link_path: string | null
  created_by: number | null
  created_at: string
}

/** GET /operator/announcements — every broadcast announcement, newest first. */
export async function listOperatorAnnouncements(): Promise<{
  announcements: AnnouncementSummary[]
}> {
  return request<{ announcements: AnnouncementSummary[] }>('GET', '/operator/announcements')
}

export interface CreateAnnouncementPayload {
  title: string
  body: string
  /** Must be an in-app relative path starting with "/", or omitted. */
  link_path?: string
}

/**
 * POST /operator/announcements — posts a new announcement, immediately
 * visible in every customer's inbox. ApiError(422) with a fields map for an
 * empty title/body or a non-internal link_path. AuthZ: operator.
 */
export async function createOperatorAnnouncement(
  payload: CreateAnnouncementPayload,
): Promise<{ ok: boolean; id: number }> {
  return request<{ ok: boolean; id: number }>('POST', '/operator/announcements', payload)
}

/** DELETE /operator/announcements/{id} */
export async function deleteOperatorAnnouncement(id: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', `/operator/announcements/${id}`, {})
}
