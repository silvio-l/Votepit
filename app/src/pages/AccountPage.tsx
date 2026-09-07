/**
 * AccountPage — /admin/account
 *
 * Owner-only account settings: data export (GDPR Art. 20) and self-service
 * account deletion with a 48h grace period (GDPR Art. 17), plus the undo
 * for a pending deletion. Anything plan- or payment-related lives in an
 * SPA extension (`@votepit/app-extensions`), not here — a self-hosted
 * installation has no such concept.
 *
 * Auth gate mirrors MembersPage: anon → redirect to /login; non-owner
 * (moderator or non-member) → "no access" (server-enforced via
 * AuthZMiddleware::accountOwner(); this client gate is UX only).
 */

import {
  Alert,
  Button,
  ErrorState,
  LoadingState,
  PageHeader,
  Section,
  Switch,
  TextInput,
} from '@votepit/ui'
import {
  Activity,
  AlertTriangle,
  Building2,
  Check,
  Download,
  Loader2,
  Trash2,
  Undo2,
  X,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath, getAccountSlug, setAccountSlug } from '../lib/accountContext'
import type { AccountSettingsData, ApiError, ExportFormat, User } from '../lib/api'
import {
  bootstrap,
  cancelAccountDeletion,
  checkAccountSlugAvailable,
  downloadAccountExport,
  getAccountSettings,
  logout,
  renameAccount,
  requestAccountDeletion,
  setTelemetryOptIn,
} from '../lib/api'
import { getEdition } from '../lib/edition'
import { formatDate } from '../lib/formatDate'
import { useI18n, useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; data: AccountSettingsData }

