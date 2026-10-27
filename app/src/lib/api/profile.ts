/**
 * User profile: avatar, social links, privacy, username, notification
 * preferences, linked OAuth providers and public profiles.
 */

import { accountPath } from '../accountContext'
import { ApiError, type ApiErrorPayload, downloadFile, getCsrfToken, request } from './client'
import type { AccountRole } from './members'
import type { BoardVisual } from './model'

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
  /** Pending self-deletion deadline (ISO 8601), or null — see requestUserDeletion(). */
  deletion_scheduled_at: string | null
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
  /**
   * Weekly owner digest e-mail — the effective value (explicit choice, else
   * on for account owners). Optional: older servers don't send it.
   */
  weekly_digest_email?: boolean
}

/** Languages the server renders notification and digest mail in (MailLanguage::SUPPORTED). */
export type DigestLanguage = 'de' | 'en' | 'es' | 'fr' | 'it' | 'nl'

/** Extra, optional PUT fields beyond the per-event-type flags. */
export interface NotificationPreferencesExtras {
  /** Only sent when the digest switch itself is toggled — omitted keeps the server-side default. */
  weekly_digest_email?: boolean
  /** UI language mail is rendered in; the server ignores values outside DigestLanguage. */
  digest_language?: DigestLanguage
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
  prefs: Omit<NotificationPreferencesData, 'notification_email'> & NotificationPreferencesExtras,
): Promise<{ ok: boolean } & Omit<NotificationPreferencesData, 'notification_email'>> {
  return request('PUT', '/account/notification-preferences', prefs)
}

/**
 * POST /digest/unsubscribe — the weekly digest's one-click unsubscribe. No
 * login needed: the signed token from the mail link is the capability.
 * ApiError(400, key: 'invalid_token') for a forged/garbled token.
 */
export async function unsubscribeWeeklyDigest(token: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/digest/unsubscribe', { token })
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
  const csrfToken = getCsrfToken()
  if (csrfToken !== null) {
    headers['X-CSRF-Token'] = csrfToken
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

// ── Linked OAuth providers (account settings) ─────────────────────────────────

export interface OAuthIdentity {
  provider: 'google' | 'github'
  created_at: string
}

/** GET /account/oauth-identities — the caller's own linked OAuth providers. */
export async function getOAuthIdentities(): Promise<{ identities: OAuthIdentity[] }> {
  return request<{ identities: OAuthIdentity[] }>('GET', '/account/oauth-identities')
}

/**
 * DELETE /account/oauth-identities/{provider} — unlinks one provider.
 * Idempotent: unlinking a provider that isn't currently linked still
 * succeeds. Never risks a lockout — magic-link login always works.
 */
export async function deleteOAuthIdentity(provider: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', `/account/oauth-identities/${provider}`)
}

// ── Public profile view (profile-visibility feature) ─────────────────────────

/**
 * Another user's public-safe profile (profile-visibility feature).
 * `visible: false` (the default — every user starts anonymous) means the
 * server has already withheld avatar_url/social links entirely — those
 * fields are simply absent, never present-but-empty, so there is no way for
 * client code to accidentally render anonymous-user data.
 */
export type PublicProfile = PublicProfileView & {
  /** Board context (`?board=`): the board's look, when the profile was reached from a board. */
  board?: ProfileBoardContext
}

/** The board a profile was reached from, with the look its pages apply. */
export interface ProfileBoardContext extends BoardVisual {
  slug: string
  name: string
}

type PublicProfileView =
  | {
      id: number
      visible: false
      /**
       * True for the shared "deleted user" placeholder that ideas/comments
       * of self-deleted users are attributed to (UserErasureRepository).
       */
      deleted?: boolean
      /**
       * True for the shared guest author of ideas submitted without an
       * account (ADR 0006) — rendered as "Guest", never a profile.
       */
      guest?: boolean
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
export async function getPublicProfile(userId: number, boardSlug?: string): Promise<PublicProfile> {
  const query = boardSlug ? `?board=${encodeURIComponent(boardSlug)}` : ''
  return request<PublicProfile>('GET', accountPath(`/members/${userId}/profile${query}`))
}

// ── GDPR self-service for every user (own data export + self-deletion) ────────
// User-scoped like the rest of /account/* — never wrapped in accountPath().

/**
 * GET /account/export — downloads a JSON file with the caller's own data
 * across every account (profile, memberships, linked sign-in providers,
 * ideas, comments, votes, reactions, notification preferences).
 */
export async function downloadMyData(): Promise<void> {
  await downloadFile('/account/export', 'application/json', 'votepit-my-data.json')
}

/**
 * POST /account/delete — schedules deletion of the caller's own user account
 * after a 48h grace period. `confirmEmail` must be the email address the
 * user signs in with (checked server-side against the stored HMAC):
 * ApiError(422, 'confirmation_mismatch') otherwise. ApiError(409,
 * 'owns_accounts' | 'operator_undeletable') while the user still owns an
 * account or is a platform operator. Requires CSRF — call bootstrap() first.
 */
export async function requestUserDeletion(
  confirmEmail: string,
): Promise<{ ok: boolean; deletion_scheduled_at: string }> {
  return request<{ ok: boolean; deletion_scheduled_at: string }>('POST', '/account/delete', {
    confirm_email: confirmEmail,
  })
}

/** POST /account/delete/cancel — undoes a pending self-deletion during the grace period. */
export async function cancelUserDeletion(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', '/account/delete/cancel', {})
}
