/**
 * MembersPage — /admin/members
 *
 * Account-scoped member management (roles & invitations):
 * member table + role change / remove, invite-by-email form, pending-invites
 * table with revoke.
 *
 * Auth gate:
 *   - Anon                  → redirect to /login?r=…
 *   - Not owner/admin       → "no access" message (no data rendered) —
 *                              moderator/member have no admin/moderation
 *                              rights over the account itself (moderator is
 *                              restricted to comment/idea moderation, member
 *                              to private-board access only — see
 *                              AuthZMiddleware::accountModerate()), neither
 *                              reaches this page.
 *   - Member (owner OR admin) → the member/invite lists render. The
 *                              account's owner never appears as a row (see
 *                              MemberAction::list()).
 *
 * `viewer_role` comes back from GET /admin/members itself (not bootstrap) —
 * both owner and admin may read the list, but only an owner sees the
 * invite form, the revoke buttons, the role selector and the remove button
 * (server-enforced via AuthZMiddleware::accountOwner(); this client gate is
 * UX only, mirrors the AdminPage/BoardsAdminPage pattern).
 */

import {
  Button,
  ConfirmDialog,
  cx,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Select,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
  TextField,
} from '@votepit/ui/ds'
import { Clock, KeyRound, UserMinus, UserPlus, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { useIsPhone } from '../components/LocalizedHeader'
import { MemberStandingBadge } from '../components/MemberStandingBadge'
import { PlanUpgradeLink } from '../components/PlanUpgradeLink'
import { RoleBadge, RoleIcon } from '../components/RoleBadge'
import { accountPath } from '../lib/accountContext'
import type {
  ApiError,
  AssignableAccountRole,
  MemberStandingState,
  MemberSummary,
  PendingInvite,
  User,
} from '../lib/api'
import {
  bootstrap,
  changeMemberRole,
  inviteMember,
  listMembers,
  logout,
  removeMember,
  requestMemberPasswordReset,
  revokeInvite,
  setMemberStanding,
} from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function MembersPage() {
  const navigate = useNavigate()
  const t = useT('membersPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  // Below 640 px the table becomes one card per member (same testids, all
  // controls reachable); the table's own scroll wrapper would hide the
  // role/status/remove columns with no cue.
  const isPhone = useIsPhone()
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [isOwner, setIsOwner] = useState(false)

  const [members, setMembers] = useState<MemberSummary[]>([])
  const [invites, setInvites] = useState<PendingInvite[]>([])

  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<AssignableAccountRole>('member')
  const [inviteFieldError, setInviteFieldError] = useState<string | undefined>(undefined)
  const [inviteGeneralError, setInviteGeneralError] = useState<string | null>(null)
  /** The invite failed on the plan's member limit: offer the upgrade path next to it. */
  const [inviteLimitReached, setInviteLimitReached] = useState(false)
  const [inviteSuccess, setInviteSuccess] = useState(false)
  const [inviteSending, setInviteSending] = useState(false)

  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)
  const [removeTarget, setRemoveTarget] = useState<number | null>(null)

  const [resetEmail, setResetEmail] = useState('')
  const [resetSending, setResetSending] = useState(false)
  const [resetError, setResetError] = useState<string | null>(null)
  const [resetSuccess, setResetSuccess] = useState(false)

  const reload = async () => {
    const data = await listMembers()
    setMembers(data.members)
    setInvites(data.invites)
    setIsOwner(data.viewer_role === 'owner')
    return data
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/members'))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)

        await reload()
        if (cancelled) return

        setPageState({ phase: 'ready' })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/members'))}`, {
            replace: true,
          })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 403) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }
        const msg =
          (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleInvite = async (e: React.FormEvent) => {
    e.preventDefault()
    if (inviteSending || inviteEmail.trim() === '') return

    setInviteSending(true)
    setInviteFieldError(undefined)
    setInviteGeneralError(null)
    setInviteLimitReached(false)
    setInviteSuccess(false)

    try {
      await inviteMember(inviteEmail.trim(), inviteRole)
      setInviteEmail('')
      setInviteRole('member')
      setInviteSuccess(true)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      const fieldMsg = apiErr?.payload?.fields?.email
      if (fieldMsg !== undefined) {
        setInviteFieldError(fieldMsg)
      } else if (apiErr?.payload?.key === 'plan_limit_team') {
        setInviteLimitReached(true)
        setInviteGeneralError(t('planLimitTeam'))
      } else {
        setInviteGeneralError(apiErr?.payload?.message ?? t('inviteFailed'))
      }
    } finally {
      setInviteSending(false)
    }
  }

  const handleRemove = async () => {
    if (removeTarget === null) return
    const userId = removeTarget
    setBusyKey(`remove-${userId}`)
    setRowError(null)
    try {
      await removeMember(userId)
      setRemoveTarget(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRemoveTarget(null)
      setRowError(apiErr?.payload?.message ?? t('removeFailed'))
    } finally {
      setBusyKey(null)
    }
  }

  const handleRoleChange = async (userId: number, role: AssignableAccountRole) => {
    if (busyKey !== null) return
    setBusyKey(`role-${userId}`)
    setRowError(null)
    try {
      await changeMemberRole(userId, role)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRowError(apiErr?.payload?.message ?? t('roleChangeFailed'))
    } finally {
      setBusyKey(null)
    }
  }

  const handleStandingChange = async (userId: number, state: MemberStandingState) => {
    if (busyKey !== null) return
    setBusyKey(`standing-${userId}`)
    setRowError(null)
    try {
      await setMemberStanding(userId, state)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRowError(apiErr?.payload?.message ?? t('standingChangeFailed'))
    } finally {
      setBusyKey(null)
    }
  }

  const handlePasswordReset = async (e: React.FormEvent) => {
    e.preventDefault()
    if (resetSending || resetEmail.trim() === '') return

    setResetSending(true)
    setResetError(null)
    setResetSuccess(false)
    try {
      await requestMemberPasswordReset(resetEmail.trim())
      setResetSuccess(true)
      setResetEmail('')
    } catch (err) {
      const apiErr = err as ApiError
      setResetError(
        apiErr?.payload?.key === 'not_found'
          ? t('passwordResetNotFoundError')
          : (apiErr?.payload?.message ?? t('passwordResetGenericError')),
      )
    } finally {
      setResetSending(false)
    }
  }

  const handleRevoke = async (inviteId: number) => {
    if (busyKey !== null) return
    setBusyKey(`revoke-${inviteId}`)
    setRowError(null)
    try {
      await revokeInvite(inviteId)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRowError(apiErr?.payload?.message ?? t('revokeFailed'))
    } finally {
      setBusyKey(null)
    }
  }

  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageState.phase === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={5} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="members-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const memberName = (m: MemberSummary) => (
    <span className="font-medium text-vp-ink">
      {m.username
        ? t('userLabelWithUsername', { id: m.public_id ?? '—', username: m.username })
        : t('userLabel', { id: m.public_id ?? '—' })}
    </span>
  )

  const memberRole = (m: MemberSummary, stacked: boolean) =>
    isOwner ? (
      <span
        className={cx(
          'text-vp-text-secondary',
          stacked ? 'flex items-center gap-ds-xs' : 'inline-flex items-center gap-ds-xs',
        )}
      >
        <RoleIcon role={m.role} />
        <Select
          label={t('roleSelectAriaLabel', { id: m.public_id ?? m.user_id })}
          hideLabel
          testId={`members-role-${m.user_id}`}
          value={m.role}
          onChange={(v) => void handleRoleChange(m.user_id, v as AssignableAccountRole)}
          disabled={busyKey === `role-${m.user_id}`}
          className={stacked ? 'w-full' : 'w-36'}
        >
          <option value="admin">{t('roleAdmin')}</option>
          <option value="moderator">{t('roleModerator')}</option>
          <option value="member">{t('roleMember')}</option>
        </Select>
      </span>
    ) : (
      <RoleBadge role={m.role} />
    )

  const memberStanding = (m: MemberSummary, stacked: boolean) => (
    <span
      className={cx(stacked ? 'flex items-center gap-ds-xs' : 'inline-flex items-center gap-ds-xs')}
    >
      <MemberStandingBadge state={m.standing.state} />
      <Select
        label={t('standingSelectAriaLabel', { id: m.public_id ?? m.user_id })}
        hideLabel
        testId={`members-standing-${m.user_id}`}
        value={m.standing.state}
        onChange={(v) => void handleStandingChange(m.user_id, v as MemberStandingState)}
        disabled={busyKey === `standing-${m.user_id}`}
        className={stacked ? 'w-full' : 'w-32'}
      >
        <option value="normal">{t('standingNormal')}</option>
        <option value="flagged">{t('standingFlagged')}</option>
        <option value="warned">{t('standingWarned')}</option>
        <option value="suspended">{t('standingSuspended')}</option>
      </Select>
    </span>
  )

  const memberRemove = (m: MemberSummary, stacked: boolean) => (
    <Button
      variant="destructive"
      testId={`members-remove-${m.user_id}`}
      onClick={() => setRemoveTarget(m.user_id)}
      disabled={busyKey !== null}
      aria-label={t('removeAriaLabel', { id: m.public_id ?? m.user_id })}
      className={stacked ? 'w-full gap-ds-xs' : 'gap-ds-xs'}
    >
      <UserMinus size={14} aria-hidden="true" />
      {t('remove')}
    </Button>
  )

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      >
        {rowError !== null && (
          <InlineNotice tone="error" testId="members-row-error">
            {rowError}
          </InlineNotice>
        )}
      </PageHeader>

      <div className="flex flex-col gap-ds-lg">
        {/* ── Members ───────────────────────────────────────────────────── */}
        <Section
          testId="members-section"
          title={t('membersHeading', { count: members.length })}
          icon={<Users size={16} />}
          emphasis="ruled"
          flush
        >
          {isPhone ? (
            <ul
              aria-label={t('membersAriaLabel')}
              data-testid="members-list"
              className="m-0 flex list-none flex-col gap-ds-sm p-ds-md"
            >
              {members.map((m) => (
                <li
                  key={m.user_id}
                  data-testid={`members-row-${m.user_id}`}
                  className="flex flex-col gap-ds-sm rounded-vp-md border border-vp-border-subtle bg-vp-surface-frost p-ds-md"
                >
                  {memberName(m)}
                  <div className="flex flex-col gap-ds-2xs">
                    <span className="text-vp-xs text-vp-text-muted">{t('roleColumn')}</span>
                    {memberRole(m, true)}
                  </div>
                  <div className="flex flex-col gap-ds-2xs">
                    <span className="text-vp-xs text-vp-text-muted">{t('standingColumn')}</span>
                    {memberStanding(m, true)}
                  </div>
                  {isOwner && memberRemove(m, true)}
                </li>
              ))}
            </ul>
          ) : (
            <Table caption={t('membersAriaLabel')} testId="members-table">
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('membersAriaLabel')}</TableHeaderCell>
                  <TableHeaderCell>{t('roleColumn')}</TableHeaderCell>
                  <TableHeaderCell>{t('standingColumn')}</TableHeaderCell>
                  {isOwner && (
                    <TableHeaderCell numeric>
                      <span className="sr-only">{t('remove')}</span>
                    </TableHeaderCell>
                  )}
                </TableRow>
              </TableHead>
              <TableBody>
                {members.map((m) => (
                  <TableRow key={m.user_id} testId={`members-row-${m.user_id}`}>
                    <TableCell>{memberName(m)}</TableCell>
                    <TableCell>{memberRole(m, false)}</TableCell>
                    <TableCell>{memberStanding(m, false)}</TableCell>
                    {isOwner && <TableCell numeric>{memberRemove(m, false)}</TableCell>}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Section>

        {/* ── Password reset (owner + admin) ───────────────────────────────── */}
        <Section
          testId="members-password-reset-section"
          title={t('passwordResetHeading')}
          description={t('passwordResetBody')}
          icon={<KeyRound size={16} />}
        >
          <form
            onSubmit={handlePasswordReset}
            noValidate
            className="flex flex-col sm:flex-row sm:items-end gap-ds-sm"
          >
            <div className="flex-1 sm:max-w-ds-field">
              <TextField
                className="max-w-ds-field"
                label={t('passwordResetEmailField')}
                name="password_reset_email"
                id="password-reset-email"
                type="email"
                value={resetEmail}
                onChange={(e) => setResetEmail(e.target.value)}
                placeholder={t('emailPlaceholder')}
                disabled={resetSending}
                autoComplete="off"
                testId="members-password-reset-email"
              />
            </div>
            <div>
              <Button
                type="submit"
                variant="secondary"
                testId="members-password-reset-submit"
                disabled={resetSending || resetEmail.trim() === ''}
                loading={resetSending}
                className="gap-ds-xs"
              >
                {!resetSending && <KeyRound size={16} aria-hidden="true" />}
                {resetSending ? t('passwordResetSending') : t('passwordResetSubmit')}
              </Button>
            </div>
          </form>

          {(resetError !== null || resetSuccess) && (
            <div className="mt-ds-md">
              {resetError !== null && (
                <InlineNotice tone="error" testId="members-password-reset-error">
                  {resetError}
                </InlineNotice>
              )}
              {resetSuccess && (
                <InlineNotice tone="success" testId="members-password-reset-success">
                  {t('passwordResetSuccess')}
                </InlineNotice>
              )}
            </div>
          )}
        </Section>

        {/* ── Invite form (owner-only) ─────────────────────────────────────── */}
        {isOwner && (
          <Section
            testId="members-invite-section"
            title={t('inviteHeading')}
            description={t('inviteBody')}
            icon={<UserPlus size={16} />}
          >
            <form
              onSubmit={handleInvite}
              noValidate
              className="flex flex-col sm:flex-row sm:items-end gap-ds-sm"
            >
              <div className="flex-1 sm:max-w-ds-field">
                <TextField
                  className="max-w-ds-field"
                  label={t('emailField')}
                  name="invite_email"
                  id="invite-email"
                  type="email"
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                  placeholder={t('emailPlaceholder')}
                  error={inviteFieldError}
                  disabled={inviteSending}
                  autoComplete="off"
                  testId="members-invite-email"
                />
              </div>
              <div className="sm:w-44">
                <Select
                  className="max-w-ds-field"
                  label={t('inviteRoleField')}
                  value={inviteRole}
                  onChange={(v) => setInviteRole(v as AssignableAccountRole)}
                  disabled={inviteSending}
                  testId="members-invite-role"
                >
                  <option value="member">{t('roleMember')}</option>
                  <option value="moderator">{t('roleModerator')}</option>
                  <option value="admin">{t('roleAdmin')}</option>
                </Select>
              </div>
              <div>
                <Button
                  type="submit"
                  variant="primary"
                  testId="members-invite-submit"
                  disabled={inviteSending || inviteEmail.trim() === ''}
                  loading={inviteSending}
                  className="gap-ds-xs"
                >
                  {!inviteSending && <UserPlus size={16} aria-hidden="true" />}
                  {inviteSending ? t('inviteSending') : t('inviteSubmit')}
                </Button>
              </div>
            </form>

            <p className="mt-ds-md text-vp-sm text-vp-text-muted">
              {inviteRole === 'member' && t('inviteRoleMemberHint')}
              {inviteRole === 'moderator' && t('inviteRoleModeratorHint')}
              {inviteRole === 'admin' && t('inviteRoleAdminHint')}
            </p>

            {(inviteGeneralError !== null || inviteSuccess) && (
              <div className="mt-ds-md">
                {inviteGeneralError !== null && (
                  <InlineNotice tone="error" testId="members-invite-error">
                    {inviteGeneralError}
                    {inviteLimitReached && (
                      <>
                        {' '}
                        <PlanUpgradeLink label={tCommon('planUpgradeLinkLabel')} />
                      </>
                    )}
                  </InlineNotice>
                )}
                {inviteSuccess && (
                  <InlineNotice tone="success" testId="members-invite-success">
                    {t('inviteSuccess')}
                  </InlineNotice>
                )}
              </div>
            )}
          </Section>
        )}

        {/* ── Pending invites (owner-only revoke) ──────────────────────────── */}
        {invites.length > 0 && (
          <Section
            testId="members-pending-invites-section"
            title={t('pendingInvitesHeading', { count: invites.length })}
            icon={<Clock size={16} />}
            flush
          >
            <Table caption={t('pendingInvitesAriaLabel')} testId="members-invites-table">
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('pendingInvitesAriaLabel')}</TableHeaderCell>
                  <TableHeaderCell>
                    <span className="sr-only">{t('invitedUserExpiry', { date: '' })}</span>
                  </TableHeaderCell>
                  {isOwner && (
                    <TableHeaderCell numeric>
                      <span className="sr-only">{t('revoke')}</span>
                    </TableHeaderCell>
                  )}
                </TableRow>
              </TableHead>
              <TableBody>
                {invites.map((inv) => (
                  <TableRow key={inv.id} testId={`members-invite-row-${inv.id}`}>
                    <TableCell>
                      <div className="flex items-center gap-ds-xs">
                        <span className="font-medium text-vp-ink">
                          {t('userLabel', { id: inv.user_id })}
                        </span>
                        <RoleBadge role={inv.role} />
                      </div>
                    </TableCell>
                    <TableCell>
                      <span className="text-vp-sm text-vp-text-secondary">
                        {t('invitedUserExpiry', { date: formatDate(inv.expires_at, language) })}
                      </span>
                    </TableCell>
                    {isOwner && (
                      <TableCell numeric>
                        <Button
                          variant="destructive"
                          testId={`members-revoke-${inv.id}`}
                          onClick={() => void handleRevoke(inv.id)}
                          disabled={busyKey === `revoke-${inv.id}`}
                          aria-label={t('revokeInviteAriaLabel', { id: inv.user_id })}
                        >
                          {t('revoke')}
                        </Button>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Section>
        )}
      </div>

      <ConfirmDialog
        testId="members-remove-dialog"
        open={removeTarget !== null}
        title={removeTarget !== null ? t('removeAriaLabel', { id: removeTarget }) : ''}
        description={t('confirmRemove')}
        confirmLabel={t('remove')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        busy={removeTarget !== null && busyKey === `remove-${removeTarget}`}
        onConfirm={() => void handleRemove()}
        onCancel={() => setRemoveTarget(null)}
      />
    </>,
  )
}
