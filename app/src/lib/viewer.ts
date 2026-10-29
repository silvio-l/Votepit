/**
 * The signed-in viewer as last reported by GET /api/bootstrap, so components
 * deep in the tree (e.g. PlanUpgradeLink) can tell whether the viewer may
 * change the plan without threading the user through every prop chain.
 *
 * Module-level state, same pattern as accountContext.ts: bootstrap() in
 * api/client.ts refreshes it on every call, and pages call bootstrap() on
 * mount, so it is current for whichever page is open. Null before the first
 * bootstrap and for logged-out visitors (every role check then fails closed).
 */

import { getAccountSlug } from './accountContext'
import type { AccountRole } from './api/members'
import { accountRoleFor, type User } from './api/model'
import type { Entitlements } from './entitlements'

let currentViewer: User | null = null

export function setViewer(user: User | null): void {
  currentViewer = user
}

/** The viewer's role in the account of the current route, or null. */
export function getViewerRole(): AccountRole | null {
  return accountRoleFor(currentViewer, getAccountSlug())
}

/**
 * Entitlements of the account in the current route (P-38), from the viewer's
 * own memberships. Null for logged-out viewers and non-members: they never get
 * a plan, and locked states then fall back to the generic copy.
 */
export function getViewerEntitlements(): Entitlements | null {
  const memberships = currentViewer?.memberships ?? []
  const slug = getAccountSlug()
  const membership =
    slug === null ? memberships[0] : memberships.find((m) => m.account_slug === slug)
  return membership?.entitlements ?? null
}
