/**
 * SupportPage — /admin/support
 *
 * Dashboard contact form: any account member (owner or moderator) can send a
 * categorized support request straight to the operator, see their own
 * account's past tickets, and — before ever submitting — get FAQ entries
 * matching the category they picked, so a common question can be answered
 * instantly instead of waiting on a reply (Zendesk/Intercom-style
 * deflection, see migrations/0023_add_support_and_faq.sql).
 *
 * The ticket list is a table, row click navigates to SupportTicketPage for
 * the full thread — no longer an inline accordion (same reasoning as
 * OperatorSupportPage: unreadable once a conversation grows past a couple
 * of messages).
 *
 * Auth gate:
 *   - Anon                  → redirect to /login?r=…
 *   - Not an account member → "no access" message (no data rendered)
 *   - Member (owner OR moderator) → form + own tickets render
 */

import {
  Alert,
  Badge,
  type BadgeTone,
  Button,
  EmptyState,
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
  Textarea,
  TextInput,
} from '@votepit/ui'
import { Send, Ticket } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath } from '../lib/accountContext'
import type { ApiError, FaqEntry, SupportCategory, SupportRequestSummary, User } from '../lib/api'
import { bootstrap, listFaq, listMySupportRequests, logout, submitSupportRequest } from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

const CATEGORIES: SupportCategory[] = [
  'technical',
  'billing',
  'account',
  'feature_request',
  'privacy',
  'other',
]

const statusTone: Record<SupportRequestSummary['status'], BadgeTone> = {
  open: 'warning',
  answered: 'success',
  closed: 'neutral',
}

export default function SupportPage() {
  const navigate = useNavigate()
  const t = useT('supportPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [faqEntries, setFaqEntries] = useState<FaqEntry[]>([])
  const [myRequests, setMyRequests] = useState<SupportRequestSummary[]>([])

  const [category, setCategory] = useState<SupportCategory>('technical')
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')

  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [generalError, setGeneralError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const reload = async () => {
    const [faqData, requestsData] = await Promise.all([listFaq(), listMySupportRequests()])
    setFaqEntries(faqData.entries)
    setMyRequests(requestsData.requests)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/support'))}`, {
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
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/support'))}`, {
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

  const matchingFaqEntries = useMemo(
    () => faqEntries.filter((e) => e.category === category),
    [faqEntries, category],
  )

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || subject.trim() === '' || message.trim() === '') return

    setSubmitting(true)
    setFieldErrors({})
    setGeneralError(null)
    setSuccess(false)

    try {
      await submitSupportRequest({
        category,
        subject: subject.trim(),
        message: message.trim(),
      })
      setSubject('')
      setMessage('')
      setSuccess(true)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr?.payload?.fields !== undefined) {
        setFieldErrors(apiErr.payload.fields)
      } else {
        setGeneralError(apiErr?.payload?.message ?? t('submitFailed'))
      }
    } finally {
      setSubmitting(false)
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
        action={<Button onClick={handleLogout}>{tCommon('header.logout')}</Button>}
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
      />

      <div className="flex flex-col gap-6">
        {/* ── Contact form ──────────────────────────────────────────────── */}
        <Section title={t('formHeading')} icon={<Send size={16} />} emphasis="ruled" flush>
          <form
            onSubmit={handleSubmit}
            noValidate
            className="flex flex-col gap-4 px-4 sm:px-5 py-5"
          >
            <Select
              label={t('categoryLabel')}
              value={category}
              onChange={(v) => setCategory(v as SupportCategory)}
              error={fieldErrors.category}
              disabled={submitting}
              className="max-w-sm"
            >
              {CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {t(`category.${c}`)}
                </option>
              ))}
            </Select>

            {matchingFaqEntries.length > 0 && (
              <Alert tone="info" title={t('faqDeflectionHeading')} className="animate-vp-fade-in">
                <dl className="flex flex-col gap-3">
                  {matchingFaqEntries.map((entry) => (
                    <div key={entry.id}>
                      <dt className="text-vp-sm font-medium text-vp-ink">
                        {language === 'de' ? entry.question_de : entry.question_en}
                      </dt>
                      <dd className="vp-prose text-vp-sm text-vp-text-secondary">
                        {language === 'de' ? entry.answer_de : entry.answer_en}
                      </dd>
                    </div>
                  ))}
                </dl>
              </Alert>
            )}

            <TextInput
              label={t('subjectLabel')}
              name="subject"
              value={subject}
              onChange={setSubject}
              error={fieldErrors.subject}
              disabled={submitting}
              required
            />

            <Textarea
              label={t('messageLabel')}
              name="message"
              value={message}
              onChange={setMessage}
              error={fieldErrors.message}
              disabled={submitting}
              rows={6}
              required
            />

            {generalError !== null && <Alert tone="error">{generalError}</Alert>}
            {success && <Alert tone="success">{t('submitSuccess')}</Alert>}

            <div>
              <Button
                type="submit"
                variant="primary"
                disabled={submitting || subject.trim() === '' || message.trim() === ''}
                loading={submitting}
                aria-busy={submitting}
                className="gap-1.5"
              >
                {!submitting && <Send size={16} aria-hidden="true" />}
                {submitting ? t('submitting') : t('submit')}
              </Button>
            </div>
          </form>
        </Section>

        {/* ── Own tickets ───────────────────────────────────────────────── */}
        <Section
          title={t('ticketsHeading', { count: myRequests.length })}
          icon={<Ticket size={16} />}
          flush
        >
          {myRequests.length === 0 ? (
            <EmptyState size="compact" title={t('noTickets')} />
          ) : (
            <Table caption={t('ticketsHeading', { count: myRequests.length })}>
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('subjectColumn')}</TableHeaderCell>
                  <TableHeaderCell>{t('categoryColumn')}</TableHeaderCell>
                  <TableHeaderCell>{t('statusColumn')}</TableHeaderCell>
                  <TableHeaderCell>{t('updatedColumn')}</TableHeaderCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {myRequests.map((r) => (
                  <TableRow
                    key={r.id}
                    interactive
                    onClick={() => navigate(accountPath(`/admin/support/${r.id}`))}
                  >
                    <TableCell>
                      <span className="font-medium text-vp-ink">{r.subject}</span>
                    </TableCell>
                    <TableCell>
                      <Badge tone="neutral">{t(`category.${r.category}`)}</Badge>
                    </TableCell>
                    <TableCell>
                      <Badge tone={statusTone[r.status]} dot>
                        {t(`status.${r.status}`)}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <span className="font-mono-num text-vp-sm text-vp-text-secondary">
                        {formatDateTime(r.updated_at, language)}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Section>
      </div>
    </>,
  )
}
