/**
 * OperatorPage — /operator
 *
 * Platform super-admin panel (Operator panel). Sits ABOVE the
 * per-account admin tier: usage overview, platform-wide account/board
 * lock-delete actions (regardless of ownership), and the abuse-report inbox.
 *
 * Auth gate:
 *   - Anon         → redirect to /login?r=…
 *   - Not operator → "no access" message (no data rendered) — this
 *     includes installation-wide admins (is_admin) and account owners of
 *     ANY account; only `boot.user.is_operator` grants access.
 *   - Operator     → usage counters + accounts/boards/reports sections
 *
 * Frontend authz nuance: gated per boot.user.is_operator, UX only — the
 * authoritative check stays server-side (AuthZMiddleware::operator()).
 *
 * Unprefixed by any account segment (this is deliberately NOT
 * account-scoped) — a single flat panel, no per-item detail pages, per the
 * roadmap's "minimal operator panel" scope note.
 */

import {
  Badge,
  Button,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  SearchField,
  Section,
  StatCard,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
  TextField,
} from '@votepit/ui/ds'
import {
  Ban,
  Building2,
  Flag,
  KeyRound,
  LayoutGrid,
  Lightbulb,
  Lock,
  LogOut,
  ShieldAlert,
  Trash2,
  Unlock,
  UserPlus,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { OperatorAnnouncementsPanel } from '../components/OperatorAnnouncementsPanel'
import { OperatorFaqPanel } from '../components/OperatorFaqPanel'
import type {
  AbuseReportSummary,
  ApiError,
  OperatorAccountSummary,
  OperatorBoardSummary,
  OperatorUsageData,
  User,
} from '../lib/api'
import {
  bootstrap,
  deleteOperatorAccount,
  deleteOperatorBoard,
  endOperatorUserSessions,
  getOperatorUsage,
  listOperatorAccounts,
  listOperatorBoards,
  listOperatorReports,
  lockOperatorAccount,
  lockOperatorBoard,
  logout,
  requestOperatorPasswordReset,
  reviewOperatorReport,
  setOperatorUserBlocked,
  unlockOperatorAccount,
  unlockOperatorBoard,
} from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function OperatorPage() {
  const navigate = useNavigate()
  const t = useT('operatorPage')
  const tCommon = useT('common')

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [usage, setUsage] = useState<OperatorUsageData | null>(null)
  const [accounts, setAccounts] = useState<OperatorAccountSummary[]>([])
  const [boards, setBoards] = useState<OperatorBoardSummary[]>([])
  const [reports, setReports] = useState<AbuseReportSummary[]>([])

  const [accountSearch, setAccountSearch] = useState('')
  const [boardSearch, setBoardSearch] = useState('')

  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)
  const [deleteTarget, setDeleteTarget] = useState<{
    kind: 'account' | 'board'
    id: number
    name: string
  } | null>(null)

  const [resetEmail, setResetEmail] = useState('')
  const [resetSending, setResetSending] = useState(false)
  const [resetError, setResetError] = useState<string | null>(null)
  const [resetSuccess, setResetSuccess] = useState(false)

  const [sessionControlEmail, setSessionControlEmail] = useState('')
  const [sessionControlBusy, setSessionControlBusy] = useState<'block' | 'unblock' | 'end' | null>(
    null,
  )
  const [sessionControlError, setSessionControlError] = useState<string | null>(null)
  const [sessionControlSuccess, setSessionControlSuccess] = useState<string | null>(null)

  const reload = async () => {
    const [usageData, accountsData, boardsData, reportsData] = await Promise.all([
      getOperatorUsage(),
      listOperatorAccounts(),
      listOperatorBoards(),
      listOperatorReports(),
    ])
    setUsage(usageData)
    setAccounts(accountsData.accounts)
    setBoards(boardsData.boards)
    setReports(reportsData.reports)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent('/operator')}`, { replace: true })
          return
        }

        setUser(boot.user)
        if (!boot.user.is_operator) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }

        setIsAuthenticated(true)

        await reload()
        if (cancelled) return

        setPageState({ phase: 'ready' })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent('/operator')}`, { replace: true })
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

  const withBusy = async (key: string, action: () => Promise<unknown>, errorMsg: string) => {
    if (busyKey !== null) return
    setBusyKey(key)
    setRowError(null)
    try {
      await action()
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRowError(apiErr?.payload?.message ?? errorMsg)
    } finally {
      setBusyKey(null)
    }
  }

  const handleAccountLock = (id: number) =>
    withBusy(`acc-lock-${id}`, () => lockOperatorAccount(id), t('lockFailed'))
  const handleAccountUnlock = (id: number) =>
    withBusy(`acc-unlock-${id}`, () => unlockOperatorAccount(id), t('unlockFailed'))

  const handleBoardLock = (id: number) =>
    withBusy(`board-lock-${id}`, () => lockOperatorBoard(id), t('lockFailed'))
  const handleBoardUnlock = (id: number) =>
    withBusy(`board-unlock-${id}`, () => unlockOperatorBoard(id), t('unlockFailed'))

  const handleDeleteConfirm = async () => {
    if (deleteTarget === null) return
    const { kind, id } = deleteTarget
    setBusyKey(`${kind}-delete-${id}`)
    setRowError(null)
    try {
      if (kind === 'account') {
        await deleteOperatorAccount(id)
      } else {
        await deleteOperatorBoard(id)
      }
      setDeleteTarget(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setDeleteTarget(null)
      setRowError(apiErr?.payload?.message ?? t('deleteFailed'))
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
      await requestOperatorPasswordReset(resetEmail.trim())
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

  const handleSessionControl = async (action: 'block' | 'unblock' | 'end') => {
    const email = sessionControlEmail.trim()
    if (sessionControlBusy !== null || email === '') return

    setSessionControlBusy(action)
    setSessionControlError(null)
    setSessionControlSuccess(null)
    try {
      if (action === 'end') {
        await endOperatorUserSessions(email)
        setSessionControlSuccess(t('sessionControlEndSessionsSuccess'))
      } else {
        await setOperatorUserBlocked(email, action === 'block')
        setSessionControlSuccess(
          action === 'block' ? t('sessionControlBlockSuccess') : t('sessionControlUnblockSuccess'),
        )
      }
      setSessionControlEmail('')
    } catch (err) {
      const apiErr = err as ApiError
      setSessionControlError(
        apiErr?.payload?.key === 'not_found'
          ? t('sessionControlNotFoundError')
          : (apiErr?.payload?.message ?? t('sessionControlGenericError')),
      )
    } finally {
      setSessionControlBusy(null)
    }
  }

  const handleReportReview = (id: number, status: 'reviewed' | 'dismissed') =>
    withBusy(`report-${status}-${id}`, () => reviewOperatorReport(id, status), t('actionFailed'))

  // Client-side filtering: both lists are already fetched in full (no
  // pagination), so a search box just narrows what's already in memory —
  // an installation-wide table without any filter becomes hard to scan
  // once there are more than a handful of accounts/boards.
  const filteredAccounts = useMemo(() => {
    const q = accountSearch.trim().toLowerCase()
    if (q === '') return accounts
    return accounts.filter((a) => a.name.toLowerCase().includes(q) || a.slug.includes(q))
  }, [accounts, accountSearch])

  const filteredBoards = useMemo(() => {
    const q = boardSearch.trim().toLowerCase()
    if (q === '') return boards
    return boards.filter(
      (b) => b.name.toLowerCase().includes(q) || `${b.account_slug}/${b.slug}`.includes(q),
    )
  }, [boards, boardSearch])

  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageState.phase === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
      logoHref="/"
      area="operator"
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={6} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="operator-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeOperator')}
        title={t('title')}
        description={t('subtitle')}
      >
        {rowError !== null && <InlineNotice tone="error">{rowError}</InlineNotice>}
      </PageHeader>

      <div className="flex flex-col gap-6">
        {/* ── Usage overview ────────────────────────────────────────────── */}
        {usage !== null && (
          <section aria-label={t('usageHeading')} className="flex flex-col gap-3">
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 vp-stagger">
              <StatCard
                label={t('usageAccounts')}
                value={usage.accounts_total}
                icon={<Building2 size={16} />}
                className="animate-vp-rise"
              />
              <StatCard
                label={t('usageBoards')}
                value={usage.boards_total}
                icon={<LayoutGrid size={16} />}
                className="animate-vp-rise"
              />
              <StatCard
                label={t('usageIdeas')}
                value={usage.ideas_total}
                icon={<Lightbulb size={16} />}
                className="animate-vp-rise"
              />
              <StatCard
                label={t('usageNewAccounts7d')}
                value={usage.signups_last_7_days}
                icon={<UserPlus size={16} />}
                tone="accent"
                className="animate-vp-rise"
              />
              <StatCard
                label={t('usageOpenReports')}
                value={usage.open_reports}
                icon={<Flag size={16} />}
                tone={usage.open_reports > 0 ? 'danger' : 'default'}
                className="animate-vp-rise"
              />
              <Link to="/operator/support" className="no-underline text-inherit animate-vp-rise">
                <StatCard
                  label={t('usageOpenSupportRequests')}
                  value={usage.open_support_requests}
                  caption={t('usageOpenSupportRequestsCaption')}
                  icon={<ShieldAlert size={16} />}
                  tone={usage.open_support_requests > 0 ? 'warning' : 'default'}
                  className="vp-card--interactive"
                />
              </Link>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="vp-eyebrow">{t('usageAccountsByPlan')}</span>
              <ul
                className="flex flex-wrap items-center gap-1.5 list-none m-0 p-0"
                aria-label={t('usageAccountsByPlan')}
              >
                {Object.entries(usage.accounts_by_plan).map(([plan, count]) => (
                  <li key={plan}>
                    <Badge tone="neutral" size="md" className="gap-1.5">
                      {plan}
                      <span className="font-mono-num text-vp-ink">{count}</span>
                    </Badge>
                  </li>
                ))}
              </ul>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="vp-eyebrow">{t('featuresHeading')}</span>
              <Badge tone={usage.translation_configured ? 'success' : 'neutral'} size="md">
                {usage.translation_configured
                  ? t('translationConfiguredBadge')
                  : t('translationNotConfiguredBadge')}
              </Badge>
            </div>
          </section>
        )}

        {/* ── Password reset (support + operator) ───────────────────────── */}
        <Section
          testId="operator-password-reset-section"
          title={t('passwordResetHeading')}
          description={t('passwordResetBody')}
          icon={<KeyRound size={16} />}
        >
          <form
            onSubmit={handlePasswordReset}
            noValidate
            className="flex flex-col sm:flex-row sm:items-end gap-3"
          >
            <div className="flex-1 sm:max-w-sm">
              <TextField
                testId="operator-password-reset-email"
                label={t('passwordResetEmailField')}
                name="operator_password_reset_email"
                id="operator-password-reset-email"
                type="email"
                value={resetEmail}
                onChange={(e) => setResetEmail(e.target.value)}
                disabled={resetSending}
                autoComplete="off"
              />
            </div>
            <div>
              <Button
                type="submit"
                variant="secondary"
                testId="operator-reset-send"
                disabled={resetSending || resetEmail.trim() === ''}
                loading={resetSending}
                className="gap-1.5"
              >
                {!resetSending && <KeyRound size={16} aria-hidden="true" />}
                {resetSending ? t('passwordResetSending') : t('passwordResetSubmit')}
              </Button>
            </div>
          </form>

          {(resetError !== null || resetSuccess) && (
            <div className="pt-ds-md">
              {resetError !== null && <InlineNotice tone="error">{resetError}</InlineNotice>}
              {resetSuccess && (
                <InlineNotice tone="success">{t('passwordResetSuccess')}</InlineNotice>
              )}
            </div>
          )}
        </Section>

        {/* ── Block user / end sessions (support + operator) ────────────── */}
        <Section
          testId="operator-session-control-section"
          title={t('sessionControlHeading')}
          description={t('sessionControlBody')}
          icon={<Ban size={16} />}
        >
          <form
            onSubmit={(e) => e.preventDefault()}
            noValidate
            className="flex flex-col sm:flex-row sm:items-end gap-3"
          >
            <div className="flex-1 sm:max-w-sm">
              <TextField
                testId="operator-session-control-email"
                label={t('sessionControlEmailField')}
                name="operator_session_control_email"
                id="operator-session-control-email"
                type="email"
                value={sessionControlEmail}
                onChange={(e) => setSessionControlEmail(e.target.value)}
                disabled={sessionControlBusy !== null}
                autoComplete="off"
              />
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="destructive"
                testId="operator-session-block"
                disabled={sessionControlBusy !== null || sessionControlEmail.trim() === ''}
                loading={sessionControlBusy === 'block'}
                onClick={() => void handleSessionControl('block')}
                className="gap-1.5"
              >
                {sessionControlBusy !== 'block' && <Ban size={16} aria-hidden="true" />}
                {sessionControlBusy === 'block'
                  ? t('sessionControlSending')
                  : t('sessionControlBlock')}
              </Button>
              <Button
                type="button"
                variant="secondary"
                testId="operator-session-unblock"
                disabled={sessionControlBusy !== null || sessionControlEmail.trim() === ''}
                loading={sessionControlBusy === 'unblock'}
                onClick={() => void handleSessionControl('unblock')}
                className="gap-1.5"
              >
                {sessionControlBusy !== 'unblock' && <Unlock size={16} aria-hidden="true" />}
                {sessionControlBusy === 'unblock'
                  ? t('sessionControlSending')
                  : t('sessionControlUnblock')}
              </Button>
              <Button
                type="button"
                variant="secondary"
                testId="operator-session-end"
                disabled={sessionControlBusy !== null || sessionControlEmail.trim() === ''}
                loading={sessionControlBusy === 'end'}
                onClick={() => void handleSessionControl('end')}
                className="gap-1.5"
              >
                {sessionControlBusy !== 'end' && <LogOut size={16} aria-hidden="true" />}
                {sessionControlBusy === 'end'
                  ? t('sessionControlSending')
                  : t('sessionControlEndSessions')}
              </Button>
            </div>
          </form>

          {(sessionControlError !== null || sessionControlSuccess !== null) && (
            <div className="px-ds-md sm:px-ds-lg pb-ds-lg">
              {sessionControlError !== null && (
                <InlineNotice tone="error">{sessionControlError}</InlineNotice>
              )}
              {sessionControlSuccess !== null && (
                <InlineNotice tone="success">{sessionControlSuccess}</InlineNotice>
              )}
            </div>
          )}
        </Section>

        {/* ── Accounts ───────────────────────────────────────────────────── */}
        <Section
          testId="operator-accounts-section"
          title={t('accountsHeading', { count: filteredAccounts.length })}
          icon={<Building2 size={16} />}
          actions={
            accounts.length > 0 && (
              <SearchField
                testId="operator-accounts-search"
                label={t('accountsSearchLabel')}
                hideLabel
                value={accountSearch}
                onChange={(e) => setAccountSearch(e.target.value)}
                placeholder={t('accountsSearchPlaceholder')}
                className="w-56"
              />
            )
          }
          flush
        >
          {accounts.length === 0 ? (
            <EmptyState
              kind="no-ideas"
              variant="plain"
              title={t('noAccountsYet')}
              testId="operator-accounts-empty"
            />
          ) : filteredAccounts.length === 0 ? (
            <EmptyState
              kind="no-results"
              variant="plain"
              title={t('noAccountsFiltered')}
              testId="operator-accounts-filtered-empty"
            />
          ) : (
            <Table testId="operator-accounts-table" caption={t('accountsAriaLabel')}>
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('accountsAriaLabel')}</TableHeaderCell>
                  <TableHeaderCell>{t('slugColumn')}</TableHeaderCell>
                  <TableHeaderCell>{t('planColumn')}</TableHeaderCell>
                  <TableHeaderCell numeric>
                    <span className="sr-only">{t('lock')}</span>
                  </TableHeaderCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filteredAccounts.map((a) => (
                  <TableRow key={a.id}>
                    <TableCell>
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{a.name}</span>
                        {a.locked_at !== null && (
                          <Badge tone="danger" className="gap-1">
                            <Lock size={11} aria-hidden="true" />
                            {t('lockedBadge')}
                          </Badge>
                        )}
                        {a.is_default && (
                          <span title={t('defaultAccountHint')}>
                            <Badge tone="neutral">{t('defaultAccountBadge')}</Badge>
                          </span>
                        )}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="font-mono-num text-vp-sm text-vp-text-secondary">
                        {a.slug}
                      </span>
                    </TableCell>
                    <TableCell>
                      <Badge tone="neutral">{a.plan}</Badge>
                    </TableCell>
                    <TableCell numeric>
                      <span className="inline-flex items-center gap-1">
                        {a.locked_at === null ? (
                          <Button
                            variant="text"
                            testId={`operator-account-lock-${a.id}`}
                            onClick={() => void handleAccountLock(a.id)}
                            disabled={a.is_default || busyKey === `acc-lock-${a.id}`}
                            title={a.is_default ? t('defaultAccountHint') : undefined}
                            aria-label={t('lockAccountAriaLabel', { name: a.name })}
                            className="gap-1.5"
                          >
                            <Lock size={13} aria-hidden="true" />
                            {t('lock')}
                          </Button>
                        ) : (
                          <Button
                            variant="text"
                            testId={`operator-account-unlock-${a.id}`}
                            onClick={() => void handleAccountUnlock(a.id)}
                            disabled={busyKey === `acc-unlock-${a.id}`}
                            aria-label={t('unlockAccountAriaLabel', { name: a.name })}
                            className="gap-1.5"
                          >
                            <Unlock size={13} aria-hidden="true" />
                            {t('unlock')}
                          </Button>
                        )}
                        <Button
                          variant="text-destructive"
                          testId={`operator-account-delete-${a.id}`}
                          onClick={() =>
                            setDeleteTarget({ kind: 'account', id: a.id, name: a.name })
                          }
                          disabled={a.is_default || busyKey !== null}
                          title={a.is_default ? t('defaultAccountHint') : undefined}
                          aria-label={t('deleteAccountAriaLabel', { name: a.name })}
                          className="gap-1.5"
                        >
                          <Trash2 size={13} aria-hidden="true" />
                          {t('delete')}
                        </Button>
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Section>

        {/* ── Boards ─────────────────────────────────────────────────────── */}
        <Section
          testId="operator-boards-section"
          title={t('boardsHeading', { count: filteredBoards.length })}
          icon={<LayoutGrid size={16} />}
          actions={
            boards.length > 0 && (
              <SearchField
                testId="operator-boards-search"
                label={t('boardsSearchLabel')}
                hideLabel
                value={boardSearch}
                onChange={(e) => setBoardSearch(e.target.value)}
                placeholder={t('boardsSearchPlaceholder')}
                className="w-56"
              />
            )
          }
          flush
        >
          {boards.length === 0 ? (
            <EmptyState
              kind="no-ideas"
              variant="plain"
              title={t('noBoardsYet')}
              testId="operator-boards-empty"
            />
          ) : filteredBoards.length === 0 ? (
            <EmptyState
              kind="no-results"
              variant="plain"
              title={t('noBoardsFiltered')}
              testId="operator-boards-filtered-empty"
            />
          ) : (
            <Table testId="operator-boards-table" caption={t('boardsAriaLabel')}>
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('boardsAriaLabel')}</TableHeaderCell>
                  <TableHeaderCell>{t('accountSlugColumn')}</TableHeaderCell>
                  <TableHeaderCell numeric>
                    <span className="sr-only">{t('lock')}</span>
                  </TableHeaderCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {filteredBoards.map((b) => (
                  <TableRow key={b.id}>
                    <TableCell>
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{b.name}</span>
                        {b.locked_at !== null && (
                          <Badge tone="danger" className="gap-1">
                            <Lock size={11} aria-hidden="true" />
                            {t('lockedBadge')}
                          </Badge>
                        )}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="font-mono-num text-vp-sm text-vp-text-secondary">
                        {b.account_slug}/{b.slug}
                      </span>
                    </TableCell>
                    <TableCell numeric>
                      <span className="inline-flex items-center gap-1">
                        {b.locked_at === null ? (
                          <Button
                            variant="text"
                            testId={`operator-board-lock-${b.id}`}
                            onClick={() => void handleBoardLock(b.id)}
                            disabled={busyKey === `board-lock-${b.id}`}
                            aria-label={t('lockBoardAriaLabel', { name: b.name })}
                            className="gap-1.5"
                          >
                            <Lock size={13} aria-hidden="true" />
                            {t('lock')}
                          </Button>
                        ) : (
                          <Button
                            variant="text"
                            testId={`operator-board-unlock-${b.id}`}
                            onClick={() => void handleBoardUnlock(b.id)}
                            disabled={busyKey === `board-unlock-${b.id}`}
                            aria-label={t('unlockBoardAriaLabel', { name: b.name })}
                            className="gap-1.5"
                          >
                            <Unlock size={13} aria-hidden="true" />
                            {t('unlock')}
                          </Button>
                        )}
                        <Button
                          variant="text-destructive"
                          testId={`operator-board-delete-${b.id}`}
                          onClick={() => setDeleteTarget({ kind: 'board', id: b.id, name: b.name })}
                          disabled={busyKey !== null}
                          aria-label={t('deleteBoardAriaLabel', { name: b.name })}
                          className="gap-1.5"
                        >
                          <Trash2 size={13} aria-hidden="true" />
                          {t('delete')}
                        </Button>
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Section>

        {/* ── Abuse reports ──────────────────────────────────────────────── */}
        <Section
          testId="operator-reports-section"
          title={t('reportsHeading', { count: reports.length })}
          description={t('reportsDescription')}
          icon={<Flag size={16} />}
          flush
        >
          {reports.length === 0 ? (
            <EmptyState
              kind="no-ideas"
              variant="plain"
              title={t('noReports')}
              testId="operator-reports-empty"
            />
          ) : (
            <ul className="ds-section-list" aria-label={t('reportsAriaLabel')}>
              {reports.map((r) => (
                <li key={r.id} className="flex flex-col gap-1.5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <span className="flex flex-wrap items-center gap-2 min-w-0">
                      {/^https?:\/\//.test(r.target_url) ? (
                        <a
                          href={r.target_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="font-mono-num text-vp-sm text-vp-accent-strong break-all hover:underline"
                        >
                          {r.target_url}
                        </a>
                      ) : (
                        <span className="font-mono-num text-vp-sm text-vp-ink break-all">
                          {r.target_url}
                        </span>
                      )}
                      <Badge tone={r.status === 'open' ? 'warning' : 'neutral'}>{r.status}</Badge>
                    </span>
                    {r.status === 'open' && (
                      <span className="inline-flex items-center gap-1 shrink-0">
                        <Button
                          variant="secondary"
                          testId={`operator-report-reviewed-${r.id}`}
                          onClick={() => void handleReportReview(r.id, 'reviewed')}
                          disabled={busyKey === `report-reviewed-${r.id}`}
                          aria-label={t('reviewedAriaLabel', { id: r.id })}
                          title={t('reviewedHint')}
                        >
                          {t('reviewed')}
                        </Button>
                        <Button
                          variant="text-destructive"
                          testId={`operator-report-dismissed-${r.id}`}
                          onClick={() => void handleReportReview(r.id, 'dismissed')}
                          disabled={busyKey === `report-dismissed-${r.id}`}
                          aria-label={t('dismissedAriaLabel', { id: r.id })}
                          title={t('dismissedHint')}
                        >
                          {t('dismissed')}
                        </Button>
                      </span>
                    )}
                  </div>
                  <p className="vp-prose text-vp-sm text-vp-text-secondary">{r.reason}</p>
                  {r.reporter_email !== null && (
                    <p className="text-vp-xs text-vp-text-muted">
                      {t('reporterLabel', { email: r.reporter_email })}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Section>

        {/* ── FAQ management ────────────────────────────────────────────── */}
        <OperatorFaqPanel />

        {/* ── Announcements ─────────────────────────────────────────────── */}
        <OperatorAnnouncementsPanel />
      </div>

      <ConfirmDialog
        testId="operator-delete-dialog"
        open={deleteTarget !== null}
        title={
          deleteTarget !== null
            ? t(
                deleteTarget.kind === 'account' ? 'deleteAccountAriaLabel' : 'deleteBoardAriaLabel',
                {
                  name: deleteTarget.name,
                },
              )
            : ''
        }
        description={t(
          deleteTarget?.kind === 'board' ? 'confirmDeleteBoard' : 'confirmDeleteAccount',
        )}
        confirmLabel={t('delete')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        busy={deleteTarget !== null && busyKey === `${deleteTarget.kind}-delete-${deleteTarget.id}`}
        onConfirm={() => void handleDeleteConfirm()}
        onCancel={() => setDeleteTarget(null)}
      />
    </>,
  )
}
