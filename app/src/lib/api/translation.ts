/**
 * On-demand translation of ideas, comment threads and board intros.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

// ── Translation (idea-comment-translation) ─────────────────────────────────────

export interface TranslateIdeaResponse {
  translated_title: string | null
  translated_body: string
  detected_source_locale: string
  cached: boolean
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/translate — machine-translates an idea's
 * title+body into `targetLocale`. Caller must check bootstrap()'s
 * `translation_available` flag first — this is fail-secure server-side
 * (503 `translation_unavailable`) when no provider is configured, but the
 * SPA should never fire the request at all in that case.
 * Requires auth (AuthZ: user) — anonymous voters never see translated
 * content. Throws ApiError(404) for unknown board/idea, ApiError(422) for
 * an invalid target_locale, ApiError(429) once the `translation:idea` rate
 * limit is hit, ApiError(503, key=translation_unavailable) if the
 * provider isn't configured or the call itself fails — the original idea
 * content stays fully usable via getIdea() regardless.
 */
export async function translateIdea(
  boardSlug: string,
  ideaId: string | number,
  targetLocale: string,
): Promise<TranslateIdeaResponse> {
  return request<TranslateIdeaResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/translate`),
    { target_locale: targetLocale },
  )
}

/** One comment's translation outcome — `ok: false` carries no translated fields (see CommentTranslateAction). */
export type TranslatedComment =
  | {
      id: number
      ok: true
      translated_body: string
      detected_source_locale: string
      cached: boolean
    }
  | { id: number; ok: false }

export interface TranslateCommentsResponse {
  comments: TranslatedComment[]
}

/**
 * POST /{boardSlug}/ideas/{ideaId}/comments/translate — bulk-translates
 * EVERY comment currently on the idea into `targetLocale` in one request
 * (never one call per comment — see CommentTranslateAction's doc comment).
 * A single comment's provider failure doesn't fail the whole batch (per-
 * comment `ok` flag); the whole request 503s only when translation is
 * entirely unconfigured. Same AuthZ/rate-limit shape as translateIdea(),
 * bucket `translation:comment` (counts one hit per request regardless of
 * comment count).
 */
export async function translateComments(
  boardSlug: string,
  ideaId: string | number,
  targetLocale: string,
): Promise<TranslateCommentsResponse> {
  return request<TranslateCommentsResponse>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/comments/translate`),
    { target_locale: targetLocale },
  )
}

export interface TranslateBoardIntroResponse {
  translated_body: string
  detected_source_locale: string
  cached: boolean
}

/**
 * POST /{boardSlug}/translate-intro — machine-translates a board's intro
 * text into `targetLocale`. Same shape as translateIdea() (single item,
 * cached, per-board opt-in via `boards.translation_enabled`).
 * ApiError(404) when the board has no intro to translate, ApiError(503,
 * key=translation_unavailable) when the board hasn't opted in or no
 * provider is configured.
 */
export async function translateBoardIntro(
  boardSlug: string,
  targetLocale: string,
): Promise<TranslateBoardIntroResponse> {
  return request<TranslateBoardIntroResponse>(
    'POST',
    accountPath(`/${boardSlug}/translate-intro`),
    { target_locale: targetLocale },
  )
}
