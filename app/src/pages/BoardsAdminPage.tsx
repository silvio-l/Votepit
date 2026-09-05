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
 */

import {
  Alert,
  Badge,
  Button,
  buttonClassName,
  Card,
  ConfirmDialog,
  CopyLinkButton,
  EmptyState,
  ErrorState,
  LoadingState,
  PageHeader,
  Section,
  Select,
  StatCard,
  TextInput,
} from '@votepit/ui'
import { ArrowUpRight, LayoutGrid, Lightbulb, Plus, Settings, Vote } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ActivationChecklist } from '../components/ActivationChecklist'
import { AdminShell } from '../components/AdminShell'
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
import { useT } from '../lib/i18n/context'

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
  const t = useT('boardsAdminPage')
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
  const [createFieldErrors, setCreateFieldErrors] = useState<Record<string, string>>({})
  const [createGeneralError, setCreateGeneralError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

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

  const handleWizardDone = async (createdBoard: { slug: string; name: string } | null) => {
    // Best-effort: even if this fails (network blip), the wizard just
    // reappears next load rather than blocking the admin from their account —
    // fail-open here, not fail-closed, since nothing destructive is at stake.
    try {
      await completeOnboarding()
    } catch {
      // ignore — see above
    }

    if (createdBoard !== null) {
      navigate(accountPath(`/admin/boards/${createdBoard.slug}`))
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
        action={<Button onClick={handleLogout}>{tCommon('header.logout')}</Button>}
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const { boards, onboardingCompleted, allowedVisibilities, defaultVisibility } = pageState

  if (!onboardingCompleted) {
    return frame(
      <SetupWizard
        boards={boards}
        allowedVisibilities={allowedVisibilities}
        defaultVisibility={defaultVisibility}
        onDone={handleWizardDone}
      />,
      'narrow',
    )
  }

  const totalIdeas = boards.reduce((sum, b) => sum + b.idea_count, 0)
  const totalVotes = boards.reduce((sum, b) => sum + b.vote_count, 0)

  const createForm = (
    <Section
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
          disabled={creating}
          loading={creating}
          aria-busy={creating}
        >
          {creating ? t('createSubmitting') : t('createSubmit')}
        </Button>
      }
    >
      <form id="board-create-form" onSubmit={handleCreateSubmit} noValidate>
        <div className="flex flex-col gap-4 px-4 sm:px-5 py-5">
          <TextInput
            label={t('nameLabel')}
            name="name"
            id="board-create-name"
            value={newName}
            onChange={handleNameChange}
            placeholder={t('namePlaceholder')}
            error={createFieldErrors.name}
            required
            disabled={creating}
          />

          <TextInput
            label={t('slugLabel')}
            name="slug"
            id="board-create-slug"
            value={newSlug}
            onChange={handleSlugChange}
            placeholder={t('slugPlaceholder')}
            mono
            prefix="/"
            hint={createFieldErrors.slug === undefined ? t('slugHint') : undefined}
            error={createFieldErrors.slug}
            required
            disabled={creating}
          />

          <Select
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

          {createGeneralError !== null && <Alert tone="error">{createGeneralError}</Alert>}
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
          <a href="#board-create-name" className={buttonClassName('primary', 'sm')}>
            <Plus size={14} aria-hidden="true" />
            {t('newBoard')}
          </a>
        }
      />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6 vp-stagger">
        <StatCard
          label={t('statBoards')}
          value={boards.length}
          icon={<LayoutGrid size={16} />}
          className="animate-vp-rise"
        />
        <StatCard
          label={t('statIdeas')}
          value={totalIdeas}
          caption={t('statCaptionAll')}
          icon={<Lightbulb size={16} />}
          className="animate-vp-rise"
        />
        <StatCard
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
            <Section flush>
              <EmptyState
                title={t('noBoards')}
                description={t('emptyDescription')}
                action={
                  <a href="#board-create-name" className={buttonClassName('primary', 'sm')}>
                    <Plus size={14} aria-hidden="true" />
                    {t('createFirstBoard')}
                  </a>
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
                    <Card interactive padding="none" className="h-full flex flex-col">
                      <div className="flex items-start justify-between gap-3 px-4 pt-4">
                        <div className="min-w-0">
                          <Link
                            to={accountPath(`/admin/boards/${board.slug}`)}
                            className="block font-semibold text-vp-md text-vp-ink leading-6 truncate hover:text-vp-accent no-underline"
                          >
                            {board.name}
                          </Link>
                          <span className="mt-0.5 inline-flex items-center gap-1 text-vp-xs text-vp-text-muted">
                            <span aria-hidden="true">/</span>
                            <span className="font-mono-num">{board.slug}</span>
                          </span>
                        </div>
                        {board.frozen_at !== null && (
                          <Badge tone="danger" dot>
                            {t('frozen')}
                          </Badge>
                        )}
                      </div>
                      <div className="flex items-center gap-4 px-4 py-3 text-vp-xs text-vp-text-secondary">
                        <span className="inline-flex items-center gap-1.5">
                          <Lightbulb size={13} aria-hidden="true" />
                          <span className="font-mono-num text-vp-ink">{board.idea_count}</span>
                          <span className="sr-only">
                            {t('ideasCount', { count: board.idea_count })}
                          </span>
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                          <Vote size={13} aria-hidden="true" />
                          <span className="font-mono-num text-vp-ink">{board.vote_count}</span>
                          <span className="sr-only">
                            {t('votesCount', { count: board.vote_count })}
                          </span>
                        </span>
                      </div>
                      <div className="mt-auto flex items-center gap-1 border-t border-vp-border-subtle bg-vp-surface-frost px-2 py-1.5 rounded-b-[inherit]">
                        <Link
                          to={accountPath(`/admin/boards/${board.slug}`)}
                          className={buttonClassName('ghost', 'sm')}
                          aria-label={`${t('manage')}: ${board.name}`}
                        >
                          <Settings size={14} aria-hidden="true" />
                          {t('manage')}
                        </Link>
                        <CopyLinkButton
                          value={fullBoardUrl(board.slug)}
                          label={`${t('copyLink')}: ${board.name}`}
                          copiedLabel={t('copyLinkCopied')}
                          size="sm"
                          className="ml-auto"
                        />
                        <Link
                          to={accountPath(`/${board.slug}`)}
                          className={buttonClassName('ghost', 'sm')}
                          aria-label={`${t('view')}: ${board.name}`}
                        >
                          {t('view')}
                          <ArrowUpRight size={14} aria-hidden="true" />
                        </Link>
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
