/**
 * Support requests (customer side) and the public FAQ.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

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

/** One support message's translation outcome — `ok: false` carries no translated fields. */
export type TranslatedSupportMessage =
  | {
      id: number
      ok: true
      translated_body: string
      detected_source_locale: string
      cached: boolean
    }
  | { id: number; ok: false }

export interface TranslateSupportMessagesResponse {
  messages: TranslatedSupportMessage[]
}

/**
 * POST /admin/support/{id}/translate — bulk-translates every message of one
 * of the caller's own tickets into `targetLocale` in one request (same
 * whole-thread-in-one-call shape as translateComments()). AuthZ:
 * accountAdmin, scoped to the caller's account (ApiError(404) for another
 * tenant's ticket). ApiError(503, key=translation_unavailable) if no
 * provider is configured at all.
 */
export async function translateMySupportThread(
  id: number,
  targetLocale: string,
): Promise<TranslateSupportMessagesResponse> {
  return request<TranslateSupportMessagesResponse>(
    'POST',
    accountPath(`/admin/support/${id}/translate`),
    { target_locale: targetLocale },
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
