import { accountPath } from '../accountContext'
import { ApiError, type ApiErrorPayload, getCsrfToken, request } from './client'

/** Image slots a board can carry (P-28). `cover` yields two renditions; `logo` is the app symbol, `wordmark` the topbar logo. */
export type BoardAssetKind = 'cover' | 'favicon' | 'og' | 'logo' | 'wordmark' | 'wordmark_light'

/** Rendition keys as the server names them (BoardAssetProcessor::VARIANT_*). */
type BoardAssetVariant =
  | 'cover_wide'
  | 'cover_phone'
  | 'favicon'
  | 'og'
  | 'logo'
  | 'wordmark'
  | 'wordmark_light'

interface BoardAssetRendition {
  id: string
  width: number
  height: number
  /** Mean relative luminance of the visible pixels (0..1); null when unknown (older uploads). */
  ink: number | null
}

/** Upload clause (AGB 19) state of the account: the version and whether it was accepted. */
interface BoardUploadTerms {
  version: string
  accepted: boolean
}

export interface BoardAssetsResponse {
  variants: Partial<Record<BoardAssetVariant, BoardAssetRendition>>
  /** Present only when a terms version is configured (Votepit Cloud); absent in Community. */
  upload_terms?: BoardUploadTerms
  allowed: {
    cover_upload: boolean
    favicon_og: boolean
    back_link_footer: boolean
    logo_url: boolean
  }
  max_upload_bytes: number
  /** Upload limit per kind (bytes); the logo has its own, smaller-by-design limit. */
  max_upload_bytes_by_kind?: Partial<Record<BoardAssetKind, number>>
  /** Stored link values; shown even when the plan locks them (Q19). */
  link: BoardLinkFooterInput
}

export interface BoardLinkFooterInput {
  back_link_url: string
  back_link_label: string
  footer_note: string
}

/** GET /admin/boards/{slug}/assets — current imagery, plan flags and the upload limit. */
export async function getBoardAssets(slug: string): Promise<BoardAssetsResponse> {
  return request<BoardAssetsResponse>('GET', accountPath(`/admin/boards/${slug}/assets`))
}

/**
 * POST /admin/boards/{slug}/assets/terms/accept — the owner/admin accepts the upload clause for the
 * account (once per version; idempotent). ApiError(403 board_frozen / 404) on refusal.
 */
/**
 * `lang` is the UI language the clause was shown in; the server stores it as
 * proof (migration 0101). Unsupported values are stored as NULL.
 */
export async function acceptUploadTerms(
  slug: string,
  lang?: string,
): Promise<{ ok: boolean; terms_version: string; accepted_at: string }> {
  return request<{ ok: boolean; terms_version: string; accepted_at: string }>(
    'POST',
    accountPath(`/admin/boards/${slug}/assets/terms/accept`),
    lang === undefined ? {} : { lang },
  )
}

/**
 * POST /admin/boards/{slug}/assets/{kind} — multipart upload, field `image`.
 * The server re-encodes the file; the SPA never trusts the client file type.
 * Throws ApiError with the server key (e.g. invalid_upload, plan_feature_locked).
 */
export async function uploadBoardAsset(
  slug: string,
  kind: BoardAssetKind,
  file: File,
): Promise<{ ok: boolean; variants: Record<string, string> }> {
  const form = new FormData()
  form.append('image', file)

  const headers: Record<string, string> = { Accept: 'application/json' }
  const csrfToken = getCsrfToken()
  if (csrfToken !== null) {
    headers['X-CSRF-Token'] = csrfToken
  }

  const resp = await fetch(accountPath(`/admin/boards/${slug}/assets/${kind}`), {
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
      // keep the default payload
    }
    throw new ApiError(resp.status, errPayload)
  }

  return resp.json() as Promise<{ ok: boolean; variants: Record<string, string> }>
}

/** POST /admin/boards/{slug}/assets/{kind}/delete — removes the slot (always allowed). */
export async function deleteBoardAsset(
  slug: string,
  kind: BoardAssetKind,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    accountPath(`/admin/boards/${slug}/assets/${kind}/delete`),
    {},
  )
}

/**
 * PUT /admin/boards/{slug}/link-footer — back link and footer note. Empty
 * strings clear a value. ApiError(422) with fields.{back_link_url,
 * back_link_label,footer_note} for invalid input or a locked plan.
 */
export async function saveBoardLinkFooter(
  slug: string,
  data: BoardLinkFooterInput,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('PUT', accountPath(`/admin/boards/${slug}/link-footer`), data)
}

/** Public URL of one stored rendition (served by the gated public endpoint). */
export function boardAssetUrl(boardSlug: string, publicId: string): string {
  return accountPath(`/${boardSlug}/assets/${publicId}`)
}
