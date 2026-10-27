/**
 * TrustedAuthorsPage — /admin/trusted-authors
 *
 * Trusted authors (broaden-use-cases issue 11): people whose ideas skip the
 * pre-moderation queue in every board of this account. Trust is granted in
 * the approval queue ("Approve and trust author", ModerationQueuePage) and
 * revoked here; a warning or suspension ends it automatically (server side).
 *
 * Auth gate (same shape as AppealsAdminPage):
 *   - Anon                      → redirect to /login?r=…
 *   - Not owner/admin/moderator → "no access" (AuthZMiddleware
 *     accountModerate() on the backend gates both routes; UX only).
 *
 * Authors are shown only by their pseudonymous handle (public_id) and
 * optional username, like MembersPage — never an email.
 */

import {
  Button,
  ConfirmDialog,
  EmptyState,
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
import { ShieldCheck } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath } from '../lib/accountContext'
import type { ApiError, TrustedAuthor, User } from '../lib/api'
import { bootstrap, listTrustedAuthors, logout, revokeTrustedAuthor } from '../lib/api'
import { formatDateTime } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function TrustedAuthorsPage() {
  const navigate = useNavigate()
  const t = useT('trustedAuthorsPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [authors, setAuthors] = useState<TrustedAuthor[]>([])
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  /** Author the open revoke dialog applies to (null = dialog closed). */
  const [revokeTarget, setRevokeTarget] = useState<TrustedAuthor | null>(null)

  const selfPath = accountPath('/admin/trusted-authors')

  const reload = async () => {
    const data = await listTrustedAuthors()
    setAuthors(data.authors)
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline); only navigate matters.
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
        const msg = apiErr?.payload?.message ?? (err as Error)?.message ?? t('loadError')
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

  const label = (author: TrustedAuthor) =>
    author.username
      ? t('userLabelWithUsername', { id: author.public_id ?? '—', username: author.username })
      : t('userLabel', { id: author.public_id ?? '—' })

  const handleRevoke = async () => {
    if (revokeTarget === null) return
    setBusy(true)
    setNotice(null)
    setActionError(null)
    try {
      await revokeTrustedAuthor(revokeTarget.user_id)
      setNotice(t('revokedNotice'))
    } catch (err) {
      const apiErr = err as ApiError
      setActionError(apiErr?.payload?.message ?? t('revokeFailed'))
    } finally {
      setRevokeTarget(null)
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
    return frame(<LoadingState label={t('loading')} rows={4} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="trusted-authors-access-logout" onClick={handleLogout}>
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
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      >
        <p className="text-vp-sm text-vp-text-muted">{t('autoRevokeHint')}</p>
        {notice !== null && (
          <InlineNotice tone="success" testId="trusted-authors-notice">
            {notice}
          </InlineNotice>
        )}
        {actionError !== null && (
          <InlineNotice tone="error" testId="trusted-authors-error">
            {actionError}
          </InlineNotice>
        )}
      </PageHeader>

      <Section
        testId="trusted-authors-section"
        title={t('heading', { count: authors.length })}
        icon={<ShieldCheck size={16} />}
        emphasis="ruled"
        flush
      >
        {authors.length === 0 ? (
          <EmptyState
            kind="no-ideas"
            variant="plain"
            testId="trusted-authors-empty"
            title={t('emptyState')}
          />
        ) : (
          <Table testId="trusted-authors-table" caption={t('heading', { count: authors.length })}>
            <TableHead>
              <TableRow>
                <TableHeaderCell>{t('columnAuthor')}</TableHeaderCell>
                <TableHeaderCell>{t('columnTrustedSince')}</TableHeaderCell>
                <TableHeaderCell numeric>
                  <span className="sr-only">{t('columnActions')}</span>
                </TableHeaderCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {authors.map((author) => (
                <TableRow key={author.user_id} testId={`trusted-author-row-${author.user_id}`}>
                  <TableCell>
                    <span className="font-medium text-vp-ink">{label(author)}</span>
                  </TableCell>
                  <TableCell>
                    <span className="text-vp-text-muted">
                      {formatDateTime(author.trusted_at, language)}
                    </span>
                  </TableCell>
                  <TableCell numeric>
                    <Button
                      testId={`trusted-author-revoke-${author.user_id}`}
                      variant="destructive"
                      onClick={() => setRevokeTarget(author)}
                      disabled={busy}
                      aria-label={t('revokeAriaLabel', { id: author.public_id ?? '—' })}
                    >
                      {t('revoke')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Section>

      <ConfirmDialog
        testId="trusted-authors-revoke-dialog"
        open={revokeTarget !== null}
        tone="danger"
        title={t('revokeTitle')}
        description={t('revokeBody')}
        confirmLabel={t('revokeConfirm')}
        cancelLabel={tCommon('action.cancel')}
        busy={busy}
        onConfirm={() => void handleRevoke()}
        onCancel={() => setRevokeTarget(null)}
      />
    </>,
  )
}
