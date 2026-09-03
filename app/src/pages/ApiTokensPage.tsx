/**
 * ApiTokensPage — /admin/boards/:boardSlug/tokens
 *
 * Board-scoped API-token management (Agent API /
 * Votepit MCP): list existing tokens (label, created, last used, revoke) +
 * a create form. A newly created token's plaintext is shown exactly once,
 * directly under the create form — standard practice (mirrors how the
 * backend never stores/returns it again, see ApiTokenAction) — then never
 * again after the page reloads the list.
 *
 * Auth gate: mirrors MembersPage — no client-side role check up front;
 * GET /admin/boards/{slug}/tokens itself enforces accountAdmin (owner OR
 * moderator may manage tokens, see ApiTokenAction doc), so a 401/403 from
 * that call drives the gate.
 */

import {
  Alert,
  Badge,
  Breadcrumbs,
  Button,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  Section,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
  TextInput,
} from '@votepit/ui'
import { Check, Copy, KeyRound, Plus } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath } from '../lib/accountContext'
import type { ApiError, ApiTokenSummary, User } from '../lib/api'
import { bootstrap, createApiToken, listApiTokens, logout, revokeApiToken } from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function ApiTokensPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('apiTokensPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [tokens, setTokens] = useState<ApiTokenSummary[]>([])

  const [label, setLabel] = useState('')
  const [labelError, setLabelError] = useState<string | undefined>(undefined)
  const [createGeneralError, setCreateGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [revealedToken, setRevealedToken] = useState<{
    id: number
    label: string
    token: string
  } | null>(null)

  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)
  const [revokeTarget, setRevokeTarget] = useState<{ id: number; label: string } | null>(null)
  const [copied, setCopied] = useState(false)

  const reload = async (slug: string) => {
    const data = await listApiTokens(slug)
    setTokens(data.tokens)
    return data
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload is stable (defined inline, no external deps worth tracking); only boardSlug/navigate matter.
  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}/tokens`))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)

        await reload(slug)
        if (cancelled) return

        setPageState({ phase: 'ready' })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}/tokens`))}`, {
            replace: true,
          })
          return
        }
        if (apiErr.name === 'ApiError' && (apiErr.status === 403 || apiErr.status === 404)) {
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
  }, [boardSlug, navigate])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || creating || label.trim() === '') return

    setCreating(true)
    setLabelError(undefined)
    setCreateGeneralError(null)

    try {
      const created = await createApiToken(boardSlug, label.trim())
      setRevealedToken({ id: created.id, label: created.label, token: created.token })
      setLabel('')
      await reload(boardSlug)
    } catch (err) {
      const apiErr = err as ApiError
      const fieldMsg = apiErr?.payload?.fields?.label
      if (fieldMsg !== undefined) {
        setLabelError(fieldMsg)
      } else {
        setCreateGeneralError(apiErr?.payload?.message ?? t('createFailed'))
      }
    } finally {
      setCreating(false)
    }
  }

  const handleRevoke = async () => {
    if (!boardSlug || revokeTarget === null) return
    const { id: tokenId } = revokeTarget
    setBusyKey(`revoke-${tokenId}`)
    setRowError(null)
    try {
      await revokeApiToken(boardSlug, tokenId)
      setRevokeTarget(null)
      await reload(boardSlug)
    } catch (err) {
      const apiErr = err as ApiError
      setRevokeTarget(null)
      setRowError(apiErr?.payload?.message ?? t('revokeFailed'))
    } finally {
      setBusyKey(null)
    }
  }

  const handleCopyToken = async () => {
    if (revealedToken === null) return
    try {
      await navigator.clipboard.writeText(revealedToken.token)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard API unavailable or denied — the token stays select-all-able in the <code> block.
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
    return frame(<LoadingState label={t('loading')} rows={4} />)
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
        title={t('title')}
        description={t('subtitle')}
        back={
          <Breadcrumbs
            ariaLabel={tCommon('breadcrumb.ariaLabel')}
            items={[
              { label: tCommon('header.boardsAdmin'), href: accountPath('/admin/boards') },
              {
                label: <span className="font-mono-num">{boardSlug}</span>,
                href: accountPath(`/admin/boards/${boardSlug ?? ''}`),
              },
              { label: t('title') },
            ]}
          />
        }
      >
        {rowError !== null && <Alert tone="error">{rowError}</Alert>}
      </PageHeader>

      <div className="flex flex-col gap-6">
        {/* ── Create form ───────────────────────────────────────────────── */}
        <Section
          title={t('createHeading')}
          icon={<KeyRound size={16} />}
          description={t('createBody')}
          flush
        >
          <form
            onSubmit={handleCreate}
            noValidate
            className="flex flex-col sm:flex-row sm:items-end gap-3 px-4 sm:px-5 py-5"
          >
            <div className="flex-1 sm:max-w-sm">
              <TextInput
                label={t('labelField')}
                name="token_label"
                id="token-label"
                value={label}
                onChange={setLabel}
                placeholder={t('labelPlaceholder')}
                error={labelError}
                disabled={creating}
                autoComplete="off"
              />
            </div>
            <div>
              <Button
                type="submit"
                variant="primary"
                disabled={creating || label.trim() === ''}
                loading={creating}
                aria-busy={creating}
                className="gap-1.5"
              >
                {!creating && <Plus size={16} aria-hidden="true" />}
                {creating ? t('creating') : t('createSubmit')}
              </Button>
            </div>
          </form>

          {(createGeneralError !== null || revealedToken !== null) && (
            <div className="px-4 sm:px-5 pb-5 flex flex-col gap-3">
              {createGeneralError !== null && <Alert tone="error">{createGeneralError}</Alert>}
              {revealedToken !== null && (
                <Alert
                  tone="warning"
                  role="alert"
                  title={t('revealedTokenNotice', { label: revealedToken.label })}
                >
                  <div className="mt-1.5 flex items-start gap-2">
                    <code className="flex-1 min-w-0 rounded-vp-sm border border-vp-warn/40 bg-vp-surface px-2.5 py-1.5 font-mono-num text-vp-sm text-vp-ink break-all select-all">
                      {revealedToken.token}
                    </code>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={() => void handleCopyToken()}
                      className="gap-1.5 shrink-0"
                      aria-label={tCommon(copied ? 'action.copied' : 'action.copy')}
                    >
                      {copied ? (
                        <Check size={14} aria-hidden="true" />
                      ) : (
                        <Copy size={14} aria-hidden="true" />
                      )}
                      {tCommon(copied ? 'action.copied' : 'action.copy')}
                    </Button>
                  </div>
                </Alert>
              )}
            </div>
          )}
        </Section>

        {/* ── Token list ────────────────────────────────────────────────── */}
        <Section title={t('tokensHeading', { count: tokens.length })} emphasis="ruled" flush>
          {tokens.length === 0 ? (
            <EmptyState size="compact" title={t('noTokensYet')} />
          ) : (
            <Table caption={t('tokensAriaLabel')}>
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('labelField')}</TableHeaderCell>
                  <TableHeaderCell>
                    <span className="sr-only">{t('createdOn', { date: '' })}</span>
                  </TableHeaderCell>
                  <TableHeaderCell numeric>
                    <span className="sr-only">{t('revoke')}</span>
                  </TableHeaderCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {tokens.map((token) => (
                  <TableRow
                    key={token.id}
                    className={token.revoked_at !== null ? 'text-vp-text-muted' : undefined}
                  >
                    <TableCell>
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{token.label}</span>
                        {token.revoked_at !== null && (
                          <Badge tone="neutral">{t('revokedSuffix')}</Badge>
                        )}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="text-vp-sm text-vp-text-secondary">
                        {t('createdOn', { date: formatDate(token.created_at, language) })}
                        {token.last_used_at !== null &&
                          t('lastUsedOn', { date: formatDate(token.last_used_at, language) })}
                      </span>
                    </TableCell>
                    <TableCell numeric>
                      {token.revoked_at === null && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setRevokeTarget({ id: token.id, label: token.label })}
                          disabled={busyKey !== null}
                          aria-label={t('revokeAriaLabel', { label: token.label })}
                          className="text-vp-vote-down-strong"
                        >
                          {t('revoke')}
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Section>
      </div>

      <ConfirmDialog
        open={revokeTarget !== null}
        title={revokeTarget !== null ? t('revokeAriaLabel', { label: revokeTarget.label }) : ''}
        description={t('confirmRevoke')}
        confirmLabel={t('revoke')}
        cancelLabel={tCommon('action.cancel')}
        tone="danger"
        busy={revokeTarget !== null && busyKey === `revoke-${revokeTarget.id}`}
        onConfirm={() => void handleRevoke()}
        onCancel={() => setRevokeTarget(null)}
      />
    </>,
  )
}
