import { accountPath } from '../accountContext'
import { request } from './client'

/**
 * Participant mails (close-the-loop re-engagement): the per-category cadence
 * switches of the signed-in user, the one-click unsubscribe of a signed mail
 * link, and the per-idea follow flag. Keys match ParticipantMailCategory on
 * the server.
 */

/** my_ideas: ideas the user wrote · participated: ideas voted on, commented or followed · new_ideas: opt-in digest. */
export type ParticipantMailCategoryKey = 'my_ideas' | 'participated' | 'new_ideas'

export type ParticipantMailCadence = 'instant' | 'daily' | 'weekly' | 'off'

interface ParticipantMailCategoryPref {
  cadence: ParticipantMailCadence
  /** Cadences the server accepts for this category (new_ideas: weekly | off only). */
  allowed: ParticipantMailCadence[]
}

export interface ParticipantMailPreferences {
  categories: Record<ParticipantMailCategoryKey, ParticipantMailCategoryPref>
}

/** GET /account/participant-mail-preferences — the caller's own cadence per category. */
export async function getParticipantMailPreferences(): Promise<ParticipantMailPreferences> {
  return request<ParticipantMailPreferences>('GET', '/account/participant-mail-preferences')
}

/**
 * PUT /account/participant-mail-preferences — partial: only the given categories
 * change. The whole request is rejected if any cadence is not allowed for its category.
 */
export async function saveParticipantMailPreferences(
  updates: Partial<Record<ParticipantMailCategoryKey, ParticipantMailCadence>>,
): Promise<ParticipantMailPreferences> {
  return request<ParticipantMailPreferences>(
    'PUT',
    '/account/participant-mail-preferences',
    updates,
  )
}

/**
 * POST /participant-mail/unsubscribe — one-click unsubscribe from a signed mail
 * link. No login; the token can only switch mails off, never on.
 */
export async function unsubscribeParticipantMail(token: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/participant-mail/unsubscribe', { token })
}

/** GET {board}/ideas/{id}/follow — whether the signed-in user follows the idea (401 for guests). */
export async function getIdeaFollow(
  boardSlug: string,
  ideaId: string | number,
): Promise<{ following: boolean }> {
  return request<{ following: boolean }>('GET', accountPath(`/${boardSlug}/ideas/${ideaId}/follow`))
}

/** POST {board}/ideas/{id}/follow — follow the idea. Body `{}` keeps the CSRF header on the request. */
export async function followIdea(
  boardSlug: string,
  ideaId: string | number,
): Promise<{ following: boolean }> {
  return request<{ following: boolean }>(
    'POST',
    accountPath(`/${boardSlug}/ideas/${ideaId}/follow`),
    {},
  )
}

/** DELETE {board}/ideas/{id}/follow — stop following the idea. */
export async function unfollowIdea(
  boardSlug: string,
  ideaId: string | number,
): Promise<{ following: boolean }> {
  return request<{ following: boolean }>(
    'DELETE',
    accountPath(`/${boardSlug}/ideas/${ideaId}/follow`),
    {},
  )
}