export default function AccountPage() {
  const navigate = useNavigate()
  const t = useT('accountPage')
  const tCommon = useT('common')
  const { language } = useI18n()

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  const [exportingFormat, setExportingFormat] = useState<ExportFormat | null>(null)
  const [exportError, setExportError] = useState<string | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [deleteSlugInput, setDeleteSlugInput] = useState('')
  const [deletingAccount, setDeletingAccount] = useState(false)
  const [deleteAccountError, setDeleteAccountError] = useState<string | null>(null)
  const [undoingDeletion, setUndoingDeletion] = useState(false)
  const [undoDeletionError, setUndoDeletionError] = useState<string | null>(null)
  const [telemetryOptedIn, setTelemetryOptedIn] = useState<boolean | null>(null)
  const [telemetrySaving, setTelemetrySaving] = useState(false)
  const [telemetrySaved, setTelemetrySaved] = useState(false)
  const [telemetryError, setTelemetryError] = useState<string | null>(null)

  const [renameName, setRenameName] = useState('')
  const [renameSlug, setRenameSlug] = useState('')
  const [renameSaving, setRenameSaving] = useState(false)
  const [renameErrors, setRenameErrors] = useState<{ name?: string; slug?: string }>({})
  const [renameGeneralError, setRenameGeneralError] = useState<string | null>(null)
  const [renameSuccess, setRenameSuccess] = useState(false)
  const [slugCheck, setSlugCheck] = useState<'idle' | 'checking' | 'available' | 'taken'>('idle')
  const slugCheckSeq = useRef(0)

  // biome-ignore lint/correctness/useExhaustiveDependencies: t is stable per namespace (see useT); only navigate matters.
  useEffect(() => {
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/account'))}`, {
            replace: true,
          })
          return
        }

        setIsAuthenticated(true)
        setUser(boot.user)
        if (boot.telemetry != null) setTelemetryOptedIn(boot.telemetry.opted_in)

        const data = await getAccountSettings()
        if (cancelled) return
        setPageState({ phase: 'ready', data })
        setRenameName(data.name)
        setRenameSlug(data.slug)
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath('/admin/account'))}`, {
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

  // Debounced live slug-availability check (account slug is unique
  // platform-wide, unlike a board slug — see AccountRepository::isSlugAvailable()).
  // biome-ignore lint/correctness/useExhaustiveDependencies: pageState carries the current slug to compare against, but we only need its value at fire time.
  useEffect(() => {
    if (pageState.phase !== 'ready') return
    const trimmed = renameSlug.trim()
    if (trimmed === '' || trimmed === pageState.data.slug) {
      setSlugCheck('idle')
      return
    }

    const seq = ++slugCheckSeq.current
    setSlugCheck('checking')
    const timer = window.setTimeout(() => {
      void checkAccountSlugAvailable(trimmed)
        .then((result) => {
          if (slugCheckSeq.current !== seq) return
          setSlugCheck(result.available ? 'available' : 'taken')
        })
        .catch(() => {
          if (slugCheckSeq.current !== seq) return
          setSlugCheck('idle')
        })
    }, 400)

    return () => window.clearTimeout(timer)
  }, [renameSlug])

  const handleRenameSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (pageState.phase !== 'ready' || renameSaving) return

    const { data } = pageState
    const payload: { name?: string; slug?: string } = {}
    if (renameName !== data.name) payload.name = renameName
    if (renameSlug !== data.slug) payload.slug = renameSlug
    if (Object.keys(payload).length === 0) return

    setRenameSaving(true)
    setRenameErrors({})
    setRenameGeneralError(null)
    setRenameSuccess(false)

    try {
      const result = await renameAccount(payload)
      setRenameSuccess(true)
      setPageState({ phase: 'ready', data: { ...data, name: result.name, slug: result.slug } })
      setRenameName(result.name)
      setRenameSlug(result.slug)
      if (result.slug !== data.slug) {
        // Cloud mode: the account now lives at a new URL prefix
        // (/{oldSlug}/... → /{newSlug}/...) — every further request/link
        // built from the stale prefix would 404, so redirect immediately,
        // same as AdminPage.tsx does when a board slug changes. Read the
        // OLD prefix presence (not the new slug) to tell cloud from
        // self-host: self-host has no /:accountSlug route registered at
        // all, so it must never call accountPath() with a slug in scope.
        const wasCloudScoped = getAccountSlug() !== null
        if (wasCloudScoped) {
          setAccountSlug(result.slug)
          navigate(`/${result.slug}/admin/account`, { replace: true })
        }
      }
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setRenameErrors(fields)
      } else {
        setRenameGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setRenameSaving(false)
    }
  }

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleExport = async (format: ExportFormat) => {
    setExportError(null)
    setExportingFormat(format)
    try {
      await downloadAccountExport(format)
    } catch (err) {
      const apiErr = err as ApiError
      setExportError(apiErr?.payload?.message ?? (err as Error)?.message ?? t('exportFailed'))
    } finally {
      setExportingFormat(null)
    }
  }

  const handleRequestDeletion = async () => {
    setDeletingAccount(true)
    setDeleteAccountError(null)
    try {
      const result = await requestAccountDeletion(deleteSlugInput)
      if (pageState.phase === 'ready') {
        setPageState({
          phase: 'ready',
          data: { ...pageState.data, deletion_scheduled_at: result.deletion_scheduled_at },
        })
      }
      setDeleteConfirmOpen(false)
      setDeleteSlugInput('')
    } catch (err) {
      const apiErr = err as ApiError
      setDeleteAccountError(
        apiErr?.payload?.message ?? (err as Error)?.message ?? t('deleteAccountFailed'),
      )
    } finally {
      setDeletingAccount(false)
    }
  }

  const handleTelemetryToggle = async (next: boolean) => {
    setTelemetrySaving(true)
    setTelemetryError(null)
    setTelemetrySaved(false)
    const previous = telemetryOptedIn
    setTelemetryOptedIn(next) // optimistic — same pattern as ProfilePage's privacy toggle
    try {
      const result = await setTelemetryOptIn(next)
      setTelemetryOptedIn(result.opted_in)
      setTelemetrySaved(true)
    } catch (err) {
      setTelemetryOptedIn(previous)
      const apiErr = err as ApiError
      setTelemetryError(
        apiErr?.payload?.message ?? (err as Error)?.message ?? t('telemetrySaveFailed'),
      )
    } finally {
      setTelemetrySaving(false)
    }
  }

  const handleUndoDeletion = async () => {
    setUndoingDeletion(true)
    setUndoDeletionError(null)
    try {
      await cancelAccountDeletion()
      if (pageState.phase === 'ready') {
        setPageState({
          phase: 'ready',
          data: { ...pageState.data, deletion_scheduled_at: null },
        })
      }
    } catch (err) {
      const apiErr = err as ApiError
      setUndoDeletionError(
        apiErr?.payload?.message ?? (err as Error)?.message ?? t('deletionScheduledUndoFailed'),
      )
    } finally {
      setUndoingDeletion(false)
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
        action={<Button onClick={handleLogout}>{tCommon('header.logout')}</Button>}
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  const { data } = pageState
  const msRemaining =
    data.deletion_scheduled_at !== null
      ? Math.max(0, new Date(data.deletion_scheduled_at).getTime() - Date.now())
      : 0
  const hoursRemaining = Math.ceil(msRemaining / 3_600_000)
  const daysRemaining = Math.ceil(msRemaining / 86_400_000)
  // Show hours once the deadline is under 72h out, days before that.
  const showHoursRemaining = hoursRemaining < 72

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title')}
        description={t('subtitle')}
      />

      <div className="flex flex-col gap-6">
        {/* ── Account summary + pending deletion ────────────────────────── */}
        <Section emphasis="ruled" title={t('accountHeading')} icon={<Building2 size={16} />}>
          {data.deletion_scheduled_at !== null ? (
            <Alert
              tone="error"
              role="alert"
              title={t('deletionScheduledHeading')}
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  className="gap-1.5"
                  onClick={() => void handleUndoDeletion()}
                  disabled={undoingDeletion}
                  loading={undoingDeletion}
                >
                  {!undoingDeletion && <Undo2 size={14} aria-hidden="true" />}
                  {undoingDeletion ? t('deletionScheduledUndoing') : t('deletionScheduledUndo')}
                </Button>
              }
            >
              <p>
                {t('deletionScheduledPart1')}{' '}
                <strong>{formatDate(data.deletion_scheduled_at, language)}</strong>{' '}
                {showHoursRemaining
                  ? t('deletionScheduledPart2Hours')
                  : t('deletionScheduledPart2Days')}
                {showHoursRemaining ? hoursRemaining : daysRemaining}{' '}
                {showHoursRemaining
                  ? t('deletionScheduledPart3Hours')
                  : t('deletionScheduledPart3Days')}
              </p>
              <p className="mt-1 text-vp-text-muted">{t('deletionScheduledHint')}</p>
              {undoDeletionError !== null && (
                <p className="mt-1 text-vp-vote-down-strong">{undoDeletionError}</p>
              )}
            </Alert>
          ) : (
            <form onSubmit={handleRenameSave} noValidate>
              <div className="flex flex-col gap-5">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <TextInput
                    label={t('nameLabel')}
                    name="name"
                    id="account-rename-name"
                    value={renameName}
                    onChange={setRenameName}
                    error={renameErrors.name}
                    disabled={renameSaving}
                    autoComplete="off"
                  />

                  <TextInput
                    label={t('slugLabel')}
                    name="slug"
                    id="account-rename-slug"
                    value={renameSlug}
                    onChange={setRenameSlug}
                    mono
                    error={renameErrors.slug}
                    hint={renameErrors.slug !== undefined ? undefined : t('slugHint')}
                    disabled={renameSaving}
                    autoComplete="off"
                    trailing={
                      slugCheck === 'checking' ? (
                        <Loader2
                          size={14}
                          className="animate-spin text-vp-text-muted"
                          aria-label={t('slugChecking')}
                        />
                      ) : slugCheck === 'available' ? (
                        <Check
                          size={14}
                          className="text-vp-vote-up-strong"
                          aria-label={t('slugAvailable')}
                        />
                      ) : slugCheck === 'taken' ? (
                        <X
                          size={14}
                          className="text-vp-vote-down-strong"
                          aria-label={t('slugTaken')}
                        />
                      ) : undefined
                    }
                  />
                </div>

                {slugCheck === 'taken' && renameErrors.slug === undefined && (
                  <p className="text-vp-xs text-vp-vote-down-strong -mt-3">{t('slugTaken')}</p>
                )}

                <div className="flex items-center gap-3">
                  <Button
                    type="submit"
                    variant="primary"
                    disabled={renameSaving || slugCheck === 'checking' || slugCheck === 'taken'}
                    loading={renameSaving}
                    aria-busy={renameSaving}
                  >
                    {renameSaving ? t('savingEllipsis') : t('renameSubmit')}
                  </Button>
                  {renameGeneralError !== null && (
                    <span className="text-vp-xs text-vp-vote-down-strong">
                      {renameGeneralError}
                    </span>
                  )}
                  {renameSuccess && (
                    <span className="text-vp-xs text-vp-vote-up-strong">{t('renameSaved')}</span>
                  )}
                </div>
              </div>
            </form>
          )}
        </Section>

        {/* ── Product telemetry (self-host/Community only — Cloud has no
            such toggle, see Votepit\Telemetry\CommunityTelemetry) ────────── */}
        {getEdition() === 'community' && telemetryOptedIn !== null && (
          <Section title={t('telemetryHeading')} icon={<Activity size={16} />}>
            <Switch
              id="telemetry-opt-in"
              label={t('telemetryToggleLabel')}
              hint={telemetryOptedIn ? t('telemetryStateOn') : t('telemetryStateOff')}
              checked={telemetryOptedIn}
              onChange={(next) => void handleTelemetryToggle(next)}
              disabled={telemetrySaving}
              aria-busy={telemetrySaving || undefined}
            />
            <p className="mt-4 text-vp-xs text-vp-text-muted max-w-prose">{t('telemetryHint')}</p>
            {telemetryError !== null && (
              <Alert tone="error" className="mt-3">
                {telemetryError}
              </Alert>
            )}
            {telemetrySaved && (
              <Alert tone="success" className="mt-3 animate-vp-fade-in">
                {t('telemetrySaved')}
              </Alert>
            )}
          </Section>
        )}

        {/* ── Data export ───────────────────────────────────────────────── */}
        <Section
          title={t('exportHeading')}
          icon={<Download size={16} />}
          description={t('exportBody')}
        >
          {exportError !== null && (
            <div className="mb-4">
              <Alert tone="error">{exportError}</Alert>
            </div>
          )}
          <div className="flex flex-wrap gap-3">
            <Button
              type="button"
              variant="secondary"
              className="gap-1.5"
              onClick={() => void handleExport('json')}
              disabled={exportingFormat !== null}
              loading={exportingFormat === 'json'}
            >
              {exportingFormat !== 'json' && <Download size={14} aria-hidden="true" />}
              {exportingFormat === 'json' ? t('exportingJson') : t('exportJson')}
            </Button>
            <Button
              type="button"
              variant="secondary"
              className="gap-1.5"
              onClick={() => void handleExport('csv')}
              disabled={exportingFormat !== null}
              loading={exportingFormat === 'csv'}
            >
              {exportingFormat !== 'csv' && <Download size={14} aria-hidden="true" />}
              {exportingFormat === 'csv' ? t('exportingCsv') : t('exportCsv')}
            </Button>
          </div>
        </Section>

        {/* ── Danger zone — GDPR self-service account deletion ──────────── */}
        {!data.is_default_account && (
          <Section
            title={t('dangerZoneHeading')}
            icon={<AlertTriangle size={16} />}
            description={t('dangerZoneBody')}
            emphasis="danger"
            flush
          >
            <div className="px-4 sm:px-5 py-4">
              {deleteAccountError !== null && (
                <div className="mb-3">
                  <Alert tone="error">{deleteAccountError}</Alert>
                </div>
              )}
              {!deleteConfirmOpen ? (
                <Button
                  type="button"
                  variant="danger"
                  className="gap-1.5"
                  onClick={() => setDeleteConfirmOpen(true)}
                  disabled={data.deletion_scheduled_at !== null}
                >
                  <Trash2 size={14} aria-hidden="true" />
                  {t('deleteAccountButton')}
                </Button>
              ) : (
                <div className="flex flex-col gap-3 max-w-sm">
                  <TextInput
                    label={t('deleteAccountConfirmLabel', { slug: data.slug })}
                    placeholder={t('deleteAccountConfirmPlaceholder')}
                    value={deleteSlugInput}
                    onChange={setDeleteSlugInput}
                    mono
                    autoComplete="off"
                  />
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      variant="danger"
                      size="sm"
                      onClick={() => void handleRequestDeletion()}
                      disabled={deletingAccount || deleteSlugInput !== data.slug}
                      loading={deletingAccount}
                    >
                      {deletingAccount ? t('deletingAccount') : t('deleteAccountConfirmSubmit')}
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={() => {
                        setDeleteConfirmOpen(false)
                        setDeleteSlugInput('')
                        setDeleteAccountError(null)
                      }}
                      disabled={deletingAccount}
                    >
                      {t('deleteAccountConfirmCancel')}
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </Section>
        )}
      </div>
    </>,
  )
}
