/**
 * Per-board outbound integrations: board SMTP (self-host), webhooks and
 * Slack/Discord chat notifications.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

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

// ── Admin: Board webhooks ───────────────────────────────────────────────────────

export interface BoardWebhookDelivery {
  event: string
  success: boolean
  status_code: number | null
  error: string | null
  attempted_at: string
}

export interface BoardWebhookSettingsData {
  configured: boolean
  url: string | null
  active: boolean
  created_at: string | null
  updated_at: string | null
  deliveries: BoardWebhookDelivery[]
}

/** GET /admin/boards/{slug}/webhook — current config (never the secret) + recent deliveries. */
export async function getAdminBoardWebhook(boardSlug: string): Promise<BoardWebhookSettingsData> {
  return request<BoardWebhookSettingsData>('GET', accountPath(`/admin/boards/${boardSlug}/webhook`))
}

/**
 * PUT /admin/boards/{slug}/webhook — sets/replaces the URL. Always issues a
 * fresh secret, returned exactly once in this response — never retrievable
 * again afterwards.
 */
export async function saveAdminBoardWebhook(
  boardSlug: string,
  url: string,
): Promise<{ ok: boolean; secret: string }> {
  return request<{ ok: boolean; secret: string }>(
    'PUT',
    accountPath(`/admin/boards/${boardSlug}/webhook`),
    { url },
  )
}

/** PATCH /admin/boards/{slug}/webhook — pause/resume without rotating the URL or secret. */
export async function setAdminBoardWebhookActive(
  boardSlug: string,
  active: boolean,
): Promise<{ ok: boolean; active: boolean }> {
  return request<{ ok: boolean; active: boolean }>(
    'PATCH',
    accountPath(`/admin/boards/${boardSlug}/webhook`),
    { active },
  )
}

/** DELETE /admin/boards/{slug}/webhook — removes the configuration entirely. */
export async function deleteAdminBoardWebhook(boardSlug: string): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', accountPath(`/admin/boards/${boardSlug}/webhook`))
}

// ── Admin: Slack/Discord chat notifications ─────────────────────────────────────

export type ChatProvider = 'slack' | 'discord'

export interface ChatIntegration {
  provider: ChatProvider
  /** Non-secret display form ("hooks.slack.com/…a1b2") — the full URL is never returned. */
  url_hint: string
  last_delivery_at: string | null
  last_delivery_ok: boolean | null
  last_delivery_error: string | null
  updated_at: string
}

export interface ChatNotificationSettings {
  /** False when the account's plan doesn't include chat notifications. */
  allowed: boolean
  integrations: Record<ChatProvider, ChatIntegration | null>
}

/** GET /admin/boards/{slug}/chat-notifications */
export async function getAdminBoardChatNotifications(
  boardSlug: string,
): Promise<ChatNotificationSettings> {
  return request<ChatNotificationSettings>(
    'GET',
    accountPath(`/admin/boards/${boardSlug}/chat-notifications`),
  )
}

/**
 * PUT /admin/boards/{slug}/chat-notifications/{provider} — sets/replaces the
 * incoming-webhook URL. ApiError(422, 'plan_limit_chat_notifications' |
 * 'validation_error').
 */
export async function saveAdminBoardChatNotification(
  boardSlug: string,
  provider: ChatProvider,
  url: string,
): Promise<{ ok: boolean; provider: ChatProvider; url_hint: string }> {
  return request<{ ok: boolean; provider: ChatProvider; url_hint: string }>(
    'PUT',
    accountPath(`/admin/boards/${boardSlug}/chat-notifications/${provider}`),
    { url },
  )
}

/** DELETE /admin/boards/{slug}/chat-notifications/{provider} */
export async function deleteAdminBoardChatNotification(
  boardSlug: string,
  provider: ChatProvider,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'DELETE',
    accountPath(`/admin/boards/${boardSlug}/chat-notifications/${provider}`),
  )
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
