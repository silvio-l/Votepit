/**
 * SupportTicketPage — /admin/support/:id
 *
 * One ticket's full thread on its own page rather than an inline accordion
 * row in SupportPage (same reasoning as OperatorSupportTicketPage: became
 * unreadable once a conversation grew past a couple of messages).
 *
 * Auth gate: same shape as SupportPage — anon redirects to /login, a
 * non-member sees "no access", a ticket that doesn't belong to this account
 * (or doesn't exist) shows "not found".
 */

import {
  Badge,
  type BadgeTone,
  Breadcrumbs,
  Button,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  TextArea,
} from '@votepit/ui/ds'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { useSupportMessageTranslations } from '../hooks/useIdeaTranslation'
import { accountPath } from '../lib/accountContext'
import type { ApiError, SupportMessage, SupportRequestStatus, User } from '../lib/api'
import {
  bootstrap,
  getMySupportThread,
  logout,
  replyMySupportRequest,
  translateMySupportThread,
} from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
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
  | { phase: 'ready'; subject: string; status: SupportRequestStatus; messages: SupportMessage[] }

const statusTone: Record<SupportRequestStatus, BadgeTone> = {
  open: 'warning',
  answered: 'success',
  closed: 'neutral',
}

export default function SupportTicketPage() {
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const requestId = Number(id)
  const t = useT('supportPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [translationAvailable, setTranslationAvailable] = useState(false)

  const [threadState, setThreadState] = useState<ThreadState>({ phase: 'loading' })
  const [replyDraft, setReplyDraft] = useState('')
  const [replyBusy, setReplyBusy] = useState(false)
  const [replyError, setReplyError] = useState<string | null>(null)
  const [messagesShowingOriginal, setMessagesShowingOriginal] = useState<Set<number>>(new Set())

  // Not board-bound — only bootstrap()'s translation_available flag gates
  // this. Called unconditionally (before the loading/error early returns
  // below) — Rules of Hooks.
  const messageTranslations = useSupportMessageTranslations(
    requestId,
    threadState.phase === 'ready' ? threadState.messages.map((m) => m.id) : [],
    language,
    translationAvailable,
    translateMySupportThread,
  )

  function toggleMessageOriginal(id: number) {
    setMessagesShowingOriginal((prev) => {
      const updated = new Set(prev)
      if (updated.has(id)) updated.delete(id)
      else updated.add(id)
      return updated
    })
  }

  function messageTranslationView(m: SupportMessage): {
    displayBody: string
    isTranslated: boolean
    failed: boolean
  } {
    const result = messageTranslations.results.get(m.id)
    if (result === undefined) {
      return { displayBody: m.body, isTranslated: false, failed: false }
    }
    if (!result.ok) {
      return { displayBody: m.body, isTranslated: false, failed: true }
    }
    const isTranslated = result.detected_source_locale !== language
    const showingOriginal = messagesShowingOriginal.has(m.id)
    return {
      displayBody: isTranslated && !showingOriginal ? result.translated_body : m.body,
      isTranslated,
      failed: false,
    }
  }

  const loadThread = async () => {
    setThreadState({ phase: 'loading' })
    try {
      const thread = await getMySupportThread(requestId)
      setThreadState({
        phase: 'ready',
        subject: thread.request.subject,
        status: thread.request.status,
        messages: thread.messages,
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
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/support/${id ?? ''}`))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)
        setTranslationAvailable(boot.translation_available ?? false)
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
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/support/${id ?? ''}`))}`, {
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
      await replyMySupportRequest(requestId, body)
      setReplyDraft('')
      await loadThread()
    } catch (err) {
      const apiErr = err as ApiError
      setReplyError(apiErr?.payload?.message ?? t('replyFailed'))
    } finally {
      setReplyBusy(false)
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
    return frame(<LoadingState label={t('loading')} rows={6} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="support-ticket-access-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const backCrumb = (
    <Breadcrumbs
      testId="support-ticket-breadcrumbs"
      ariaLabel={tCommon('breadcrumb.ariaLabel')}
      items={[
        { label: t('title'), href: accountPath('/admin/support') },
        { label: threadState.phase === 'ready' ? threadState.subject : t('title') },
      ]}
    />
  )

  if (threadState.phase === 'not_found') {
    return frame(
      <>
        <PageHeader
          eyebrow={tCommon('header.scopeAdmin')}
          title={t('ticketNotFoundTitle')}
          back={backCrumb}
        />
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
        <PageHeader eyebrow={tCommon('header.scopeAdmin')} title={t('loading')} back={backCrumb} />
        <LoadingState label={t('threadLoading')} rows={4} />
      </>,
    )
  }

  if (threadState.phase === 'error') {
    return frame(
      <>
        <PageHeader eyebrow={tCommon('header.scopeAdmin')} title={t('title')} back={backCrumb} />
        <ErrorState title={tCommon('state.errorTitle')} description={threadState.message} />
      </>,
    )
  }

  const { subject, status, messages } = threadState

  return frame(
    <>
      <PageHeader eyebrow={tCommon('header.scopeAdmin')} title={subject} back={backCrumb}>
        <Badge tone={statusTone[status]} dot testId="support-ticket-status">
          {t(`status.${status}`)}
        </Badge>
      </PageHeader>

      <Section testId="support-thread-section" title={t('threadHeading')} flush>
        <ul className="flex flex-col gap-3 p-ds-md sm:p-ds-lg list-none m-0">
          {messages.map((m) => {
            const translation = messageTranslationView(m)
            return (
              <li
                key={m.id}
                className="rounded-vp-md border border-vp-border-subtle bg-vp-surface-frost px-3 py-2.5"
              >
                <p className="vp-eyebrow mb-1">
                  {m.author_type === 'operator' ? t('fromSupport') : t('fromYou')}
                  {' · '}
                  {formatDateTime(m.created_at, language)}
                </p>
                <p className="vp-prose text-vp-sm text-vp-title whitespace-pre-wrap">
                  {translation.displayBody}
                </p>
                {translation.isTranslated && (
                  <p className="mt-1 flex items-center gap-1 text-vp-xs text-vp-text-muted">
                    <span>{t('machineTranslatedLabel')}</span>
                    <span aria-hidden="true">·</span>
                    <button
                      type="button"
                      data-testid={`support-ticket-translation-toggle-${m.id}`}
                      className="underline hover:no-underline"
                      onClick={() => toggleMessageOriginal(m.id)}
                    >
                      {messagesShowingOriginal.has(m.id)
                        ? t('showTranslationLink')
                        : t('showOriginalLink')}
                    </button>
                  </p>
                )}
                {translation.failed && (
                  <p className="mt-1 text-vp-xs text-vp-text-muted">
                    {t('translationUnavailableHint')}
                  </p>
                )}
              </li>
            )
          })}
        </ul>

        <form
          onSubmit={(e) => {
            e.preventDefault()
            void handleReply()
          }}
          className="flex flex-col gap-2 border-t border-vp-border-subtle px-ds-md sm:px-ds-lg py-ds-md"
        >
          <TextArea
            testId="support-reply-body"
            label={t('replyLabel')}
            value={replyDraft}
            onChange={(e) => setReplyDraft(e.target.value)}
            placeholder={t('replyPlaceholder')}
            disabled={replyBusy}
            rows={4}
          />
          <div className="flex items-center gap-3">
            <Button
              testId="support-reply-submit"
              type="submit"
              variant="primary"
              disabled={replyBusy || replyDraft.trim() === ''}
              loading={replyBusy}
            >
              {replyBusy ? t('replySubmitting') : t('replySubmit')}
            </Button>
            {replyError !== null && (
              <InlineNotice tone="error" testId="support-reply-error" className="flex-1 min-w-48">
                {replyError}
              </InlineNotice>
            )}
          </div>
        </form>
      </Section>
    </>,
  )
}
