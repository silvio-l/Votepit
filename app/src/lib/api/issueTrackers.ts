/**
 * Linear/GitHub issue-tracker links: the account connection (account admin)
 * and per-idea "create issue" links (owner, admin or moderator).
 */

import { accountPath } from '../accountContext'
import { request } from './client'

export type IssueTrackerProvider = 'linear' | 'github'

export interface IssueTrackerConnection {
  provider: IssueTrackerProvider
  /** Linear team key or GitHub "owner/repo". */
  target: string
  /** Non-secret display form ("…a1b2") — the token itself is never returned. */
  token_hint: string
  /** URL to register as the provider webhook for the status sync. */
  webhook_url: string
  /** False while no webhook signing secret is stored (Linear: none pasted yet). */
  sync_enabled: boolean
  updated_at: string
}

export interface IssueTrackerSettings {
  /** False when the account's plan doesn't include issue-tracker links. */
  allowed: boolean
  connections: Record<IssueTrackerProvider, IssueTrackerConnection | null>
}

export interface IssueTrackerConnectBody {
  /** Optional when already connected (the stored token is kept). */
  token?: string
  /** Linear only. */
  team_key?: string
  /** Linear only: the signing secret Linear shows for the webhook. */
  webhook_secret?: string
  /** GitHub only: "owner/repo". */
  repository?: string
}

export interface IssueTrackerConnectResult {
  ok: boolean
  connection: IssueTrackerConnection
  /** GitHub, first connect only: the webhook secret to paste into GitHub (shown once). */
  webhook_secret?: string
}

/** GET /admin/issue-trackers */
export async function getIssueTrackers(): Promise<IssueTrackerSettings> {
  return request<IssueTrackerSettings>('GET', accountPath('/admin/issue-trackers'))
}

/**
 * PUT /admin/issue-trackers/{provider} — connects/updates after verifying the
 * token against the provider. ApiError(422, 'plan_limit_issue_tracker' |
 * 'validation_error' | 'issue_tracker_invalid_credentials' |
 * 'issue_tracker_target_not_found') or ApiError(502, 'issue_tracker_unavailable').
 */
export async function connectIssueTracker(
  provider: IssueTrackerProvider,
  body: IssueTrackerConnectBody,
): Promise<IssueTrackerConnectResult> {
  return request<IssueTrackerConnectResult>(
    'PUT',
    accountPath(`/admin/issue-trackers/${provider}`),
    body,
  )
}

/** DELETE /admin/issue-trackers/{provider} — existing idea links stay. */
export async function disconnectIssueTracker(
  provider: IssueTrackerProvider,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>('DELETE', accountPath(`/admin/issue-trackers/${provider}`))
}

export interface IdeaIssueLink {
  provider: IssueTrackerProvider
  /** "ENG-42" / "#7" */
  external_key: string
  url: string
  created_at: string
}

export interface IdeaIssueLinks {
  allowed: boolean
  /** Providers the account has connected. */
  providers: IssueTrackerProvider[]
  links: IdeaIssueLink[]
}

/** GET /admin/boards/{slug}/ideas/{id}/issue-links */
export async function getIdeaIssueLinks(
  boardSlug: string,
  ideaId: number,
): Promise<IdeaIssueLinks> {
  return request<IdeaIssueLinks>(
    'GET',
    accountPath(`/admin/boards/${boardSlug}/ideas/${ideaId}/issue-links`),
  )
}

/**
 * POST /admin/boards/{slug}/ideas/{id}/issue-links/{provider} — creates the
 * issue in the tracker and stores the link. ApiError(409, 'issue_link_exists'),
 * ApiError(422, 'plan_limit_issue_tracker' | 'issue_tracker_not_connected' | …).
 */
export async function createIdeaIssue(
  boardSlug: string,
  ideaId: number,
  provider: IssueTrackerProvider,
): Promise<{ ok: boolean; link: Omit<IdeaIssueLink, 'created_at'> }> {
  return request<{ ok: boolean; link: Omit<IdeaIssueLink, 'created_at'> }>(
    'POST',
    accountPath(`/admin/boards/${boardSlug}/ideas/${ideaId}/issue-links/${provider}`),
  )
}

/** DELETE /admin/boards/{slug}/ideas/{id}/issue-links/{provider} — the issue itself stays. */
export async function deleteIdeaIssueLink(
  boardSlug: string,
  ideaId: number,
  provider: IssueTrackerProvider,
): Promise<{ ok: boolean }> {
  return request<{ ok: boolean }>(
    'DELETE',
    accountPath(`/admin/boards/${boardSlug}/ideas/${ideaId}/issue-links/${provider}`),
  )
}
