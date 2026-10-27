/**
 * ModerationQueuePage — /admin/boards/:boardSlug/queue
 *
 * Pre-moderation approval queue (broaden-use-cases issue 05): lists the
 * board's ideas waiting for approval; moderators approve (publish) or reject
 * them, one by one or as a bulk selection.
 *
 * Auth gate (same shape as AppealsAdminPage):
 *   - Anon                      → redirect to /login?r=…
 *   - Not owner/admin/moderator → "no access" (AuthZMiddleware
 *     accountModerate() on the backend gates both queue routes; UX only).
 *
 * Approve → approveModerationQueue() (one request for the whole selection).
 * Reject  → the existing reason-coded removal moderateIdea(), one request
 * per idea with the reason picked once in ContentModerationModal — the same
 * reason codes, author notice and appeal path as any other idea removal.
 *
 * Guest ideas (ADR 0006) carry a "Guest" label; "reject all guest ideas"
 * clears a guest spam wave in one confirmed step (rejectAllGuestIdeas) —
 * members' ideas are never touched by it.
 *
 * Trusted authors (broaden-use-cases issue 11): "Approve and trust author"
 * (approveAndTrustAuthor) publishes one idea and lets its author's next
 * ideas skip the queue in every board of this account. Offered only for
 * rows the server marks `author_type: 'member'` — never for guest ideas or
 * payloads without the marker (the server refuses guests with 422 anyway).
 * The list with "Revoke" lives on TrustedAuthorsPage, linked from here.
 */

