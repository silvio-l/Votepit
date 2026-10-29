/**
 * Operator panel (platform super-admin), public abuse reports and
 * operator announcements.
 */

import type { BoardVisibility } from './boardAdmin'
import { request } from './client'
import type {
  FaqEntry,
  ListOperatorSupportRequestsParams,
  SupportCategory,
  SupportRequestStatus,
  SupportRequestSummary,
  SupportThreadResponse,
  TranslateSupportMessagesResponse,
} from './support'

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
  /** Config::translationConfigured() — read-only, no secret exposed. */
  translation_configured: boolean
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

/** Result of the operator-only Community update check (GET /operator/update). */
export interface OperatorUpdateStatus {
  installed_version: string
  latest_version: string | null
  update_available: boolean
  release_url: string | null
  docs_url: string | null
  checked_at: string | null
  update_check_enabled: boolean
}

/**
 * GET /operator/update — whether a newer Community release is known. Advisory
 * only: callers should treat a failure as "no banner", never as a page error.
 */
export async function getOperatorUpdateStatus(): Promise<OperatorUpdateStatus> {
  return request<OperatorUpdateStatus>('GET', '/operator/update')
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

/**
 * POST /operator/support/{id}/translate — bulk-translates every message of
 * one ticket into `targetLocale`, unscoped (operator side). AuthZ:
 * support() — is_support OR is_operator.
 */
export async function translateOperatorSupportThread(
  id: number,
  targetLocale: string,
): Promise<TranslateSupportMessagesResponse> {
  return request<TranslateSupportMessagesResponse>('POST', `/operator/support/${id}/translate`, {
    target_locale: targetLocale,
  })
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
