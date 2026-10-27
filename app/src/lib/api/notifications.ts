/**
 * In-app notification inbox.
 */

import { request } from './client'

// ── Notifications (in-app inbox) ──────────────────────────────────────────────
//
// Entirely in-app replacement for the old support-request email channel — see
// migrations/0024_add_notifications_remove_support_email.sql. Four kinds
// share one shape: a 'support_reply' notification (account-scoped, system-
// generated), an 'announcement' notification (broadcast, operator-authored —
// see the Operator panel section below), and 'idea_comment'/'thread_reply'
// (user-scoped, notification-preferences feature — see
// NotificationPreferencesSection in ProfilePage.tsx for the opt-in flags).

export type NotificationType =
  | 'support_reply'
  | 'announcement'
  | 'idea_comment'
  | 'thread_reply'
  // Pre-moderation (issue 05): moderator queue notice + author approval notice.
  | 'ideas_pending_approval'
  | 'idea_approved'
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

/** One notification's translation outcome — `ok: false` carries no translated fields. */
export type TranslatedNotification =
  | {
      id: number
      ok: true
      translated_title: string | null
      translated_body: string
      detected_source_locale: string
      cached: boolean
    }
  | { id: number; ok: false }

export interface TranslateNotificationsResponse {
  notifications: TranslatedNotification[]
}

/**
 * POST /notifications/translate — bulk-translates title+body of every
 * listed notification into `targetLocale` in one request (same whole-batch-
 * in-one-call shape as translateComments()); also covers operator broadcast
 * announcements, since those are physically notifications rows. An id the
 * caller cannot see comes back as `{id, ok: false}` rather than failing the
 * whole request. AuthZ: user. Not board-bound — no per-board opt-in check,
 * only Config::translationConfigured() gates it (ApiError(503,
 * key=translation_unavailable) when unconfigured).
 */
export async function translateNotifications(
  notificationIds: number[],
  targetLocale: string,
): Promise<TranslateNotificationsResponse> {
  return request<TranslateNotificationsResponse>('POST', '/notifications/translate', {
    notification_ids: notificationIds,
    target_locale: targetLocale,
  })
}
