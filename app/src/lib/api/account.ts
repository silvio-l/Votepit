/**
 * Account settings, GDPR account deletion and data export.
 */

import { accountPath } from '../accountContext'
import { downloadFile, request } from './client'

// ── Account settings: summary + GDPR account deletion ─────────────────────────

export interface AccountSettingsData {
  account_id: number
  /** The "Delete account" typed-confirmation input compares against this client-side. */
  slug: string
  name: string
  /**
   * True for the self-host installation's single, undeletable account
   * (is_default=1). The SPA hides the "Delete account" danger zone
   * entirely when true — AccountDeleteAction rejects it server-side anyway.
   */
  is_default_account: boolean
  /** Pending deletion grace-period deadline, or null if none is scheduled. */
  deletion_scheduled_at: string | null
  /**
   * Board-operator identity (board-operator-compliance issue 02, extended by
   * migrations/0064_add_legal_operator_contact.sql) — shown as
   * "Operated by ..." on any public board page. Null until the owner sets
   * it; a board cannot be made public/unlisted until name, address AND
   * email are all non-empty (see renameAccount()'s field errors).
   * `legal_operator_notice` is supplementary free text, never required.
   */
  legal_operator_name: string | null
  legal_operator_address: string | null
  legal_operator_email: string | null
  legal_operator_notice: string | null
}

/** GET /admin/account — owner-only account summary for the account settings page. */
export async function getAccountSettings(): Promise<AccountSettingsData> {
  return request<AccountSettingsData>('GET', accountPath('/admin/account'))
}

/**
 * PUT /admin/account — renames the account's name and/or slug, and/or
 * updates its legal-operator identity. All fields are independent and
 * optional — pass only the one(s) being changed; an omitted field keeps its
 * current value. Unlike a board slug, the account slug is unique
 * platform-wide (not just within the account) — see
 * checkAccountSlugAvailable() for the live-typing check.
 * legal_operator_name/legal_operator_address/legal_operator_email/
 * legal_operator_notice: an explicit empty string clears the field back to
 * null (unlike name/slug, which must never be empty). ApiError(422) with
 * fields.{name,slug,legal_operator_name,legal_operator_address,
 * legal_operator_email,legal_operator_notice} on validation/collision.
 * AuthZ: accountOwner + CSRF — call bootstrap() first.
 */
export async function renameAccount(data: {
  name?: string
  slug?: string
  legal_operator_name?: string
  legal_operator_address?: string
  legal_operator_email?: string
  legal_operator_notice?: string
}): Promise<{
  ok: boolean
  slug: string
  name: string
  legal_operator_name: string | null
  legal_operator_address: string | null
  legal_operator_email: string | null
  legal_operator_notice: string | null
}> {
  return request<{
    ok: boolean
    slug: string
    name: string
    legal_operator_name: string | null
    legal_operator_address: string | null
    legal_operator_email: string | null
    legal_operator_notice: string | null
  }>('PUT', accountPath('/admin/account'), data)
}

/**
 * GET /admin/account/slug-available?slug=... — live global-uniqueness check
 * for the account-rename form (debounced while typing). Returns
 * `available: false` for both a taken slug and a format-invalid one — the
 * caller shows format errors from SlugValidator client-side separately, so
 * this endpoint only needs to answer "would saving this slug work right
 * now". AuthZ: accountOwner (same tier as the rest of account settings).
 */
export async function checkAccountSlugAvailable(slug: string): Promise<{ available: boolean }> {
  return request<{ available: boolean }>(
    'GET',
    accountPath(`/admin/account/slug-available?slug=${encodeURIComponent(slug)}`),
  )
}

/**
 * POST /admin/account/delete — schedules GDPR account deletion after a 48h
 * grace period. AuthZ: accountOwner. `confirmSlug` must match the account's
 * real slug exactly (re-validated server-side — see AccountDeleteAction
 * class doc); a mismatch returns ApiError(422, 'confirmation_mismatch').
 * ApiError(422, 'default_account_undeletable') on a self-host installation's
 * only account. An extension's deletion precondition may block with its own
 * status/key (e.g. an unsettled paid subscription).
 */
export async function requestAccountDeletion(
  confirmSlug: string,
): Promise<{ ok: boolean; deletion_scheduled_at: string }> {
  return request<{ ok: boolean; deletion_scheduled_at: string }>(
    'POST',
    accountPath('/admin/account/delete'),
    { confirm_slug: confirmSlug },
  )
}

/**
 * POST /admin/account/delete/cancel — undoes a pending deletion while the
 * grace period is still running. AuthZ: accountOwner.
 */
export async function cancelAccountDeletion(): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath('/admin/account/delete/cancel'), {})
}

// ── Account data export (customer self-export, GDPR Art. 20) ─────────────────

export type ExportFormat = 'json' | 'csv'

/**
 * GET /admin/export?format=json|csv — downloads the account's full data
 * export and triggers a browser save (see downloadFile()). AuthZ:
 * accountOwner.
 *
 * The server's `Content-Disposition` header names the file
 * (`votepit-export-<slug>-<timestamp>.json|zip`); it is used verbatim when
 * present, with a generic fallback name otherwise.
 */
export async function downloadAccountExport(format: ExportFormat = 'json'): Promise<void> {
  await downloadFile(
    accountPath(`/admin/export?format=${format}`),
    format === 'csv' ? 'application/zip' : 'application/json',
    `votepit-export.${format === 'csv' ? 'zip' : 'json'}`,
  )
}
