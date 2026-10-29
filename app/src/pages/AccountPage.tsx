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
  Button,
  ErrorState,
  InlineNotice,
  LoadingState,
  PageHeader,
  Section,
  Switch,
  TextArea,
  TextField,
} from '@votepit/ui/ds'
import {
  Activity,
  AlertTriangle,
  Building2,
  Check,
  Download,
  Loader2,
  Scale,
  Trash2,
  Undo2,
  X,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath, getAccountSlug, setAccountSlug } from '../lib/accountContext'
import type {
  AccountSettingsData,
  AdminBoardSummary,
  ApiError,
  ExportFormat,
  User,
} from '../lib/api'
import {
  bootstrap,
  cancelAccountDeletion,
  checkAccountSlugAvailable,
  downloadAccountExport,
  getAccountSettings,
  listAdminBoards,
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

  const [legalOperatorName, setLegalOperatorName] = useState('')
  const [legalOperatorAddress, setLegalOperatorAddress] = useState('')
  const [legalOperatorEmail, setLegalOperatorEmail] = useState('')
  const [legalOperatorNotice, setLegalOperatorNotice] = useState('')
  const [legalOperatorSaving, setLegalOperatorSaving] = useState(false)
  const [legalOperatorErrors, setLegalOperatorErrors] = useState<{
    legal_operator_name?: string
    legal_operator_address?: string
    legal_operator_email?: string
    legal_operator_notice?: string
  }>({})
  const [legalOperatorGeneralError, setLegalOperatorGeneralError] = useState<string | null>(null)
  const [legalOperatorSuccess, setLegalOperatorSuccess] = useState(false)
  const [complianceFrozenBoards, setComplianceFrozenBoards] = useState<AdminBoardSummary[]>([])

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
        setLegalOperatorName(data.legal_operator_name ?? '')
        setLegalOperatorAddress(data.legal_operator_address ?? '')
        setLegalOperatorEmail(data.legal_operator_email ?? '')
        setLegalOperatorNotice(data.legal_operator_notice ?? '')

        if (boot.user.compliance_frozen_board_count > 0) {
          listAdminBoards()
            .then(({ boards }) => {
              if (!cancelled) {
                setComplianceFrozenBoards(boards.filter((b) => b.compliance_frozen_at !== null))
              }
            })
            .catch(() => {})
        }
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

  const handleLegalOperatorSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (pageState.phase !== 'ready' || legalOperatorSaving) return

    const { data } = pageState
    const payload: {
      legal_operator_name?: string
      legal_operator_address?: string
      legal_operator_email?: string
      legal_operator_notice?: string
    } = {}
    if (legalOperatorName !== (data.legal_operator_name ?? '')) {
      payload.legal_operator_name = legalOperatorName
    }
    if (legalOperatorAddress !== (data.legal_operator_address ?? '')) {
      payload.legal_operator_address = legalOperatorAddress
    }
    if (legalOperatorEmail !== (data.legal_operator_email ?? '')) {
      payload.legal_operator_email = legalOperatorEmail
    }
    if (legalOperatorNotice !== (data.legal_operator_notice ?? '')) {
      payload.legal_operator_notice = legalOperatorNotice
    }
    if (Object.keys(payload).length === 0) return

    setLegalOperatorSaving(true)
    setLegalOperatorErrors({})
    setLegalOperatorGeneralError(null)
    setLegalOperatorSuccess(false)

    try {
      const result = await renameAccount(payload)
      setLegalOperatorSuccess(true)
      setPageState({
        phase: 'ready',
        data: {
          ...data,
          legal_operator_name: result.legal_operator_name,
          legal_operator_address: result.legal_operator_address,
          legal_operator_email: result.legal_operator_email,
          legal_operator_notice: result.legal_operator_notice,
        },
      })
      setLegalOperatorName(result.legal_operator_name ?? '')
      setLegalOperatorAddress(result.legal_operator_address ?? '')
      setLegalOperatorEmail(result.legal_operator_email ?? '')
      setLegalOperatorNotice(result.legal_operator_notice ?? '')

      // The server just re-ran BoardComplianceEnforcer as part of
      // renameAccount() (AccountRenameAction), so a previously frozen board
      // may have unfrozen — refetch instead of leaving the banner above
      // showing the stale pre-save list.
      listAdminBoards()
        .then(({ boards }) => {
          setComplianceFrozenBoards(boards.filter((b) => b.compliance_frozen_at !== null))
        })
        .catch(() => {})
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setLegalOperatorErrors(fields)
      } else {
        setLegalOperatorGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setLegalOperatorSaving(false)
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
        action={
          <Button testId="account-access-logout" onClick={handleLogout}>
            {tCommon('header.logout')}
          </Button>
        }
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

      <div className="flex flex-col gap-ds-lg">
        {/* ── Account summary + pending deletion ────────────────────────── */}
        <Section
          testId="account-summary-section"
          emphasis="ruled"
          title={t('accountHeading')}
          icon={<Building2 size={16} />}
        >
          {data.deletion_scheduled_at !== null ? (
            <InlineNotice
              tone="error"
              role="alert"
              testId="account-deletion-scheduled"
              title={t('deletionScheduledHeading')}
              action={
                <Button
                  testId="account-deletion-undo"
                  type="button"
                  variant="secondary"
                  className="gap-ds-xs"
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
              <p className="mt-ds-2xs text-vp-text-muted">{t('deletionScheduledHint')}</p>
              {undoDeletionError !== null && (
                <p className="mt-ds-2xs text-vp-vote-down-strong">{undoDeletionError}</p>
              )}
            </InlineNotice>
          ) : (
            <form onSubmit={handleRenameSave} noValidate>
              <div className="flex flex-col gap-ds-md">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-ds-md">
                  <TextField
                    className="max-w-ds-field"
                    testId="account-rename-name"
                    label={t('nameLabel')}
                    name="name"
                    id="account-rename-name"
                    value={renameName}
                    onChange={(e) => setRenameName(e.target.value)}
                    error={renameErrors.name}
                    disabled={renameSaving}
                    autoComplete="off"
                  />

                  <TextField
                    className="max-w-ds-field"
                    testId="account-rename-slug"
                    label={t('slugLabel')}
                    name="slug"
                    id="account-rename-slug"
                    value={renameSlug}
                    onChange={(e) => setRenameSlug(e.target.value)}
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

                <div className="flex items-center gap-ds-sm">
                  <Button
                    testId="account-rename-submit"
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

        {/* ── Legal-operator identity (board-operator-compliance issue 02) ── */}
        <Section
          testId="account-legal-section"
          emphasis="ruled"
          title={t('legalOperatorHeading')}
          icon={<Scale size={16} />}
        >
          <p className="text-vp-sm text-vp-text-muted mb-ds-md">{t('legalOperatorBody')}</p>
          {complianceFrozenBoards.length > 0 && (
            <InlineNotice
              tone="error"
              role="alert"
              testId="account-compliance-frozen"
              title={t('complianceFrozenBannerHeading')}
              className="mb-ds-md"
            >
              <p>{t('complianceFrozenBannerBody')}</p>
              <ul className="list-disc pl-ds-lg mt-ds-xs">
                {complianceFrozenBoards.map((board) => (
                  <li key={board.id}>{board.name}</li>
                ))}
              </ul>
            </InlineNotice>
          )}
          <form onSubmit={handleLegalOperatorSave} noValidate>
            <div className="flex flex-col gap-ds-md">
              <TextField
                className="max-w-ds-field"
                testId="account-legal-operator-name"
                label={t('legalOperatorNameLabel')}
                name="legal_operator_name"
                id="account-legal-operator-name"
                value={legalOperatorName}
                onChange={(e) => setLegalOperatorName(e.target.value)}
                error={legalOperatorErrors.legal_operator_name}
                hint={
                  legalOperatorErrors.legal_operator_name !== undefined
                    ? undefined
                    : t('legalOperatorNameHint')
                }
                placeholder={t('legalOperatorNamePlaceholder')}
                disabled={legalOperatorSaving}
                autoComplete="off"
                maxLength={255}
              />

              <TextArea
                className="max-w-ds-field-long"
                testId="account-legal-operator-address"
                label={t('legalOperatorAddressLabel')}
                name="legal_operator_address"
                id="account-legal-operator-address"
                value={legalOperatorAddress}
                onChange={(e) => setLegalOperatorAddress(e.target.value)}
                error={legalOperatorErrors.legal_operator_address}
                placeholder={t('legalOperatorAddressPlaceholder')}
                disabled={legalOperatorSaving}
                rows={3}
                maxLength={2000}
              />

              <TextField
                className="max-w-ds-field"
                testId="account-legal-operator-email"
                label={t('legalOperatorEmailLabel')}
                name="legal_operator_email"
                id="account-legal-operator-email"
                type="email"
                value={legalOperatorEmail}
                onChange={(e) => setLegalOperatorEmail(e.target.value)}
                error={legalOperatorErrors.legal_operator_email}
                hint={
                  legalOperatorErrors.legal_operator_email !== undefined
                    ? undefined
                    : t('legalOperatorEmailHint')
                }
                placeholder={t('legalOperatorEmailPlaceholder')}
                disabled={legalOperatorSaving}
                autoComplete="off"
                maxLength={255}
              />

              <TextArea
                className="max-w-ds-field-long"
                testId="account-legal-operator-notice"
                label={t('legalOperatorNoticeLabel')}
                name="legal_operator_notice"
                id="account-legal-operator-notice"
                value={legalOperatorNotice}
                onChange={(e) => setLegalOperatorNotice(e.target.value)}
                error={legalOperatorErrors.legal_operator_notice}
                hint={
                  legalOperatorErrors.legal_operator_notice !== undefined
                    ? undefined
                    : t('legalOperatorNoticeHint')
                }
                placeholder={t('legalOperatorNoticePlaceholder')}
                disabled={legalOperatorSaving}
                rows={3}
                maxLength={4000}
              />

              <div className="flex items-center gap-ds-sm">
                <Button
                  testId="account-legal-submit"
                  type="submit"
                  variant="primary"
                  disabled={legalOperatorSaving}
                  loading={legalOperatorSaving}
                  aria-busy={legalOperatorSaving}
                >
                  {legalOperatorSaving ? t('savingEllipsis') : t('legalOperatorSubmit')}
                </Button>
                {legalOperatorGeneralError !== null && (
                  <span className="text-vp-xs text-vp-vote-down-strong">
                    {legalOperatorGeneralError}
                  </span>
                )}
                {legalOperatorSuccess && (
                  <span className="text-vp-xs text-vp-vote-up-strong">
                    {t('legalOperatorSaved')}
                  </span>
                )}
              </div>
            </div>
          </form>
        </Section>

        {/* ── Product telemetry (self-host/Community only — Cloud has no
            such toggle, see Votepit\Telemetry\CommunityTelemetry) ────────── */}
        {getEdition() === 'community' && telemetryOptedIn !== null && (
          <Section
            testId="account-telemetry-section"
            title={t('telemetryHeading')}
            icon={<Activity size={16} />}
          >
            <Switch
              testId="account-telemetry-opt-in"
              label={t('telemetryToggleLabel')}
              hint={telemetryOptedIn ? t('telemetryStateOn') : t('telemetryStateOff')}
              checked={telemetryOptedIn}
              onChange={(next) => void handleTelemetryToggle(next)}
              disabled={telemetrySaving}
            />
            <p className="mt-ds-md text-vp-xs text-vp-text-muted max-w-prose">
              {t('telemetryHint')}
            </p>
            {telemetryError !== null && (
              <InlineNotice tone="error" className="mt-ds-sm" testId="account-telemetry-error">
                {telemetryError}
              </InlineNotice>
            )}
            {telemetrySaved && (
              <InlineNotice
                tone="success"
                className="mt-ds-sm animate-vp-fade-in"
                testId="account-telemetry-saved"
              >
                {t('telemetrySaved')}
              </InlineNotice>
            )}
          </Section>
        )}

        {/* ── Data export ───────────────────────────────────────────────── */}
        <Section
          testId="account-export-section"
          title={t('exportHeading')}
          icon={<Download size={16} />}
          description={t('exportBody')}
        >
          {exportError !== null && (
            <div className="mb-ds-md">
              <InlineNotice tone="error" testId="account-export-error">
                {exportError}
              </InlineNotice>
            </div>
          )}
          <div className="flex flex-wrap gap-ds-sm">
            <Button
              testId="account-export-json"
              type="button"
              variant="secondary"
              className="gap-ds-xs"
              onClick={() => void handleExport('json')}
              disabled={exportingFormat !== null}
              loading={exportingFormat === 'json'}
            >
              {exportingFormat !== 'json' && <Download size={14} aria-hidden="true" />}
              {exportingFormat === 'json' ? t('exportingJson') : t('exportJson')}
            </Button>
            <Button
              testId="account-export-csv"
              type="button"
              variant="secondary"
              className="gap-ds-xs"
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
            testId="account-danger-section"
            title={t('dangerZoneHeading')}
            icon={<AlertTriangle size={16} />}
            description={t('dangerZoneBody')}
            emphasis="danger"
            flush
          >
            <div className="px-ds-md sm:px-ds-lg py-ds-md">
              {deleteAccountError !== null && (
                <div className="mb-ds-sm">
                  <InlineNotice tone="error" testId="account-delete-error">
                    {deleteAccountError}
                  </InlineNotice>
                </div>
              )}
              {!deleteConfirmOpen ? (
                <Button
                  testId="account-delete-open"
                  type="button"
                  variant="destructive"
                  className="gap-ds-xs"
                  onClick={() => setDeleteConfirmOpen(true)}
                  disabled={data.deletion_scheduled_at !== null}
                >
                  <Trash2 size={14} aria-hidden="true" />
                  {t('deleteAccountButton')}
                </Button>
              ) : (
                <div className="flex flex-col gap-ds-sm max-w-ds-field">
                  <TextField
                    className="max-w-ds-field"
                    testId="account-delete-slug"
                    label={t('deleteAccountConfirmLabel', { slug: data.slug })}
                    placeholder={t('deleteAccountConfirmPlaceholder')}
                    value={deleteSlugInput}
                    onChange={(e) => setDeleteSlugInput(e.target.value)}
                    mono
                    autoComplete="off"
                  />
                  <div className="flex flex-wrap gap-ds-xs">
                    <Button
                      testId="account-delete-confirm"
                      type="button"
                      variant="destructive"
                      onClick={() => void handleRequestDeletion()}
                      disabled={deletingAccount || deleteSlugInput !== data.slug}
                      loading={deletingAccount}
                    >
                      {deletingAccount ? t('deletingAccount') : t('deleteAccountConfirmSubmit')}
                    </Button>
                    <Button
                      testId="account-delete-cancel"
                      type="button"
                      variant="secondary"
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
