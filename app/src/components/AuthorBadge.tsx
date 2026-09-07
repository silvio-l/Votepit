/**
 * AuthorBadge — the compact "who wrote this" line for ideas and comments
 * (profile-visibility feature).
 *
 * Three renderings, all the same height as the surrounding meta text, and
 * ALL linked to the public profile page — including your own, so you can see
 * exactly what other people see when they open your profile (same page,
 * same visibility rules, no special-cased "self" view):
 *   - the current user              → own avatar + "You" (no lookup request
 *                                     — myAvatarUrl is already known)
 *   - any other author, visible     → their avatar + their username, or
 *                                     "Voter" if they haven't set one
 *   - any other author, anonymous   → silhouette + "Voter"
 *     (default) or lookup-failed
 *
 * There is no real name anywhere in the system (ADR 0002 — identity is a
 * pseudonymised email HMAC only) — the username is a purely optional,
 * self-chosen public label (migration 0022), never derived from the email.
 * "Voter" is the fallback for everyone but yourself: an anonymous profile,
 * or a visible one that never set a username. The single highest-ranking
 * role badge (operator outranks any account role) is appended in every
 * branch regardless of visibility — see HighestRoleBadge.
 */

import { Skeleton } from '@votepit/ui'
import { Link } from 'react-router-dom'
import type { PublicProfileCache } from '../hooks/usePublicProfile'
import { usePublicProfile } from '../hooks/usePublicProfile'
import { accountPath } from '../lib/accountContext'
import type { AccountRole } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { Avatar } from './Avatar'
import { HighestRoleBadge } from './RoleBadge'

interface AuthorBadgeProps {
  authorId: number
  currentUserId: number | null
  /** The current user's own avatar — used only when authorId is the current user. */
  myAvatarUrl: string | null
  /** The current user's role in this account — badge for the "You" branch. */
  currentUserRole: AccountRole | null
  /** The current user's platform-wide operator flag — outranks any account role, see HighestRoleBadge. */
  currentUserIsOperator: boolean
  cache: PublicProfileCache
  className?: string
}

const AVATAR_SIZE = 18

export function AuthorBadge({
  authorId,
  currentUserId,
  myAvatarUrl,
  currentUserRole,
  currentUserIsOperator,
  cache,
  className = '',
}: AuthorBadgeProps) {
  const t = useT('authorBadge')
  const isSelf = currentUserId !== null && authorId === currentUserId
  const state = usePublicProfile(isSelf ? null : authorId, cache)

  const wrapperClass = `inline-flex items-center gap-1.5 ${className}`

  if (isSelf) {
    return (
      <span className={wrapperClass}>
        <Link
          to={accountPath(`/members/${authorId}/profile`)}
          className="inline-flex items-center gap-1.5 font-medium text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
        >
          <Avatar avatarUrl={myAvatarUrl} size={AVATAR_SIZE} alt="" />
          {t('you')}
        </Link>
        <HighestRoleBadge isOperator={currentUserIsOperator} role={currentUserRole} />
      </span>
    )
  }

  if (state.status === 'loading') {
    return (
      <span className={wrapperClass} aria-busy="true">
        <Avatar avatarUrl={null} size={AVATAR_SIZE} alt="" />
        <Skeleton className="h-3 w-10" />
      </span>
    )
  }

  // A failed lookup falls back to the anonymous rendering — the safe default.
  const profile = state.status === 'ready' ? state.profile : null
  const role = profile?.role ?? null
  const isOperator = profile?.is_operator ?? false
  const avatarUrl = profile?.visible === true ? profile.avatar_url : null
  const displayName = (profile?.visible === true ? profile.username : null) ?? t('voter')

  return (
    <span className={wrapperClass}>
      <Link
        to={accountPath(`/members/${authorId}/profile`)}
        className="inline-flex items-center gap-1.5 font-medium text-vp-text-secondary hover:text-vp-ink hover:underline transition-colors duration-150"
      >
        <Avatar avatarUrl={avatarUrl} size={AVATAR_SIZE} alt="" />
        {displayName}
      </Link>
      <HighestRoleBadge isOperator={isOperator} role={role} />
    </span>
  )
}
