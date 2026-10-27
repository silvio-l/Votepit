/**
 * ApiTokensPage — /admin/tokens
 *
 * Account-scoped API-token management (Agent API / Votepit MCP): list
 * existing tokens (label, scope, granted boards, created, last used, revoke)
 * + a create form (label, board multi-select, read/write/moderate grant). A newly
 * created token's plaintext is shown exactly once, directly under the
 * create form — standard practice (mirrors how the backend never
 * stores/returns it again, see ApiTokenAction) — then never again after the
 * page reloads the list.
 *
 * Auth gate: mirrors MembersPage — no client-side role check up front;
 * GET /admin/tokens itself enforces accountAdmin (owner OR admin may manage
 * tokens, see ApiTokenAction doc), so a 401/403 from that call drives the gate.
 */

import {
  Badge,
  Breadcrumbs,
  Button,
  ConfirmDialog,
  CopyField,
  EmptyState,
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
import { KeyRound, Plus } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { IssueTrackerConnectionsSection } from '../components/IssueTrackerConnectionsSection'
import { PlanUpgradeLink } from '../components/PlanUpgradeLink'
import { accountPath } from '../lib/accountContext'
import type { AgentTarget } from '../lib/agentPrompts'
import { AGENT_TARGETS, buildAgentPrompt } from '../lib/agentPrompts'
import type {
  AdminBoardSummary,
  ApiError,
  ApiTokenBoardGrant,
  ApiTokenScope,
  ApiTokenSummary,
  User,
} from '../lib/api'
import {
  bootstrap,
  createApiToken,
  listAdminBoards,
  listApiTokens,
  logout,
  revokeApiToken,
} from '../lib/api'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

const API_REFERENCE_URL = 'https://votepit.com/docs/api-reference/'
const MCP_SERVER_URL = 'https://votepit.com/docs/mcp-server/'

function setupTargetLabelKey(target: AgentTarget): string {
  switch (target) {
    case 'claude-code':
      return 'setupTargetClaudeCode'
    case 'claude-code-cli':
      return 'setupTargetClaudeCodeCli'
    case 'claude-desktop':
      return 'setupTargetClaudeDesktop'
    case 'cursor-vscode':
      return 'setupTargetCursorVscode'
    case 'generic-cli':
      return 'setupTargetGenericCli'
    default: {
      const exhaustive: never = target
      throw new Error(`Unknown agent target: ${String(exhaustive)}`)
    }
  }
}

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready' }

export default function ApiTokensPage() {
  const navigate = useNavigate()
  const t = useT('apiTokensPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  const [tokens, setTokens] = useState<ApiTokenSummary[]>([])
  const [boards, setBoards] = useState<AdminBoardSummary[]>([])

  const [label, setLabel] = useState('')
  const [boardScopes, setBoardScopes] = useState<Record<string, ApiTokenScope | ''>>({})
  const [labelError, setLabelError] = useState<string | undefined>(undefined)
  const [boardsError, setBoardsError] = useState<string | undefined>(undefined)
  const [createGeneralError, setCreateGeneralError] = useState<string | null>(null)
  /** Token creation failed on the plan (Agent API not included): offer the upgrade path. */
  const [createPlanLimit, setCreatePlanLimit] = useState(false)
  const [creating, setCreating] = useState(false)
  const [revealedToken, setRevealedToken] = useState<{
    id: number
    label: string
    token: string
    boards: ApiTokenBoardGrant[]
  } | null>(null)
  const [agentTarget, setAgentTarget] = useState<AgentTarget>('claude-code')

  const [busyKey, setBusyKey] = useState<string | null>(null)
  const [rowError, setRowError] = useState<string | null>(null)
  const [revokeTarget, setRevokeTarget] = useState<{ id: number; label: string } | null>(null)

  const boardNameById = (id: number): string => boards.find((b) => b.id === id)?.name ?? String(id)
  const boardSlugById = (id: number): string | undefined => boards.find((b) => b.id === id)?.slug
  const scopeLabel = (scope: ApiTokenScope): string =>
    scope === 'moderate' ? t('scopeModerate') : scope === 'write' ? t('scopeWrite') : t('scopeRead')

  const reload = async () => {
    const [tokensData, boardsData] = await Promise.all([listApiTokens(), listAdminBoards()])
    setTokens(tokensData.tokens)
    setBoards(boardsData.boards)
    return tokensData
  }

  // biome-ignore lint/correctness/useExhaustiveDependencies: reload/t are stable (defined inline, no external deps worth tracking); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/tokens'))}`, {
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
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/tokens'))}`, {
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
  }, [navigate])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const setBoardScope = (slug: string, value: ApiTokenScope | '') => {
    setBoardScopes((prev) => ({ ...prev, [slug]: value }))
  }

  const selectedBoardGrants = Object.entries(boardScopes)
    .filter((entry): entry is [string, ApiTokenScope] => entry[1] !== '')
    .map(([slug, scope]) => ({ slug, scope }))

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (creating || label.trim() === '' || selectedBoardGrants.length === 0) return

    setCreating(true)
    setLabelError(undefined)
    setBoardsError(undefined)
    setCreateGeneralError(null)
    setCreatePlanLimit(false)

    try {
      const created = await createApiToken(selectedBoardGrants, label.trim())
      setRevealedToken({
        id: created.id,
        label: created.label,
        token: created.token,
        boards: created.boards,
      })
      setAgentTarget('claude-code')
      setLabel('')
      setBoardScopes({})
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      const fieldLabel = apiErr?.payload?.fields?.label
      const fieldBoards = apiErr?.payload?.fields?.boards
      if (fieldLabel !== undefined || fieldBoards !== undefined) {
        setLabelError(fieldLabel)
        setBoardsError(fieldBoards)
      } else {
        if (apiErr?.payload?.key === 'plan_limit_agent_api') {
          setCreatePlanLimit(true)
          setCreateGeneralError(t('planLimitAgentApi'))
        } else {
          setCreateGeneralError(apiErr?.payload?.message ?? t('createFailed'))
        }
      }
    } finally {
      setCreating(false)
    }
  }

  const handleRevoke = async () => {
    if (revokeTarget === null) return
    const { id: tokenId } = revokeTarget
    setBusyKey(`revoke-${tokenId}`)
    setRowError(null)
    try {
      await revokeApiToken(tokenId)
      setRevokeTarget(null)
      await reload()
    } catch (err) {
      const apiErr = err as ApiError
      setRevokeTarget(null)
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
    return frame(<LoadingState label={t('loading')} rows={4} />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="api-tokens-access-logout" onClick={handleLogout}>
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
        title={t('title')}
        description={t('subtitle')}
        back={
          <Breadcrumbs
            testId="api-tokens-breadcrumbs"
            ariaLabel={tCommon('breadcrumb.ariaLabel')}
            items={[
              { label: tCommon('header.boardsAdmin'), href: accountPath('/admin/boards') },
              { label: t('title') },
            ]}
          />
        }
      >
        {rowError !== null && (
          <InlineNotice tone="error" testId="api-tokens-row-error">
            {rowError}
          </InlineNotice>
        )}
      </PageHeader>

      <div className="flex flex-col gap-ds-lg">
        {/* ── Create form ───────────────────────────────────────────────── */}
        <Section
          testId="api-tokens-create-section"
          title={t('createHeading')}
          icon={<KeyRound size={16} />}
          description={t('createBody')}
        >
          <p className="mb-ds-md text-vp-sm text-vp-text-secondary">
            {t('docsIntro')}{' '}
            <a
              href={API_REFERENCE_URL}
              target="_blank"
              rel="noreferrer"
              className="underline hover:no-underline"
            >
              {t('docsApiReference')}
            </a>{' '}
            ·{' '}
            <a
              href={MCP_SERVER_URL}
              target="_blank"
              rel="noreferrer"
              className="underline hover:no-underline"
            >
              {t('docsMcpServer')}
            </a>
          </p>

          <form onSubmit={handleCreate} noValidate className="flex flex-col gap-ds-md">
            <div className="flex-1 sm:max-w-ds-field">
              <TextField
                className="max-w-ds-field"
                testId="api-tokens-label"
                label={t('labelField')}
                name="token_label"
                id="token-label"
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder={t('labelPlaceholder')}
                error={labelError}
                disabled={creating}
                autoComplete="off"
              />
            </div>

            <fieldset className="flex flex-col gap-ds-xs">
              <legend className="text-vp-sm font-medium mb-ds-2xs">{t('boardsField')}</legend>
              {boards.length === 0 ? (
                <p className="text-vp-sm text-vp-text-secondary">{t('noBoardsYet')}</p>
              ) : (
                <div className="flex flex-col gap-ds-xs">
                  {boards.map((board) => (
                    <div key={board.id} className="flex items-center gap-ds-sm sm:max-w-ds-field">
                      <span className="flex-1 text-vp-sm">{board.name}</span>
                      <Select
                        testId={`api-tokens-board-scope-${board.slug}`}
                        label={t('boardScopeAriaLabel', { board: board.name })}
                        hideLabel
                        id={`token-board-scope-${board.slug}`}
                        value={boardScopes[board.slug] ?? ''}
                        onChange={(v) => setBoardScope(board.slug, v as ApiTokenScope | '')}
                        disabled={creating}
                        className="sm:max-w-[10rem]"
                      >
                        <option value="">{t('scopeNone')}</option>
                        <option value="moderate">{t('scopeModerate')}</option>
                        <option value="write">{t('scopeWrite')}</option>
                        <option value="read">{t('scopeRead')}</option>
                      </Select>
                    </div>
                  ))}
                </div>
              )}
              {boards.length > 0 && (
                <p className="text-vp-xs text-vp-text-secondary sm:max-w-ds-field">
                  {t('scopeModerateHint')}
                </p>
              )}
              {boardsError !== undefined && (
                <InlineNotice tone="error" testId="api-tokens-boards-error">
                  {boardsError}
                </InlineNotice>
              )}
            </fieldset>

            <div>
              <Button
                testId="api-tokens-submit"
                type="submit"
                variant="primary"
                disabled={creating || label.trim() === '' || selectedBoardGrants.length === 0}
                loading={creating}
                aria-busy={creating}
                className="gap-ds-xs"
              >
                {!creating && <Plus size={16} aria-hidden="true" />}
                {creating ? t('creating') : t('createSubmit')}
              </Button>
            </div>
          </form>

          {(createGeneralError !== null || revealedToken !== null) && (
            <div className="mt-ds-md flex flex-col gap-ds-sm">
              {createGeneralError !== null && (
                <InlineNotice tone="error" testId="api-tokens-create-error">
                  {createGeneralError}
                  {createPlanLimit && (
                    <>
                      {' '}
                      <PlanUpgradeLink label={tCommon('planUpgradeLinkLabel')} />
                    </>
                  )}
                </InlineNotice>
              )}
              {revealedToken !== null && (
                <InlineNotice
                  tone="warning"
                  role="alert"
                  testId="api-tokens-revealed"
                  title={t('revealedTokenNotice', { label: revealedToken.label })}
                >
                  <CopyField
                    testId="api-tokens-revealed-copy"
                    label={t('revealedTokenFieldLabel')}
                    value={revealedToken.token}
                    copyLabel={tCommon('action.copy')}
                    copiedLabel={tCommon('action.copied')}
                    mono
                    className="mt-ds-xs"
                  />

                  <div className="mt-ds-md flex flex-col gap-ds-sm">
                    <div>
                      <p className="text-vp-sm font-medium">{t('setupHeading')}</p>
                      <p className="text-vp-sm text-vp-text-secondary">{t('setupIntro')}</p>
                    </div>

                    <div className="sm:max-w-ds-field">
                      <Select
                        className="max-w-ds-field"
                        testId="api-tokens-setup-target"
                        label={t('setupTargetLabel')}
                        id="agent-setup-target"
                        value={agentTarget}
                        onChange={(v) => setAgentTarget(v as AgentTarget)}
                      >
                        {AGENT_TARGETS.map((targetOption) => (
                          <option key={targetOption} value={targetOption}>
                            {t(setupTargetLabelKey(targetOption))}
                          </option>
                        ))}
                      </Select>
                    </div>

                    <CopyField
                      testId="api-tokens-setup-prompt-copy"
                      label={t('setupPromptFieldLabel')}
                      value={buildAgentPrompt(agentTarget, {
                        origin: window.location.origin,
                        token: revealedToken.token,
                        boardSlug:
                          revealedToken.boards.length === 1
                            ? (boardSlugById(revealedToken.boards[0].board_id) ?? null)
                            : null,
                      })}
                      copyLabel={tCommon('action.copy')}
                      copiedLabel={tCommon('action.copied')}
                      mono
                    />
                  </div>
                </InlineNotice>
              )}
            </div>
          )}
        </Section>

        {/* ── Token list ────────────────────────────────────────────────── */}
        <Section
          testId="api-tokens-list-section"
          title={t('tokensHeading', { count: tokens.length })}
          emphasis="ruled"
          flush
        >
          {tokens.length === 0 ? (
            <EmptyState
              kind="no-ideas"
              variant="plain"
              testId="api-tokens-empty"
              title={t('noTokensYet')}
            />
          ) : (
            <Table testId="api-tokens-table" caption={t('tokensAriaLabel')}>
              <TableHead>
                <TableRow>
                  <TableHeaderCell>{t('labelField')}</TableHeaderCell>
                  <TableHeaderCell>{t('boardsField')}</TableHeaderCell>
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
                    testId={`api-token-row-${token.id}`}
                    className={token.revoked_at !== null ? 'text-vp-text-muted' : undefined}
                  >
                    <TableCell>
                      <span className="flex flex-wrap items-center gap-ds-xs">
                        <span className="font-medium">{token.label}</span>
                        {token.is_oauth && (
                          <Badge tone="accent" testId={`api-token-oauth-${token.id}`}>
                            {t('oauthBadge')}
                          </Badge>
                        )}
                        {token.revoked_at !== null && (
                          <Badge tone="neutral" testId={`api-token-revoked-${token.id}`}>
                            {t('revokedSuffix')}
                          </Badge>
                        )}
                      </span>
                    </TableCell>
                    <TableCell>
                      <span className="flex flex-wrap items-center gap-ds-xs">
                        {token.boards.map((grant) => (
                          <Badge
                            key={grant.board_id}
                            testId={`api-token-grant-${token.id}-${grant.board_id}`}
                            tone={
                              grant.scope === 'moderate'
                                ? 'warning'
                                : grant.scope === 'write'
                                  ? 'accent'
                                  : 'neutral'
                            }
                          >
                            {boardNameById(grant.board_id)}: {scopeLabel(grant.scope)}
                          </Badge>
                        ))}
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
                          testId={`api-token-revoke-${token.id}`}
                          variant="destructive"
                          onClick={() => setRevokeTarget({ id: token.id, label: token.label })}
                          disabled={busyKey !== null}
                          aria-label={t('revokeAriaLabel', { label: token.label })}
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

        <IssueTrackerConnectionsSection />
      </div>

      <ConfirmDialog
        testId="api-tokens-revoke-dialog"
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
