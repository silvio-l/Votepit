/**
 * Account members, invitations and invite acceptance.
 */

import { accountPath } from '../accountContext'
import { request } from './client'

// ── Admin: Members & invites ───────────────────────────────────────────────────

export type AccountRole = 'owner' | 'admin' | 'moderator' | 'member'
/** Roles MemberAction::changeRole() accepts as a target — never 'owner' (see its class doc). */
export type AssignableAccountRole = 'admin' | 'moderator' | 'member'

/**
 * Repeat-offender escalation state (board-operator-compliance issue 10,
 * account_member_standing.state) — normal → flagged → warned → suspended
 * under the automatic computation (Domain\ModerationStandingService); a
 * manual override (PATCH /admin/members/{id}/standing) may set any value,
 * including a downgrade.
 */
export type MemberStandingState = 'normal' | 'flagged' | 'warned' | 'suspended'

export interface MemberStanding {
  violation_count: number
  state: MemberStandingState
  /** Present only once a change has actually happened (seeded default rows omit it). */
  state_changed_at?: string
  /** NULL/absent = the last change was automatic; set = a manual override, see MemberAction's class doc. */
  state_changed_by_user_id?: number | null
}

export interface MemberSummary {
  user_id: number
  /** Random opaque handle, safe to display — user_id above is internal-only. */
  public_id: string | null
  username: string | null
  role: AccountRole
  created_at: string
  /** board-operator-compliance issue 10 — always present, defaults to {violation_count: 0, state: 'normal'}. */
  standing: MemberStanding
}

export interface PendingInvite {
  id: number
  user_id: number
  role: AccountRole
  expires_at: string
  created_at: string
}

export interface MembersData {
  members: MemberSummary[]
  invites: PendingInvite[]
  viewer_role: AccountRole | null
}

/**
 * GET /admin/members — list account members + pending invites.
 * AuthZ: accountAdmin (owner AND moderator may read) — throws ApiError(401)
 * for anon, ApiError(403) without account membership.
 * `viewer_role` tells the caller's own role so the SPA can gate owner-only UI
 * (invite form, remove, role change, revoke) without a second round trip.
 */
export async function listMembers(): Promise<MembersData> {
  return request<MembersData>('GET', accountPath('/admin/members'))
}

/**
 * POST /admin/invites — invite a new member by email with a given role
 * (server-enforced — owners are never created via invite, only
 * AssignableAccountRole values are accepted).
 * AuthZ: accountOwner — throws ApiError(403) for a moderator (invite is
 * owner-only), ApiError(422) with fields.email for invalid/already-a-member/
 * self-invite, or fields.role for an invalid role. Requires CSRF — call
 * bootstrap() first.
 */
export async function inviteMember(
  email: string,
  role: AssignableAccountRole = 'member',
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath('/admin/invites'), { email, role })
}

/**
 * POST /admin/invites/{id}/revoke — cancel a still-pending invite.
 * AuthZ: accountOwner. Throws ApiError(404) for an unknown/already-resolved/
 * foreign-account invite id.
 */
export async function revokeInvite(inviteId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/invites/${inviteId}/revoke`), {})
}

/**
 * POST /admin/members/{userId}/remove — remove a member from the account.
 * AuthZ: accountOwner. Throws ApiError(404) for unknown/foreign member,
 * ApiError(422, key='last_owner') when removing the account's last owner.
 */
export async function removeMember(userId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('POST', accountPath(`/admin/members/${userId}/remove`), {})
}

/**
 * POST /admin/members/{userId}/role — change a member's role
 * (admin|moderator|member; never 'owner' — see AssignableAccountRole).
 * AuthZ: accountOwner.
 */
export async function changeMemberRole(
  userId: number,
  role: AssignableAccountRole,
): Promise<{ ok: boolean; role: AccountRole }> {
  return request<{ ok: boolean; role: AccountRole }>(
    'POST',
    accountPath(`/admin/members/${userId}/role`),
    { role },
  )
}

/**
 * PATCH /admin/members/{userId}/standing — manual repeat-offender
 * standing override (board-operator-compliance issue 10). AuthZ:
 * accountModerate (owner|admin|moderator — deliberately not owner-only,
 * see AccountMemberStandingAction's class doc). Throws ApiError(404) for
 * unknown/foreign member, ApiError(422) for an invalid state value.
 */
export async function setMemberStanding(
  userId: number,
  state: MemberStandingState,
): Promise<{ ok: boolean; state: MemberStandingState }> {
  return request<{ ok: boolean; state: MemberStandingState }>(
    'PATCH',
    accountPath(`/admin/members/${userId}/standing`),
    { state },
  )
}

export interface AcceptInviteResponse {
  ok: boolean
  account_id: number
  account_slug: string | null
  role: AccountRole
}

/**
 * GET /invite/accept?token=<plaintext> — accept a pending invite.
 * AuthZ: user (anon → 401 — the SPA then redirects to /login?r=… so the
 * invitee can log in with the invited address, landing back here afterwards).
 * Throws ApiError(400, key='invalid_token') for expired/used/unknown tokens,
 * ApiError(403, key='invite_mismatch') if the logged-in user isn't the
 * invited one.
 */
export async function acceptInvite(token: string): Promise<AcceptInviteResponse> {
  const params = new URLSearchParams({ token })
  return request<AcceptInviteResponse>('GET', `/invite/accept?${params.toString()}`)
}

// ── Trusted authors (broaden-use-cases issue 11) ──────────────────────────────

export interface TrustedAuthor {
  user_id: number
  /** Random opaque handle, safe to display — user_id above is internal-only. */
  public_id: string | null
  username: string | null
  trusted_at: string
  state: MemberStandingState
}

/**
 * GET /admin/trusted-authors — authors whose ideas skip pre-moderation in
 * this account. AuthZ: accountModerate — throws ApiError(401) for anon,
 * ApiError(403) without owner/admin/moderator role.
 */
export async function listTrustedAuthors(): Promise<{ authors: TrustedAuthor[] }> {
  return request<{ authors: TrustedAuthor[] }>('GET', accountPath('/admin/trusted-authors'))
}

/**
 * POST /admin/trusted-authors/{userId}/revoke — the author's next ideas wait
 * for approval again. 404 when the author is not trusted (any more).
 * Requires accountModerate AuthZ + CSRF.
 */
export async function revokeTrustedAuthor(userId: number): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'POST',
    accountPath(`/admin/trusted-authors/${userId}/revoke`),
    {},
  )
}
