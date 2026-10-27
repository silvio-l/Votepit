/**
 * Grouping rules of the board list (G-09). Groups follow the status categories
 * in the owner's order; Done, Declined and Archived start collapsed. The
 * collapsed choice is remembered per viewer; storage can be blocked, so every
 * access is guarded.
 */

import type { IdeaStatus } from './api'

/** Group order: open work first, closed outcomes at the bottom. */
export const GROUP_ORDER: readonly IdeaStatus[] = [
  'open',
  'planned',
  'in_progress',
  'done',
  'declined',
  'archived',
]

/** Groups that start collapsed when the viewer has no stored choice. */
const DEFAULT_COLLAPSED: readonly IdeaStatus[] = ['done', 'declined', 'archived']

/** Ideas requested per "Load more" click (the API's page size). */
export const GROUP_PAGE_SIZE = 50

const STORAGE_KEY = 'votepit.board.collapsedGroups'

/** Collapsed groups for this viewer; falls back to the defaults when nothing valid is stored. */
export function readCollapsedGroups(storage: Storage | null = safeStorage()): Set<IdeaStatus> {
  if (storage === null) return new Set(DEFAULT_COLLAPSED)
  try {
    const raw = storage.getItem(STORAGE_KEY)
    if (raw === null) return new Set(DEFAULT_COLLAPSED)
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return new Set(DEFAULT_COLLAPSED)
    return new Set(
      parsed.filter((value): value is IdeaStatus => GROUP_ORDER.includes(value as IdeaStatus)),
    )
  } catch {
    return new Set(DEFAULT_COLLAPSED)
  }
}

/** Stores the collapsed groups; a blocked or full storage is ignored. */
export function writeCollapsedGroups(
  collapsed: ReadonlySet<IdeaStatus>,
  storage: Storage | null = safeStorage(),
): void {
  if (storage === null) return
  try {
    storage.setItem(STORAGE_KEY, JSON.stringify([...collapsed]))
  } catch {
    // Private mode or quota: the page works without remembering the choice.
  }
}

/** Ideas still to load in a group. Never negative. */
export function remainingIn(total: number, loaded: number): number {
  return Math.max(0, total - loaded)
}

function safeStorage(): Storage | null {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null
  } catch {
    return null
  }
}
