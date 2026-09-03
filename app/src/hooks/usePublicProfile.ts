/**
 * usePublicProfile — loads another user's public profile (profile-visibility
 * feature) through a caller-owned cache, so a page showing the same author
 * on the idea and on five of its comments issues ONE request, not six.
 *
 * The cache is deliberately NOT module-level: it is created by the page
 * (`useRef(createPublicProfileCache())`) and handed to every AuthorBadge on
 * it, so it lives exactly as long as that page — a visibility change made
 * elsewhere is picked up on the next page load, and tests stay isolated.
 */

import { useEffect, useState } from 'react'
import type { PublicProfile } from '../lib/api'
import { getPublicProfile } from '../lib/api'

export type PublicProfileCache = Map<number, Promise<PublicProfile>>

export function createPublicProfileCache(): PublicProfileCache {
  return new Map()
}

export type PublicProfileState =
  | { status: 'loading' }
  | { status: 'ready'; profile: PublicProfile }
  | { status: 'error' }

function loadCached(cache: PublicProfileCache, userId: number): Promise<PublicProfile> {
  const hit = cache.get(userId)
  if (hit !== undefined) return hit
  const pending = getPublicProfile(userId).catch((err: unknown) => {
    // A failed load must not poison the cache for the rest of the page —
    // the next badge for this author gets a fresh attempt.
    cache.delete(userId)
    throw err
  })
  cache.set(userId, pending)
  return pending
}

/**
 * `userId === null` skips loading entirely (the caller renders the current
 * user without a request) and stays in `loading` — callers never read the
 * state in that case.
 */
export function usePublicProfile(
  userId: number | null,
  cache: PublicProfileCache,
): PublicProfileState {
  const [state, setState] = useState<PublicProfileState>({ status: 'loading' })

  useEffect(() => {
    if (userId === null) return
    let cancelled = false
    setState({ status: 'loading' })
    loadCached(cache, userId)
      .then((profile) => {
        if (!cancelled) setState({ status: 'ready', profile })
      })
      .catch(() => {
        if (!cancelled) setState({ status: 'error' })
      })
    return () => {
      cancelled = true
    }
  }, [userId, cache])

  return state
}
