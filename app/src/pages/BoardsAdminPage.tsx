/**
 * BoardsAdminPage — /admin/boards
 *
 * Account-scoped board overview (read path) + create
 * form (write path). Lists all boards of the current account,
 * links to their board-scoped admin pages (/admin/boards/{slug}) and allows
 * creating a new board.
 *
 * Auth gate (mirrors AdminPage):
 *   - Anon      → redirect to /login?r=…
 *   - Non-admin → "no access" message (no list/form rendered)
 *   - Admin     → board cards + create form
 *
 * Frontend authz: no pre-gate on is_admin (platform-admin flag — NOT the
 * account-owner role; it wrongly locked real account owners out, see
 * Fable audit 2026-09-02). The server's 403 (accountAdmin()) is the only
 * authoritative check, see the catch below.
 *
 * Create form: the suggested slug is derived client-side from the name
 * (slugify) but is NEVER authoritative — server validation (SlugValidator)
 * decides. Once the slug was edited by hand it stops following the name.
 *
 * Board-operator acknowledgment (board-operator-compliance ticket 05, Cloud
 * only): a required checkbox distinct from the signup-time terms checkbox
 * (SignupAccountPage) — this one is board-scoped, shown once per board
 * creation. Gated on getEdition() === 'cloud' (self-host has no such
 * acknowledgment to make — NullBoardOperatorAcknowledgmentGate never checks
 * the field server-side either). Submit stays disabled client-side until
 * checked; the server independently rejects (422
 * `operator_acknowledgment_required`) a submission without it regardless of
 * client-side state.
 */

import { appExtensions } from '@votepit/app-extensions'
import {
  Badge,
  Button,
  ButtonLink,
  Card,
  Checkbox,
  ConfirmDialog,
  CopyLinkButton,
  EmptyState,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Select,
  StatCard,
  TextField,
} from '@votepit/ui/ds'
import { ArrowUpRight, LayoutGrid, Lightbulb, Plus, Settings, Vote } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { ActivationChecklist } from '../components/ActivationChecklist'
import { AdminShell } from '../components/AdminShell'
import { RouterButtonLink } from '../components/RouterButtonLink'
import SetupWizard from '../components/SetupWizard'
import { accountPath, fullBoardUrl } from '../lib/accountContext'
import type { AdminBoardSummary, ApiError, BoardVisibility, User } from '../lib/api'
import {
  bootstrap,
  completeOnboarding,
  createAdminBoard,
  listAdminBoards,
  logout,
} from '../lib/api'
import { getEdition } from '../lib/edition'
import { useT } from '../lib/i18n/context'
import { reopenSetupTarget } from '../lib/onboarding'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | {
      phase: 'ready'
      boards: AdminBoardSummary[]
      onboardingCompleted: boolean
      allowedVisibilities: BoardVisibility[]
      defaultVisibility: BoardVisibility
      hasCompleteLegalOperatorIdentity: boolean
    }

/**
 * Client-side slug suggestion from the board name — cosmetic only, never
 * authoritative (the server validates via SlugValidator). Lowercased,
 * diacritics stripped, anything non-[a-z0-9] becomes a hyphen, repeated /
 * edge hyphens removed, capped at 64 chars.
 */
function slugify(input: string): string {
  return input
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // strip combining diacritics (ä → a + ◌̈) — export-ok: comment-language
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64)
}

