/**
 * AppealsAdminPage — /admin/appeals
 *
 * Account-scoped moderation-appeal review inbox (board-operator-compliance
 * issue 09) — the counterpart, at moderator/admin scope, of the platform
 * operator's global abuse-report inbox (OperatorPage's /operator/reports
 * section): same list + resolve-action shape, but scoped to this account's
 * own content_moderation_actions rows instead of platform-wide anonymous
 * reports.
 *
 * Auth gate:
 *   - Anon              → redirect to /login?r=…
 *   - Not owner/admin/moderator → "no access" (AuthZMiddleware
 *     accountModerate() on the backend already gates GET
 *     {accountPrefix}/moderation/appeals the same way; this is UX only).
 *
 * A pending appeal can be resolved 'upheld' (removal stands) or
 * 'overturned' (removal was a mistake) — both just call
 * resolveModerationAppeal() and reload the list; already-resolved appeals
 * stay visible (read-only) so moderators keep an audit trail in view.
 */

import {
  Button,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  StatusChip,
  type StatusTone,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
} from '@votepit/ui/ds'
import { Gavel } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath } from '../lib/accountContext'
import type { ApiError, ModerationAppealStatus, ModerationAppealSummary, User } from '../lib/api'
import { bootstrap, listModerationAppeals, logout, resolveModerationAppeal } from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

const STATUS_TONE: Record<ModerationAppealStatus, StatusTone> = {
  pending: 'progress',
  upheld: 'neutral',
  overturned: 'success',
}

/** Reuses the `common.commentModeration.*` reason labels — same 7-value
 * enum the moderator removal form (ContentModerationModal) already
 * localizes, so an appeal row shows the same wording as the removal
 * reason the moderator originally picked. */
const REASON_LABEL_KEYS: Record<string, string> = {
  community_rule: 'commentModeration.reasonCommunityRule',
  illegal_copyright: 'commentModeration.illegalCopyright',
  illegal_privacy: 'commentModeration.illegalPrivacy',
  illegal_threat: 'commentModeration.illegalThreat',
  illegal_fraud: 'commentModeration.illegalFraud',
  illegal_other: 'commentModeration.illegalOther',
  other: 'commentModeration.reasonOther',
}

export default function AppealsAdminPage() {
  const navigate = useNavigate()
  const t = useT('appealsAdminPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const STATUS_LABEL: Record<ModerationAppealStatus, string> = {
    pending: t('statusPending'),
    upheld: t('statusUpheld'),
    overturned: t('statusOverturned'),
  }

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [appeals, setAppeals] = useState<ModerationAppealSummary[]>([])
  const [busyId, setBusyId] = useState<number | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)

  const reload = async () => {
    const data = await listModerationAppeals()
    setAppeals(data.appeals)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/appeals'))}`, {
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
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/appeals'))}`, {
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

  const handleResolve = async (id: number, status: 'upheld' | 'overturned') => {
    if (busyId !== null) return
    setBusyId(id)
    setRowError(null)
    try {
      await resolveModerationAppeal(id, status)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRowError(apiErr?.payload?.message ?? t('resolveFailed'))
    } finally {
      setBusyId(null)
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
          <Button testId="appeals-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const pendingCount = appeals.filter((a) => a.status === 'pending').length

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      >
        {rowError !== null && (
          <InlineNotice tone="error" testId="appeals-row-error">
            {rowError}
          </InlineNotice>
        )}
      </PageHeader>

      <Section
        testId="appeals-section"
        title={t('appealsHeading', { count: pendingCount })}
        icon={<Gavel size={16} />}
        emphasis="ruled"
        flush
      >
        {appeals.length === 0 ? (
          <p className="px-ds-md py-ds-lg text-vp-sm text-vp-text-muted sm:px-ds-lg">
            {t('emptyState')}
          </p>
        ) : (
          <Table caption={t('appealsHeading', { count: pendingCount })} testId="appeals-table">
            <TableHead>
              <TableRow>
                <TableHeaderCell>{t('columnContent')}</TableHeaderCell>
                <TableHeaderCell>{t('columnReason')}</TableHeaderCell>
                <TableHeaderCell>{t('columnMessage')}</TableHeaderCell>
                <TableHeaderCell>{t('columnStatus')}</TableHeaderCell>
                <TableHeaderCell>{t('columnSubmitted')}</TableHeaderCell>
                <TableHeaderCell numeric>
                  <span className="sr-only">{t('columnActions')}</span>
                </TableHeaderCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {appeals.map((a) => (
                <TableRow key={a.id} testId={`appeals-row-${a.id}`}>
                  <TableCell>
                    <span className="line-clamp-2 max-w-xs text-vp-text-secondary">
                      {a.content_snapshot}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="text-vp-text-secondary">
                      {tCommon(REASON_LABEL_KEYS[a.reason_code] ?? 'commentModeration.reasonOther')}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className="line-clamp-2 max-w-xs text-vp-text-secondary">
                      {a.message}
                    </span>
                  </TableCell>
                  <TableCell>
                    <StatusChip tone={STATUS_TONE[a.status]} testId={`appeals-status-${a.id}`}>
                      {STATUS_LABEL[a.status]}
                    </StatusChip>
                  </TableCell>
                  <TableCell>
                    <span className="text-vp-text-muted">
                      {formatDateTime(a.created_at, language)}
                    </span>
                  </TableCell>
                  <TableCell numeric>
                    {a.status === 'pending' ? (
                      <span className="inline-flex gap-2">
                        <Button
                          variant="secondary"
                          testId={`appeals-overturn-${a.id}`}
                          onClick={() => void handleResolve(a.id, 'overturned')}
                          disabled={busyId !== null}
                        >
                          {t('overturn')}
                        </Button>
                        <Button
                          variant="destructive"
                          testId={`appeals-uphold-${a.id}`}
                          onClick={() => void handleResolve(a.id, 'upheld')}
                          disabled={busyId !== null}
                        >
                          {t('uphold')}
                        </Button>
                      </span>
                    ) : (
                      <span className="text-vp-text-muted">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Section>
    </>,
  )
}
