/**
 * RoleBadge / RoleIcon — the forum-style owner/admin/moderator mark.
 *
 * One shared rendering for every surface that names an account role:
 * MembersPage (member table), PublicProfilePage and AuthorBadge (idea and
 * comment authors). The badge is deliberately independent of the author's
 * profile-visibility setting (profile-visibility feature): a moderator stays
 * recognisable as a moderator even while anonymous — that is a property of
 * the account, not of the person's profile.
 */

import { Badge, cx } from '@votepit/ui'
import { Crown, Settings2, Shield, User, Wrench } from 'lucide-react'
import type { AccountRole } from '../lib/api'
import { useT } from '../lib/i18n/context'

const ROLE_ICON = {
  owner: Crown,
  admin: Settings2,
  moderator: Shield,
  /** No admin/moderation rights — a private-board voter, hence the plain person icon. */
  member: User,
} as const

/** Owner gets the crown, admin the gear, moderator the shield, member a plain person — a quick visual scan of who can do what. */
export function RoleIcon({ role }: { role: AccountRole }) {
  const Icon = ROLE_ICON[role]
  return <Icon size={13} aria-hidden="true" className="shrink-0" />
}

const ROLE_LABEL_KEY = {
  owner: 'roleOwner',
  admin: 'roleAdmin',
  moderator: 'roleModerator',
  member: 'roleMember',
} as const

export function RoleBadge({ role, className }: { role: AccountRole; className?: string }) {
  const t = useT('membersPage')
  return (
    <Badge tone={role === 'owner' ? 'ink' : 'neutral'} className={cx('gap-1', className)}>
      <RoleIcon role={role} />
      {t(ROLE_LABEL_KEY[role])}
    </Badge>
  )
}

/**
 * Platform-wide Operator mark (`users.is_operator` — strictly above account
 * owner/admin, see AuthZMiddleware). Independent of AccountRole: an operator
 * is not necessarily a member of the account being viewed at all, so this
 * is a sibling component rather than a third RoleBadge value.
 */
export function OperatorBadge({ className }: { className?: string }) {
  const t = useT('membersPage')
  return (
    <Badge tone="accent" className={cx('gap-1', className)}>
      <Wrench size={13} aria-hidden="true" className="shrink-0" />
      {t('roleOperator')}
    </Badge>
  )
}

/**
 * Wherever a person's role is named against ONE account, show only the
 * single highest-ranking badge, never both stacked — an operator who also
 * happens to own the account they're looking at is still, first and
 * foremost, the operator (strictly above any account role, see
 * AuthZMiddleware); showing "Owner" there undersells what they actually are.
 * `null` when the person holds no rank worth a badge here (not an operator,
 * and no membership in this account).
 */
export function HighestRoleBadge({
  isOperator,
  role,
  className,
}: {
  isOperator: boolean
  role: AccountRole | null
  className?: string
}) {
  if (isOperator) return <OperatorBadge className={className} />
  if (role !== null) return <RoleBadge role={role} className={className} />
  return null
}