export default function BoardsAdminPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  // ?setup=1[&board=<slug>] reopens the setup wizard on a completed account
  // (checklist "Einrichtung fortsetzen", help entry in the page header).
  const reopenRequested = searchParams.get('setup') === '1'
  const reopenBoardSlug = searchParams.get('board')
  const t = useT('boardsAdminPage')
  const tSetup = useT('setupWizard')
  const tCommon = useT('common')
  // Visibility option labels are shared with the board-branding form
  // (AdminPage.tsx) — reused via the 'adminPage' namespace instead of
  // duplicating the same three strings here.
  const tVisibility = useT('adminPage')

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  // ── Create-form state (Issue 03) ─────────────────────────────────────────
  const [newName, setNewName] = useState('')
  const [newSlug, setNewSlug] = useState('')
  const [slugEdited, setSlugEdited] = useState(false)
  const [newVisibility, setNewVisibility] = useState<BoardVisibility>('private')
  const [pendingVisibility, setPendingVisibility] = useState<BoardVisibility | null>(null)
  const [operatorAcknowledged, setOperatorAcknowledged] = useState(false)
  const [createFieldErrors, setCreateFieldErrors] = useState<Record<string, string>>({})
  const [createGeneralError, setCreateGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const isCloud = getEdition() === 'cloud'

  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/boards'))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)

        const { boards, account } = await listAdminBoards()
        if (cancelled) return

        setNewVisibility(account.default_visibility ?? 'public')
        setPageState({
          phase: 'ready',
          boards,
          onboardingCompleted: account.onboarding_completed_at !== null,
          allowedVisibilities: account.allowed_visibilities ?? ['public'],
          defaultVisibility: account.default_visibility ?? 'public',
          hasCompleteLegalOperatorIdentity: account.has_complete_legal_operator_identity,
        })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/boards'))}`, {
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
  }, [navigate, t])

  // ── Setup wizard (onboarding) ─────────────────────────────────────────────

  const handleWizardDone = async (
    createdBoard: { slug: string; name: string } | null,
    to?: string,
  ) => {
    // Best-effort: even if this fails (network blip), the wizard just
    // reappears next load rather than blocking the admin from their account —
    // fail-open here, not fail-closed, since nothing destructive is at stake.
    try {
      await completeOnboarding()
    } catch {
      // ignore — see above
    }

    // A settings link from the wizard (already account-prefixed).
    if (to !== undefined) {
      navigate(to)
      return
    }
    if (createdBoard !== null) {
      navigate(accountPath(`/admin/boards/${createdBoard.slug}`))
      return
    }
    // Reopened wizard: drop the query so a reload shows the dashboard.
    if (reopenRequested) {
      navigate(accountPath('/admin/boards'), { replace: true })
      return
    }

    setPageState((prev) => (prev.phase === 'ready' ? { ...prev, onboardingCompleted: true } : prev))
  }

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  // ── Create-form handlers (Issue 03) ──────────────────────────────────────

  const handleNameChange = (value: string) => {
    setNewName(value)
    if (!slugEdited) {
      setNewSlug(slugify(value))
    }
  }

  const handleSlugChange = (value: string) => {
    setSlugEdited(true)
    setNewSlug(value)
  }

  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (creating) return

    setCreating(true)
    setCreateFieldErrors({})
    setCreateGeneralError(null)

    try {
      const result = await createAdminBoard({
        name: newName.trim(),
        slug: newSlug.trim(),
        visibility: newVisibility,
        operator_acknowledged: operatorAcknowledged,
      })
      navigate(accountPath(`/admin/boards/${result.slug}`))
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      setCreateFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        setCreateGeneralError(apiErr?.payload?.message ?? tCommon('state.error'))
      }
      setCreating(false)
    }
  }

  const frame = (children: ReactNode, width: 'default' | 'narrow' = 'default') => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      authPending={pageState.phase === 'loading'}
      onLogout={handleLogout}
      onLogin={() => navigate('/login')}
      width={width}
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={5} variant="card" />)
  }

  if (pageState.phase === 'access_denied') {
    return frame(
      <ErrorState
        kind="denied"
        title={t('accessDeniedTitle')}
        description={t('accessDeniedBody')}
        action={
          <Button testId="boards-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const {
    boards,
    onboardingCompleted,
    allowedVisibilities,
    defaultVisibility,
    hasCompleteLegalOperatorIdentity,
  } = pageState

  if (!onboardingCompleted || reopenRequested) {
    return frame(
      <SetupWizard
        boards={boards}
        allowedVisibilities={allowedVisibilities}
        defaultVisibility={defaultVisibility}
        mode={reopenRequested && onboardingCompleted ? 'reopen' : 'first-run'}
        targetBoardSlug={reopenBoardSlug}
        onDone={handleWizardDone}
      />,
      'narrow',
    )
  }

  const totalIdeas = boards.reduce((sum, b) => sum + b.idea_count, 0)
  const totalVotes = boards.reduce((sum, b) => sum + b.vote_count, 0)

  const createForm = (
    <Section
      testId="boards-create-section"
      id="board-create"
      title={t('createHeading')}
      description={t('createHint')}
      icon={<Plus size={16} />}
      flush
      footer={
        <Button
          type="submit"
          form="board-create-form"
          variant="primary"
          block
          testId="boards-create-submit"
          disabled={creating || (isCloud && !operatorAcknowledged)}
          loading={creating}
        >
          {creating ? t('createSubmitting') : t('createSubmit')}
        </Button>
      }
    >
      <form
        id="board-create-form"
        onSubmit={handleCreateSubmit}
        noValidate
        className="flex flex-col gap-ds-md px-ds-md sm:px-ds-lg py-ds-lg"
      >
        <div className="flex flex-col gap-4">
          <TextField
            label={t('nameLabel')}
            name="name"
            id="board-create-name"
            value={newName}
            onChange={(e) => handleNameChange(e.target.value)}
            placeholder={t('namePlaceholder')}
            error={createFieldErrors.name}
            required
            disabled={creating}
            testId="boards-create-name"
          />

          <TextField
            label={t('slugLabel')}
            name="slug"
            id="board-create-slug"
            value={newSlug}
            onChange={(e) => handleSlugChange(e.target.value)}
            placeholder={t('slugPlaceholder')}
            mono
            prefix="/"
            hint={createFieldErrors.slug === undefined ? t('slugHint') : undefined}
            error={createFieldErrors.slug}
            required
            disabled={creating}
            testId="boards-create-slug"
          />

          <Select
            testId="boards-create-visibility"
            label={tVisibility('visibilityLabel')}
            id="board-create-visibility"
            name="visibility"
            value={newVisibility}
            onChange={(v) => {
              const next = v as BoardVisibility
              if (next === 'public' && newVisibility !== 'public') {
                setPendingVisibility(next)
                return
              }
              setNewVisibility(next)
            }}
            disabled={creating}
            error={createFieldErrors.visibility}
            hint={
              createFieldErrors.visibility === undefined && allowedVisibilities.length <= 1
                ? tVisibility('visibilityUpgradeHint')
                : undefined
            }
          >
            <option value="public" disabled={!allowedVisibilities.includes('public')}>
              {tVisibility('visibilityPublic')}
            </option>
            <option value="unlisted" disabled={!allowedVisibilities.includes('unlisted')}>
              {tVisibility('visibilityUnlisted')}
            </option>
            <option value="private" disabled={!allowedVisibilities.includes('private')}>
              {tVisibility('visibilityPrivate')}
            </option>
          </Select>

          <ConfirmDialog
            testId="boards-visibility-dialog"
            open={pendingVisibility !== null}
            title={tVisibility('visibilityConfirmTitle')}
            description={tVisibility('visibilityConfirmBody')}
            confirmLabel={tVisibility('visibilityConfirmAction')}
            cancelLabel={tCommon('action.cancel')}
            onConfirm={() => {
              if (pendingVisibility !== null) setNewVisibility(pendingVisibility)
              setPendingVisibility(null)
            }}
            onCancel={() => setPendingVisibility(null)}
          />

          {isCloud && (
            <Checkbox
              id="board-create-operator-acknowledged"
              name="operator_acknowledged"
              label={t('operatorAckLabel')}
              checked={operatorAcknowledged}
              onChange={setOperatorAcknowledged}
              disabled={creating}
              testId="boards-operator-acknowledged"
            />
          )}

          {createGeneralError !== null && (
            <InlineNotice tone="error" testId="boards-create-error">
              {createGeneralError}
            </InlineNotice>
          )}
        </div>
      </form>
    </Section>
  )

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
        actions={
          <>
            <ButtonLink
              href={reopenSetupTarget()}
              linkAs={RouterButtonLink}
              variant="secondary"
              testId="boards-admin-setup-help"
            >
              {tSetup('reopenEntry')}
            </ButtonLink>
            <ButtonLink href="#board-create-name" variant="primary" testId="boards-admin-new-board">
              <Plus size={14} aria-hidden="true" />
              {t('newBoard')}
            </ButtonLink>
          </>
        }
      />

      {appExtensions.slots.accountAdminOverview}

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6 vp-stagger">
        <StatCard
          testId="boards-stat-boards"
          label={t('statBoards')}
          value={boards.length}
          icon={<LayoutGrid size={16} />}
          className="animate-vp-rise"
        />
        <StatCard
          testId="boards-stat-ideas"
          label={t('statIdeas')}
          value={totalIdeas}
          caption={t('statCaptionAll')}
          icon={<Lightbulb size={16} />}
          className="animate-vp-rise"
        />
        <StatCard
          testId="boards-stat-votes"
          label={t('statVotes')}
          value={totalVotes}
          caption={t('statCaptionAll')}
          icon={<Vote size={16} />}
          tone="accent"
          className="animate-vp-rise"
        />
      </div>

      <ActivationChecklist boards={boards} />

      <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_21rem] gap-6 items-start">
        <div className="min-w-0">
          {boards.length === 0 ? (
            <Section testId="boards-empty-section" flush>
              <EmptyState
                kind="no-ideas"
                variant="plain"
                testId="boards-empty"
                title={t('noBoards')}
                description={t('emptyDescription')}
                action={
                  <ButtonLink
                    href="#board-create-name"
                    variant="primary"
                    testId="boards-admin-create-first"
                  >
                    <Plus size={14} aria-hidden="true" />
                    {t('createFirstBoard')}
                  </ButtonLink>
                }
              />
            </Section>
          ) : (
            <section aria-label={t('boardsAriaLabel')}>
              <div className="flex items-center justify-between gap-3 mb-3">
                <h2 className="text-vp-sm font-semibold text-vp-ink leading-5 inline-flex items-center gap-1.5">
                  <LayoutGrid size={15} aria-hidden="true" className="text-vp-text-secondary" />
                  {t('boardsHeading', { count: boards.length })}
                </h2>
              </div>
              <ul className="grid grid-cols-1 md:grid-cols-2 gap-3 list-none m-0 p-0 vp-stagger">
                {boards.map((board) => (
                  <li key={board.id} className="animate-vp-rise">
                    <Card
                      interactive
                      padding="none"
                      testId={`boards-card-${board.slug}`}
                      className="h-full flex flex-col"
                    >
                      <div className="flex items-start justify-between gap-3 px-4 pt-4">
                        <div className="min-w-0">
                          <Link
                            to={accountPath(`/admin/boards/${board.slug}`)}
                            className="block font-semibold text-vp-md text-vp-ink leading-6 hyphens-auto break-words hover:text-vp-accent no-underline"
                          >
                            {board.name}
                          </Link>
                          <span className="mt-0.5 inline-flex items-center gap-1 text-vp-xs text-vp-text-muted">
                            <span aria-hidden="true">/</span>
                            <span className="font-mono-num">{board.slug}</span>
                          </span>
                        </div>
                        <div className="flex flex-col items-end gap-1">
                          {board.frozen_at !== null && (
                            <Badge tone="danger" dot testId={`boards-frozen-${board.slug}`}>
                              {t('frozen')}
                            </Badge>
                          )}
                          {board.compliance_frozen_at !== null && (
                            <Link
                              to={
                                hasCompleteLegalOperatorIdentity
                                  ? accountPath(`/admin/boards/${board.slug}`)
                                  : accountPath('/admin/account')
                              }
                              title={
                                hasCompleteLegalOperatorIdentity
                                  ? t('complianceFrozenHintBoard')
                                  : t('complianceFrozenHintAccount')
                              }
                              className="no-underline"
                            >
                              <Badge tone="danger" dot testId={`boards-compliance-${board.slug}`}>
                                {hasCompleteLegalOperatorIdentity
                                  ? t('complianceFrozenBoard')
                                  : t('complianceFrozenAccount')}
                              </Badge>
                            </Link>
                          )}
                        </div>
                      </div>
                      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-vp-sm text-vp-text-secondary">
                        <span className="inline-flex items-center gap-1.5">
                          <Lightbulb size={13} aria-hidden="true" />
                          <span className="tabular-nums font-semibold text-vp-ink">
                            {board.idea_count}
                          </span>
                          <span className="sr-only">
                            {t(board.idea_count === 1 ? 'ideasCountSingular' : 'ideasCount', {
                              count: board.idea_count,
                            })}
                          </span>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                          <Vote size={13} aria-hidden="true" />
                          <span className="tabular-nums font-semibold text-vp-ink">
                            {board.vote_count}
                          </span>
                          <span className="sr-only">
                            {t(board.vote_count === 1 ? 'votesCountSingular' : 'votesCount', {
                              count: board.vote_count,
                            })}
                          </span>
                        </span>
                      </div>
                      <div className="mt-auto flex flex-wrap items-center gap-ds-xs border-t border-vp-border-subtle bg-vp-surface-frost px-4 py-3 rounded-b-[inherit]">
                        <ButtonLink
                          href={accountPath(`/admin/boards/${board.slug}`)}
                          linkAs={RouterButtonLink}
                          variant="secondary"
                          testId={`boards-admin-manage-${board.slug}`}
                          aria-label={`${t('manage')}: ${board.name}`}
                        >
                          <Settings size={14} aria-hidden="true" />
                          {t('manage')}
                        </ButtonLink>
                        <CopyLinkButton
                          testId={`boards-copy-${board.slug}`}
                          value={fullBoardUrl(board.slug)}
                          label={`${t('copyLink')}: ${board.name}`}
                          copiedLabel={t('copyLinkCopied')}
                          className="ml-auto"
                        />
                        <ButtonLink
                          href={accountPath(`/${board.slug}`)}
                          linkAs={RouterButtonLink}
                          variant="secondary"
                          testId={`boards-admin-view-${board.slug}`}
                          aria-label={`${t('view')}: ${board.name}`}
                        >
                          {t('view')}
                          <ArrowUpRight size={14} aria-hidden="true" />
                        </ButtonLink>
                      </div>
                    </Card>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>

        <div className="lg:sticky lg:top-[calc(var(--spacing-vp-topbar)+1.5rem)]">{createForm}</div>
      </div>
    </>,
  )
}
