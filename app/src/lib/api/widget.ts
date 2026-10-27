/**
 * Owner settings of the embeddable widget (ADR 0005) — account-admin only.
 * The widget iframe itself talks to the server through its own cookie-less
 * client (src/widget/api.ts), never through this module.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

export interface BoardWidgetSettings {
  /** False when the account's plan does not include the widget (cloud) — settings stay readable. */
  plan_allowed: boolean
  /** False for a private board: the widget page only ever serves public/unlisted boards. */
  embeddable: boolean
  allowed_origins: string[]
  max_origins: number
  secret_configured: boolean
  secret_rotated_at: string | null
  /** Required JWT `aud` claim for this board. */
  audience: string
  /** Maximum `exp - iat` of an identify JWT, in seconds. */
  max_jwt_lifetime: number
  /** Install detection: the last allowed origin that loaded the widget, and when. */
  last_seen_origin: string | null
  last_seen_at: string | null
}

/** GET /admin/boards/{slug}/widget — current settings (never the secret). */
export async function getAdminBoardWidget(boardSlug: string): Promise<BoardWidgetSettings> {
  return request<BoardWidgetSettings>('GET', accountPath(`/admin/boards/${boardSlug}/widget`))
}

/**
 * PUT /admin/boards/{slug}/widget — replaces the allowed-origin list (an
 * empty list switches the widget off). Throws ApiError(422) with
 * `fields.allowed_origins` on an invalid entry, or key `plan_limit_widget`.
 */
export async function saveAdminBoardWidgetOrigins(
  boardSlug: string,
  allowedOrigins: string[],
): Promise<{ ok: boolean; allowed_origins: string[] }> {
  return request('PUT', accountPath(`/admin/boards/${boardSlug}/widget`), {
    allowed_origins: allowedOrigins,
  })
}

/**
 * POST /admin/boards/{slug}/widget/secret — generates/rotates the identify
 * secret. Returned exactly once in this response; rotation immediately
 * invalidates every outstanding widget session of this board.
 */
export async function rotateAdminBoardWidgetSecret(
  boardSlug: string,
): Promise<{ ok: boolean; secret: string }> {
  return request('POST', accountPath(`/admin/boards/${boardSlug}/widget/secret`), {})
}
