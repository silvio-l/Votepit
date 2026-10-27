/**
 * Board status registry helpers (broaden-use-cases issue 03).
 *
 * Every idea status belongs to one of six fixed categories (the canonical
 * slugs). The category drives colour, roadmap column and transitions; the
 * label is the owner's plaintext override or the default translation. The
 * label rule mirrors the server's BoardStatuses::resolveLabel(): override in
 * the viewer's language → default translation of a system status → any
 * owner label (en, then de) → the raw key.
 */

import type { IdeaStatus as ComponentStatus } from '@votepit/ui/ds'
import { useMemo } from 'react'
import type { BoardStatusInfo, IdeaStatus } from './api'
import { useT } from './i18n/context'

export const STATUS_CATEGORIES: readonly IdeaStatus[] = [
  'open',
  'planned',
  'in_progress',
  'done',
  'declined',
  'archived',
]

/**
 * Fallback for payloads without `allowed_next`: the default board's graph
 * (mirror of StatusService::TRANSITIONS). The server stays authoritative.
 */
const DEFAULT_TRANSITIONS: Record<IdeaStatus, IdeaStatus[]> = {
  open: ['planned', 'in_progress', 'done', 'declined'],
  planned: ['in_progress', 'done', 'declined', 'open'],
  in_progress: ['done', 'declined', 'planned'],
  done: ['in_progress', 'declined', 'archived'],
  declined: ['open'],
  archived: ['done'],
}

export function defaultAllowedNext(status: string): string[] {
  return DEFAULT_TRANSITIONS[status as IdeaStatus] ?? []
}

/** Canonical category slug (underscore) → @votepit/ui Status (hyphen). */
export function toComponentStatus(raw: string): ComponentStatus {
  if (raw === 'in_progress') return 'in-progress'
  return (STATUS_CATEGORIES as readonly string[]).includes(raw) ? (raw as ComponentStatus) : 'open'
}

export function findStatus(
  statuses: BoardStatusInfo[] | undefined,
  key: string | undefined,
): BoardStatusInfo | undefined {
  if (statuses === undefined || key === undefined) return undefined
  return statuses.find((s) => s.key === key)
}

/**
 * Translated default labels of the six categories. The one source of truth:
 * the idea page, the decision dialog and the worklist all read it.
 */
export function useStatusDefaultLabels(): Record<IdeaStatus, string> {
  const t = useT('ideaDetailPage')
  return useMemo(
    () => ({
      open: t('statusLabelOpen'),
      planned: t('statusLabelPlanned'),
      in_progress: t('statusLabelInProgress'),
      done: t('statusLabelDone'),
      declined: t('statusLabelDeclined'),
      archived: t('statusLabelArchived'),
    }),
    [t],
  )
}

/** The fields the label rule reads; a registry row and a worklist row both carry them. */
export type StatusLabelFields = Pick<
  BoardStatusInfo,
  'key' | 'category' | 'is_system' | 'label_de' | 'label_en'
>

/**
 * Display label of one status. `defaults` are the translated labels of the six
 * categories; a system status without an override gets its default.
 */
export function labelOfStatus(
  status: StatusLabelFields,
  language: string,
  defaults: Record<IdeaStatus, string>,
): string {
  const own = language === 'de' ? status.label_de : language === 'en' ? status.label_en : null
  if (own) return own
  if (status.is_system) return defaults[status.category] ?? status.key
  return status.label_en ?? status.label_de ?? status.key
}

/**
 * Display label of a status key on a board. `defaults` are the translated labels of the six
 * categories; `key` falls back to the category when the payload predates
 * status keys.
 */
export function statusLabel(
  statuses: BoardStatusInfo[] | undefined,
  key: string | undefined,
  category: IdeaStatus,
  language: string,
  defaults: Record<IdeaStatus, string>,
): string {
  const status = findStatus(statuses, key ?? category)
  if (status === undefined) return defaults[category] ?? category
  return labelOfStatus(status, language, defaults)
}

/** Whether a status still accepts votes/comments (unknown status → open). */
export function statusAllows(
  statuses: BoardStatusInfo[] | undefined,
  key: string | undefined,
  what: 'votes' | 'comments',
): boolean {
  const status = findStatus(statuses, key)
  if (status === undefined) return true
  return what === 'votes' ? status.allow_votes : status.allow_comments
}