import {
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
} from '@votepit/ui/ds'
import { Hourglass } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { ContentModerationModal } from '../components/ContentModerationModal'
import { accountPath } from '../lib/accountContext'
import type { ApiError, ModerationQueueData, ModerationReasonCode, User } from '../lib/api'
import {
  approveAndTrustAuthor,
  approveModerationQueue,
  bootstrap,
  getModerationQueue,
  logout,
  moderateIdea,
  rejectAllGuestIdeas,
} from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'not_found' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function ModerationQueuePage() {
  const navigate = useNavigate()
  const { boardSlug = '' } = useParams<{ boardSlug: string }>()
  const t = useT('moderationQueuePage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [queue, setQueue] = useState<ModerationQueueData | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  /** Ideas the open reject dialog applies to (null = dialog closed). */
  const [rejectIds, setRejectIds] = useState<number[] | null>(null)
  const [guestRejectOpen, setGuestRejectOpen] = useState(false)

  const selfPath = accountPath(`/admin/boards/${boardSlug}/queue`)

  const reload = async () => {
    const data = await getModerationQueue(boardSlug)
    setQueue(data)
    setSelected((prev) => prev.filter((id) => data.ideas.some((i) => i.id === id)))
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline); only navigate/boardSlug matter.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(selfPath)}`, { replace: true })
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
          navigate(`/login?r=${encodeURIComponent(selfPath)}`, { replace: true })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 403) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }
        if (apiErr.name === 'ApiError' && apiErr.status === 404) {
          setPageState({ phase: 'not_found' })
          return
        }
        const msg = apiErr?.payload?.message ?? (err as Error)?.message ?? t('loadError')
        setPageState({ phase: 'error', message: msg })
      }
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate, boardSlug])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const toggle = (id: number, checked: boolean) => {
    setSelected((prev) => (checked ? [...prev, id] : prev.filter((x) => x !== id)))
  }

  const handleApprove = async (ids: number[]) => {
    if (busy || ids.length === 0) return
    setBusy(true)
    setNotice(null)
    setActionError(null)
    try {
      const result = await approveModerationQueue(boardSlug, ids)
      setNotice(t('approvedNotice', { count: result.approved.length }))
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setActionError(apiErr?.payload?.message ?? t('approveFailed'))
    } finally {
      setBusy(false)
    }
  }

  const handleApproveAndTrust = async (id: number) => {
    if (busy) return
    setBusy(true)
    setNotice(null)
    setActionError(null)
    try {
      await approveAndTrustAuthor(boardSlug, id)
      setNotice(t('approvedAndTrustedNotice'))
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setActionError(apiErr?.payload?.message ?? t('approveAndTrustFailed'))
    } finally {
      setBusy(false)
    }
  }

  const handleReject = async (reasonCode: ModerationReasonCode, reasonNote: string) => {
    const ids = rejectIds ?? []
    setBusy(true)
    setNotice(null)
    setActionError(null)
    let rejected = 0
    try {
      for (const id of ids) {
        await moderateIdea(boardSlug, id, reasonCode, reasonNote)
        rejected++
      }
      setRejectIds(null)
      setNotice(t('rejectedNotice', { count: rejected }))
    } finally {
      // Partial failures still reload so already-rejected rows disappear;
      // ContentModerationModal shows the error inline (the promise rejects).
      await reload().catch(() => undefined)
      setBusy(false)
    }
  }

  const handleRejectAllGuest = async () => {
    setBusy(true)
    setNotice(null)
    setActionError(null)
    try {
      const result = await rejectAllGuestIdeas(boardSlug)
      setNotice(t('guestRejectedNotice', { count: result.rejected }))
    } catch (err) {
      const apiErr = err as ApiError
      setActionError(apiErr?.payload?.message ?? t('guestRejectFailed'))
    } finally {
      setGuestRejectOpen(false)
      await reload().catch(() => undefined)
      setBusy(false)
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
          <Button testId="moderation-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'not_found') {
    return frame(<ErrorState title={t('notFoundTitle')} description={t('notFoundBody')} />)
  }

  if (pageState.phase === 'error' || queue === null) {
    return frame(
      <ErrorState
        title={tCommon('state.errorTitle')}
        description={pageState.phase === 'error' ? pageState.message : t('loadError')}
      />,
    )
  }

  const ideas = queue.ideas
  const allSelected = ideas.length > 0 && selected.length === ideas.length
  const guestCount =
    queue.guest_count ?? ideas.filter((idea) => idea.author_type === 'guest').length

  return frame(
    <>
      <PageHeader
        eyebrow={queue.board.name}
        title={t('title')}
        description={t('subtitle')}
        actions={
          <Link
            to={accountPath('/admin/trusted-authors')}
            className="text-vp-sm font-medium text-vp-title underline-offset-2 hover:underline"
          >
            {t('trustedAuthorsLink')}
          </Link>
        }
      >
        {queue.board.premoderation === 'off' && (
          <InlineNotice tone="info" testId="moderation-switched-off">
            {t('switchedOffHint')}
          </InlineNotice>
        )}
        {notice !== null && (
          <InlineNotice tone="success" testId="moderation-notice">
            {notice}
          </InlineNotice>
        )}
        {actionError !== null && (
          <InlineNotice tone="error" testId="moderation-action-error">
            {actionError}
          </InlineNotice>
        )}
      </PageHeader>

      <Section
        testId="moderation-queue-section"
        title={t('heading', { count: queue.count })}
        icon={<Hourglass size={16} />}
        emphasis="ruled"
        flush
      >
        {ideas.length === 0 ? (
          <p className="px-ds-md py-ds-lg text-vp-sm text-vp-text-muted sm:px-ds-lg">
            {t('emptyState')}
          </p>
        ) : (
          <>
            <div className="flex flex-wrap items-center justify-between gap-3 px-ds-md py-3 sm:px-ds-lg border-b border-vp-border-subtle">
              <Checkbox
                id="queue-select-all"
                label={t('selectAll')}
                checked={allSelected}
                onChange={(checked) => setSelected(checked ? ideas.map((i) => i.id) : [])}
                disabled={busy}
                testId="moderation-select-all"
              />
              <span className="inline-flex flex-wrap gap-2">
                <Button
                  testId="moderation-approve-selected"
                  onClick={() => void handleApprove(selected)}
                  disabled={busy || selected.length === 0}
                >
                  {t('approveSelected', { count: selected.length })}
                </Button>
                <Button
                  variant="destructive"
                  testId="moderation-reject-selected"
                  onClick={() => setRejectIds(selected)}
                  disabled={busy || selected.length === 0}
                >
                  {t('rejectSelected', { count: selected.length })}
                </Button>
                {guestCount > 0 && (
                  <Button
                    variant="destructive"
                    testId="moderation-reject-all-guest"
                    onClick={() => setGuestRejectOpen(true)}
                    disabled={busy}
                  >
                    {t('rejectAllGuest', { count: guestCount })}
                  </Button>
                )}
              </span>
            </div>
            <Table caption={t('heading', { count: queue.count })} testId="moderation-queue-table">
              <TableHead>
                <TableRow>
                  <TableHeaderCell>
                    <span className="sr-only">{t('columnSelect')}</span>
                  </TableHeaderCell>
                  <TableHeaderCell>{t('columnIdea')}</TableHeaderCell>
                  <TableHeaderCell>{t('columnSubmitted')}</TableHeaderCell>
                  <TableHeaderCell numeric>
                    <span className="sr-only">{t('columnActions')}</span>
                  </TableHeaderCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {ideas.map((idea) => (
                  <TableRow key={idea.id} testId={`moderation-row-${idea.id}`}>
                    <TableCell>
                      <Checkbox
                        label={t('selectIdea', { title: idea.title })}
                        hideLabel
                        checked={selected.includes(idea.id)}
                        onChange={(checked) => toggle(idea.id, checked)}
                        disabled={busy}
                        testId={`moderation-select-${idea.id}`}
                      />
                    </TableCell>
                    <TableCell>
                      <Link
                        to={accountPath(`/${boardSlug}/idea/${idea.id}`)}
                        className="font-medium text-vp-title hover:underline"
                      >
                        {idea.title}
                      </Link>
                      {idea.author_type === 'guest' && (
                        <Badge
                          tone="neutral"
                          className="ml-2"
                          testId={`moderation-guest-${idea.id}`}
                        >
                          {t('guestLabel')}
                        </Badge>
                      )}
                      <span className="mt-0.5 block line-clamp-2 max-w-md text-vp-text-secondary">
                        {idea.body}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="text-vp-text-muted">
                        {formatDateTime(idea.created_at, language)}
                      </span>
                    </TableCell>
                    <TableCell numeric>
                      <span className="inline-flex flex-wrap justify-end gap-2">
                        <Button
                          variant="secondary"
                          testId={`moderation-approve-${idea.id}`}
                          onClick={() => void handleApprove([idea.id])}
                          disabled={busy}
                        >
                          {t('approve')}
                        </Button>
                        {idea.author_type === 'member' && (
                          <Button
                            variant="secondary"
                            testId={`moderation-approve-trust-${idea.id}`}
                            onClick={() => void handleApproveAndTrust(idea.id)}
                            disabled={busy}
                          >
                            {t('approveAndTrust')}
                          </Button>
                        )}
                        <Button
                          variant="destructive"
                          testId={`moderation-reject-${idea.id}`}
                          onClick={() => setRejectIds([idea.id])}
                          disabled={busy}
                        >
                          {t('reject')}
                        </Button>
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </>
        )}
      </Section>

      <ConfirmDialog
        testId="moderation-guest-reject-dialog"
        open={guestRejectOpen}
        tone="danger"
        title={t('rejectAllGuestTitle', { count: guestCount })}
        description={t('rejectAllGuestBody')}
        confirmLabel={t('rejectAllGuestConfirm')}
        cancelLabel={tCommon('action.cancel')}
        busy={busy}
        onConfirm={() => void handleRejectAllGuest()}
        onCancel={() => setGuestRejectOpen(false)}
      />

      <ContentModerationModal
        open={rejectIds !== null}
        entityType="idea"
        onClose={() => setRejectIds(null)}
        onConfirm={handleReject}
      />
    </>,
  )
}
