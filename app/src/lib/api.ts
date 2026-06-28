/**
 * Votepit API client
 *
 * All requests use `credentials: 'include'` (session cookie auth).
 * Mutating requests send the cached CSRF token as `X-CSRF-Token`.
 * Call `bootstrap()` on app mount to seed the CSRF token and user state.
 */

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
  score_cache: number
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
}

export interface PaginationMeta {
  page: number
  total_pages: number
}

export interface BoardResponse {
  board: BoardData
  ideas: Idea[]
  active_status: string | null
  active_sort: string
  page: number
  total_pages: number
  is_authenticated: boolean
}

export interface User {
  id: number
  is_admin: boolean
}

export interface BootstrapData {
  csrf_token: string
  user: User | null
}

export interface IdeaDetailResponse {
  board: {
    id: number
    slug: string
    name: string
  }
  idea: Idea
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

// ── Internal request helper ───────────────────────────────────────────────────

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
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
  return request<BoardResponse>('GET', `/${boardSlug}${qs ? `?${qs}` : ''}`)
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
  return request<IdeaDetailResponse>('GET', `/${boardSlug}/ideas/${ideaId}`)
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

/**
 * GET /login/verify?token=<plaintext>[&r=<returnTo>] — verifies a magic-link token.
 * On success: 200 {ok: true, redirect: string} (session cookie set by server).
 * On failure: throws ApiError(400) with key=invalid_token.
 * GET is CSRF-exempt (single-use capability token is its own proof).
 */
export async function verifyToken(
  token: string,
  returnTo?: string,
): Promise<{ ok: boolean; redirect: string }> {
  const params = new URLSearchParams({ token })
  if (returnTo) params.set('r', returnTo)
  return request<{ ok: boolean; redirect: string }>('GET', `/login/verify?${params.toString()}`)
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
  return request<SubmitFormData>('GET', `/${boardSlug}/ideas/new`)
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
  return request<CreateIdeaResponse>('POST', `/${boardSlug}/ideas`, payload)
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
  return request<GetIdeaForEditResponse>('GET', `/${boardSlug}/ideas/${ideaId}/edit`)
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
  return request<{ ok: boolean }>('POST', `/${boardSlug}/ideas/${ideaId}`, payload)
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
  return request<{ ok: boolean }>('POST', `/${boardSlug}/ideas/${ideaId}/withdraw`, {})
}

// ── Admin: Branding ───────────────────────────────────────────────────────────

export interface BrandingData {
  board_slug: string
  board_name: string
  primary_color: string | null
  secondary_color: string | null
  logo_url: string | null
}

/**
 * GET /admin/boards/{slug}/branding — fetch current branding settings.
 * AuthZ: admin — throws ApiError(401) for anon, ApiError(403) for non-admin.
 */
export async function getAdminBranding(slug: string): Promise<BrandingData> {
  return request<BrandingData>('GET', `/admin/boards/${slug}/branding`)
}

/**
 * POST /admin/boards/{slug}/branding — persist branding.
 * Pass empty string to clear a field (server treats '' as "reset to default").
 * Requires admin AuthZ + CSRF — call bootstrap() first.
 */
export async function saveAdminBranding(
  slug: string,
  data: { primary_color: string; secondary_color: string; logo_url: string },
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', `/admin/boards/${slug}/branding`, data)
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
  return request<ModerationData>('GET', `/admin/boards/${slug}/moderation`)
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
  return request<{ ok: boolean }>('POST', `/admin/boards/${slug}/moderation`, data)
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
  return request<VoteResponse>('POST', `/${boardSlug}/ideas/${ideaId}/vote`, { value: direction })
}
