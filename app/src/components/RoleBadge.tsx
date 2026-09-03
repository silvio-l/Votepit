/**
 * RoleBadge / RoleIcon — the forum-style owner/moderator mark.
 *
 * One shared rendering for every surface that names an account role:
 * MembersPage (member table), PublicProfilePage and AuthorBadge (idea and
 * comment authors). The badge is deliberately independent of the author's
 * profile-visibility setting (profile-visibility feature): a moderator stays
 * recognisable as a moderator even while anonymous — that is a property of
 * the account, not of the person's profile.
 */

import { Badge, cx } from '@votepit/ui'
import { Crown, Shield } from 'lucide-react'
import type { AccountRole } from '../lib/api'
import { useT } from '../lib/i18n/context'

/** Owner gets the crown, moderator the shield — a quick visual scan of who can do what. */
export function RoleIcon({ role }: { role: AccountRole }) {
  const Icon = role === 'owner' ? Crown : Shield
  return <Icon size={13} aria-hidden="true" className="shrink-0" />
}

export function RoleBadge({ role, className }: { role: AccountRole; className?: string }) {
  const t = useT('membersPage')
  return (
    <Badge tone={role === 'owner' ? 'ink' : 'neutral'} className={cx('gap-1', className)}>
      <RoleIcon role={role} />
      {role === 'owner' ? t('roleOwner') : t('roleModerator')}
    </Badge>
  )
}
