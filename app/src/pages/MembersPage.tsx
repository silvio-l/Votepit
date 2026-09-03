/**
 * MembersPage — /admin/members
 *
 * Account-scoped member management (roles & invitations):
 * member table + role change / remove, invite-by-email form, pending-invites
 * table with revoke.
 *
 * Auth gate:
 *   - Anon                  → redirect to /login?r=…
 *   - Not an account member → "no access" message (no data rendered)
 *   - Member (owner OR moderator) → the member/invite lists render
 *
 * `viewer_role` comes back from GET /admin/members itself (not bootstrap) —
 * both owner and moderator may read the list, but only an owner sees the
 * invite form, the revoke buttons, the role selector and the remove button
 * (server-enforced via AuthZMiddleware::accountOwner(); this client gate is
 * UX only, mirrors the AdminPage/BoardsAdminPage pattern).
 */

import {
  Alert,
  Button,
  ConfirmDialog,
  ErrorState,
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
  TextInput,
} from '@votepit/ui'
import { Clock, UserMinus, UserPlus, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { RoleBadge, RoleIcon } from '../components/RoleBadge'
import { accountPath } from '../lib/accountContext'
import type { ApiError, MemberSummary, PendingInvite, User } from '../lib/api'
import {
  bootstrap,
  changeMemberRole,
  inviteMember,
  listMembers,
  logout,
  removeMember,
  revokeInvite,
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
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [isOwner, setIsOwner] = useState(false)

  const [members, setMembers] = useState<MemberSummary[]>([])
  const [invites, setInvites] = useState<PendingInvite[]>([])

  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteFieldError, setInviteFieldError] = useState<string | undefined>(undefined)
  const [inviteGeneralError, setInviteGeneralError] = useState<string | null>(null)
  const [inviteSuccess, setInviteSuccess] = useState(false)
  const [inviteSending, setInviteSending] = useState(false)

  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)
  const [removeTarget, setRemoveTarget] = useState<number | null>(null)

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
    setInviteSuccess(false)

    try {
      await inviteMember(inviteEmail.trim())
      setInviteEmail('')
      setInviteSuccess(true)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      const fieldMsg = apiErr?.payload?.fields?.email
      if (fieldMsg !== undefined) {
        setInviteFieldError(fieldMsg)
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

  const handleRoleChange = async (userId: number, role: 'owner' | 'moderator') => {
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
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      >
        {rowError !== null && <Alert tone="error">{rowError}</Alert>}
      </PageHeader>

      <div className="flex flex-col gap-6">
        {/* ── Members ───────────────────────────────────────────────────── */}
        <Section
          title={t('membersHeading', { count: members.length })}
          icon={<Users size={16} />}
          emphasis="ruled"
          flush
        >
          <Table caption={t('membersAriaLabel')}>
            <TableHead>
              <TableRow>
                <TableHeaderCell>{t('membersAriaLabel')}</TableHeaderCell>
                <TableHeaderCell>{t('roleColumn')}</TableHeaderCell>
                {isOwner && (
                  <TableHeaderCell numeric>
                    <span className="sr-only">{t('remove')}</span>
                  </TableHeaderCell>
                )}
              </TableRow>
            </TableHead>
            <TableBody>
              {members.map((m) => (
                <TableRow key={m.user_id}>
                  <TableCell>
                    <span className="font-medium text-vp-ink">
                      {t('userLabel', { id: m.user_id })}
                    </span>
                  </TableCell>
                  <TableCell>
                    {isOwner ? (
                      <span className="inline-flex items-center gap-1.5 text-vp-text-secondary">
                        <RoleIcon role={m.role} />
                        <Select
                          label={t('roleSelectAriaLabel', { id: m.user_id })}
                          hideLabel
                          size="sm"
                          value={m.role}
                          onChange={(v) =>
                            void handleRoleChange(m.user_id, v as 'owner' | 'moderator')
                          }
                          disabled={busyKey === `role-${m.user_id}`}
                          className="w-36"
                        >
                          <option value="owner">{t('roleOwner')}</option>
                          <option value="moderator">{t('roleModerator')}</option>
                        </Select>
                      </span>
                    ) : (
                      <RoleBadge role={m.role} />
                    )}
                  </TableCell>
                  {isOwner && (
                    <TableCell numeric>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setRemoveTarget(m.user_id)}
                        disabled={busyKey !== null}
                        aria-label={t('removeAriaLabel', { id: m.user_id })}
                        className="text-vp-vote-down-strong gap-1.5"
                      >
                        <UserMinus size={14} aria-hidden="true" />
                        {t('remove')}
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Section>

        {/* ── Invite form (owner-only) ─────────────────────────────────────── */}
        {isOwner && (
          <Section
            title={t('inviteHeading')}
            description={t('inviteBody')}
            icon={<UserPlus size={16} />}
            flush
          >
            <form
              onSubmit={handleInvite}
              noValidate
              className="flex flex-col sm:flex-row sm:items-end gap-3 px-4 sm:px-5 py-5"
            >
              <div className="flex-1 sm:max-w-sm">
                <TextInput
                  label={t('emailField')}
                  name="invite_email"
                  id="invite-email"
                  type="email"
                  value={inviteEmail}
                  onChange={setInviteEmail}
                  placeholder={t('emailPlaceholder')}
                  error={inviteFieldError}
                  disabled={inviteSending}
                  autoComplete="off"
                />
              </div>
              <div>
                <Button
                  type="submit"
                  variant="primary"
                  disabled={inviteSending || inviteEmail.trim() === ''}
                  loading={inviteSending}
                  aria-busy={inviteSending}
                  className="gap-1.5"
                >
                  {!inviteSending && <UserPlus size={16} aria-hidden="true" />}
                  {inviteSending ? t('inviteSending') : t('inviteSubmit')}
                </Button>
              </div>
            </form>

            {(inviteGeneralError !== null || inviteSuccess) && (
              <div className="px-4 sm:px-5 pb-5">
                {inviteGeneralError !== null && <Alert tone="error">{inviteGeneralError}</Alert>}
                {inviteSuccess && <Alert tone="success">{t('inviteSuccess')}</Alert>}
              </div>
            )}
          </Section>
        )}

        {/* ── Pending invites (owner-only revoke) ──────────────────────────── */}
        {invites.length > 0 && (
          <Section
            title={t('pendingInvitesHeading', { count: invites.length })}
            icon={<Clock size={16} />}
            flush
          >
            <Table caption={t('pendingInvitesAriaLabel')}>
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
                  <TableRow key={inv.id}>
                    <TableCell>
                      <span className="font-medium text-vp-ink">
                        {t('userLabel', { id: inv.user_id })}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="text-vp-sm text-vp-text-secondary">
                        {t('invitedUserExpiry', { date: formatDate(inv.expires_at, language) })}
                      </span>
                    </TableCell>
                    {isOwner && (
                      <TableCell numeric>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => void handleRevoke(inv.id)}
                          disabled={busyKey === `revoke-${inv.id}`}
                          aria-label={t('revokeInviteAriaLabel', { id: inv.user_id })}
                          className="text-vp-vote-down-strong"
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
