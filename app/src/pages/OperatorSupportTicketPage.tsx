/**
 * OperatorSupportTicketPage — /operator/support/:id
 *
 * One ticket's full thread on its own page rather than an inline accordion
 * row (which became unreadable once a conversation grew past a couple of
 * messages) — plus the account/requester context an operator needs to go
 * cross-reference logs or other tooling for a technical issue. Email is
 * stored only as an HMAC and never surfaced anywhere — this deliberately
 * stops at account slug/name/plan and the requester's optional
 * username/id, never an email.
 *
 * Auth gate: same shape as OperatorSupportPage/OperatorPage.
 */

import {
  Alert,
  Badge,
  type BadgeTone,
  Breadcrumbs,
  Button,
  ErrorState,
  LoadingState,
  PageHeader,
  Section,
  Select,
  Textarea,
} from '@votepit/ui'
import { Building2, UserRound } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import type {
  ApiError,
  SupportMessage,
  SupportRequestStatus,
  SupportTicketAccountContext,
  SupportTicketRequesterContext,
  User,
} from '../lib/api'
import {
  bootstrap,
  getOperatorSupportThread,
  logout,
  replyOperatorSupportRequest,
  setOperatorSupportRequestStatus,
} from '../lib/api'
import { formatDate, formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

type ThreadState =
  | { phase: 'loading' }
  | { phase: 'not_found' }
  | { phase: 'error'; message: string }
  | {
      phase: 'ready'
      subject: string
      status: SupportRequestStatus
      messages: SupportMessage[]
      account: SupportTicketAccountContext | null
      requester: SupportTicketRequesterContext | null
    }

const STATUSES: SupportRequestStatus[] = ['open', 'answered', 'closed']

const statusTone: Record<SupportRequestStatus, BadgeTone> = {
  open: 'warning',
  answered: 'success',
  closed: 'neutral',
}

export default function OperatorSupportTicketPage() {
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const requestId = Number(id)
  const t = useT('operatorSupportPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [threadState, setThreadState] = useState<ThreadState>({ phase: 'loading' })
  const [statusBusy, setStatusBusy] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [replyDraft, setReplyDraft] = useState('')
  const [replyBusy, setReplyBusy] = useState(false)
  const [replyError, setReplyError] = useState<string | null>(null)

  const loadThread = async () => {
    setThreadState({ phase: 'loading' })
    try {
      const thread = await getOperatorSupportThread(requestId)
      setThreadState({
        phase: 'ready',
        subject: thread.request.subject,
        status: thread.request.status,
        messages: thread.messages,
        account: thread.account ?? null,
        requester: thread.requester ?? null,
      })
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr.name === 'ApiError' && apiErr.status === 404) {
        setThreadState({ phase: 'not_found' })
        return
      }
      const msg =
        (apiErr as ApiError)?.payload?.message ?? (err as Error)?.message ?? t('threadLoadError')
      setThreadState({ phase: 'error', message: msg })
    }
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: init/loadThread/t are stable per render pass; only navigate/id drive a re-run.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(`/operator/support/${id ?? ''}`)}`, {
            replace: true,
          })
          return
        }

        setUser(boot.user)
        if (!boot.user.is_operator && !boot.user.is_support) {
          setIsAuthenticated(true)
          setPageState({ phase: 'access_denied' })
          return
        }

        setIsAuthenticated(true)
        setPageState({ phase: 'ready' })

        if (Number.isFinite(requestId) && requestId > 0) {
          await loadThread()
        } else {
          setThreadState({ phase: 'not_found' })
        }
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(`/operator/support/${id ?? ''}`)}`, {
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
  }, [navigate, id])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleReply = async () => {
    const body = replyDraft.trim()
    if (body === '' || replyBusy) return
    setReplyBusy(true)
    setReplyError(null)
    try {
      await replyOperatorSupportRequest(requestId, body)
      setReplyDraft('')
      await loadThread()
    } catch (err) {
      const apiErr = err as ApiError
      setReplyError(apiErr?.payload?.message ?? t('replyFailed'))
    } finally {
      setReplyBusy(false)
    }
  }

  const handleStatusChange = async (status: SupportRequestStatus) => {
    if (statusBusy) return
    setStatusBusy(true)
    setStatusError(null)
    try {
      await setOperatorSupportRequestStatus(requestId, status)
      await loadThread()
    } catch (err) {
      const apiErr = err as ApiError
      setStatusError(apiErr?.payload?.message ?? t('statusChangeFailed'))
    } finally {
      setStatusBusy(false)
    }
  }

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
        action={<Button onClick={handleLogout}>{tCommon('header.logout')}</Button>}
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const backCrumb = (
    <Breadcrumbs
      ariaLabel={tCommon('breadcrumb.ariaLabel')}
      items={[
        { label: t('title'), href: '/operator/support' },
        { label: threadState.phase === 'ready' ? threadState.subject : t('scopeLabel') },
      ]}
    />
  )

  if (threadState.phase === 'not_found') {
    return frame(
      <>
        <PageHeader eyebrow={t('scopeLabel')} title={t('ticketNotFoundTitle')} back={backCrumb} />
        <ErrorState
          kind="missing"
          title={t('ticketNotFoundTitle')}
          description={t('ticketNotFoundBody')}
        />
      </>,
    )
  }

  if (threadState.phase === 'loading') {
    return frame(
      <>
        <PageHeader eyebrow={t('scopeLabel')} title={t('loading')} back={backCrumb} />
        <LoadingState label={t('threadLoading')} rows={4} />
      </>,
    )
  }

  if (threadState.phase === 'error') {
    return frame(
      <>
        <PageHeader eyebrow={t('scopeLabel')} title={t('scopeLabel')} back={backCrumb} />
        <ErrorState title={tCommon('state.errorTitle')} description={threadState.message} />
      </>,
    )
  }

  const { subject, status, messages, account, requester } = threadState

  return frame(
    <>
      <PageHeader
        eyebrow={t('scopeLabel')}
        title={subject}
        back={backCrumb}
        actions={
          <Select
            label={t('statusChangeLabel')}
            hideLabel
            value={status}
            onChange={(v) => void handleStatusChange(v as SupportRequestStatus)}
            disabled={statusBusy}
            className="w-40"
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {t(`status.${s}`)}
              </option>
            ))}
          </Select>
        }
      >
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={statusTone[status]} dot>
            {t(`status.${status}`)}
          </Badge>
          {statusError !== null && <Alert tone="error">{statusError}</Alert>}
        </div>
      </PageHeader>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <div className="flex-1 min-w-0 flex flex-col gap-6">
          <Section title={t('threadHeading')} flush>
            <ul className="flex flex-col gap-3 p-4 sm:p-5 list-none m-0">
              {messages.map((m) => (
                <li
                  key={m.id}
                  className="rounded-vp-md border border-vp-border-subtle bg-vp-surface-frost px-3 py-2.5"
                >
                  <p className="vp-eyebrow mb-1">
                    {m.author_type === 'operator' ? t('fromOperator') : t('fromCustomer')}
                    {' · '}
                    {formatDateTime(m.created_at, language)}
                  </p>
                  <p className="vp-prose text-vp-sm text-vp-ink whitespace-pre-wrap">{m.body}</p>
                </li>
              ))}
            </ul>

            <form
              onSubmit={(e) => {
                e.preventDefault()
                void handleReply()
              }}
              className="flex flex-col gap-2 border-t border-vp-border-subtle px-4 sm:px-5 py-4"
            >
              <Textarea
                label={t('replyLabel')}
                value={replyDraft}
                onChange={setReplyDraft}
                placeholder={t('replyPlaceholder')}
                disabled={replyBusy}
                rows={4}
              />
              <div className="flex items-center gap-3">
                <Button
                  type="submit"
                  variant="primary"
                  size="sm"
                  disabled={replyBusy || replyDraft.trim() === ''}
                  loading={replyBusy}
                >
                  {replyBusy ? t('replySubmitting') : t('replySubmit')}
                </Button>
                {replyError !== null && (
                  <Alert tone="error" className="flex-1 min-w-48">
                    {replyError}
                  </Alert>
                )}
              </div>
            </form>
          </Section>
        </div>

        <div className="w-full lg:w-80 shrink-0 flex flex-col gap-4">
          <Section title={t('accountContextHeading')} icon={<Building2 size={16} />}>
            {account === null ? (
              <p className="px-4 sm:px-5 py-4 text-vp-sm text-vp-text-muted">—</p>
            ) : (
              <dl className="flex flex-col gap-2 px-4 sm:px-5 py-4 text-vp-sm">
                <div className="flex items-center justify-between gap-2">
                  <dt className="text-vp-text-muted">{t('accountSlugLabel')}</dt>
                  <dd className="font-mono-num text-vp-ink">{account.slug}</dd>
                </div>
                <div className="flex items-center justify-between gap-2">
                  <dt className="text-vp-text-muted">{t('accountPlanLabel')}</dt>
                  <dd>
                    <Badge tone="neutral">{account.plan}</Badge>
                  </dd>
                </div>
                <div className="text-vp-xs text-vp-text-muted">
                  {t('accountCreatedLabel', { date: formatDate(account.created_at, language) })}
                </div>
              </dl>
            )}
          </Section>

          <Section title={t('requesterContextHeading')} icon={<UserRound size={16} />}>
            {requester === null ? (
              <p className="px-4 sm:px-5 py-4 text-vp-sm text-vp-text-muted">—</p>
            ) : (
              <dl className="flex flex-col gap-2 px-4 sm:px-5 py-4 text-vp-sm">
                <div className="flex items-center justify-between gap-2">
                  <dt className="text-vp-text-muted">{t('requesterUsernameLabel')}</dt>
                  <dd className="text-vp-ink">
                    {requester.username ?? (
                      <span className="text-vp-text-muted italic">
                        {t('requesterUsernameUnset')}
                      </span>
                    )}
                  </dd>
                </div>
                <div className="text-vp-xs text-vp-text-muted">
                  {t('requesterCreatedLabel', { date: formatDate(requester.created_at, language) })}
                </div>
                {requester.public_id !== null && (
                  <div className="text-vp-xs text-vp-text-muted font-mono-num">
                    {t('requesterPublicIdLabel', { id: requester.public_id })}
                  </div>
                )}
                <div className="text-vp-xs text-vp-text-muted font-mono-num">
                  {t('requesterIdLabel', { id: requester.id })}
                </div>
              </dl>
            )}
          </Section>
        </div>
      </div>
    </>,
  )
}
