/**
 * MemberStandingBadge — repeat-offender escalation state indicator
 * (board-operator-compliance issue 10, account_member_standing.state).
 *
 * 'normal' renders nothing (the common case — most members are never
 * moderated; a blank cell reads faster than a "Normal" badge on every
 * row). flagged/warned/suspended escalate tone info → warning → danger,
 * matching how those tones are already used elsewhere for a rising
 * severity scale (see Badge's toneClasses).
 */

import { Badge } from '@votepit/ui/ds'
import type { MemberStandingState } from '../lib/api'
import { useT } from '../lib/i18n/context'

const STANDING_TONE = {
  flagged: 'info',
  warned: 'warning',
  suspended: 'danger',
} as const

const STANDING_LABEL_KEY = {
  flagged: 'standingFlagged',
  warned: 'standingWarned',
  suspended: 'standingSuspended',
} as const

export function MemberStandingBadge({
  state,
  className,
}: {
  state: MemberStandingState
  className?: string
}) {
  const t = useT('membersPage')
  if (state === 'normal') return null

  return (
    <Badge tone={STANDING_TONE[state]} dot className={className}>
      {t(STANDING_LABEL_KEY[state])}
    </Badge>
  )
}
