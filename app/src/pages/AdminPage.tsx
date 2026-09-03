/**
 * AdminPage — /admin/boards/:boardSlug
 *
 * Board admin surface: Branding + Moderation + SMTP (Issue 14).
 *
 * Auth gate:
 *   - Anon      → redirect to /login?r=…
 *   - Non-admin → "no access" message (no forms rendered)
 *   - Admin     → three sheets: Branding, Moderation, SMTP
 *
 * The SMTP sheet only exists where the installation offers per-board SMTP
 * (bootstrap `features.board_smtp`, see lib/features.ts) — a hosted
 * multi-tenant install sends through the operator's mailer only and has no
 * per-board SMTP routes at all.
 *
 * Branding: primary_color, secondary_color, logo_url, intro, hide_badge,
 *   visibility. Only --vp-* brand tokens; semantic vote/status tokens are
 *   intentionally NOT editable so Up/Down meaning stays readable everywhere.
 *   Every channel here is server-validated (hex, image URL, plaintext) —
 *   tenant isolation depends on that, so nothing else is exposed.
 *
 * Moderation: toggle (enabled/disabled) + custom blocklist (add / remove).
 *   Word-add reloads the list so IDs are accurate for subsequent removes.
 */

import {
  Alert,
  Breadcrumbs,
  Button,
  buttonClassName,
  Checkbox,
  ErrorState,
  LoadingState,
  PageHeader,
  Section,
  Select,
  Textarea,
  TextInput,
} from '@votepit/ui'
import { ExternalLink, KeyRound, Mail, Palette, ShieldBan, X } from 'lucide-react'
import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { accountPath } from '../lib/accountContext'
import type { ApiError, BoardVisibility, BrandingField, ModerationWord, User } from '../lib/api'
import {
  bootstrap,
  getAdminBoardSmtp,
  getAdminBranding,
  getAdminModeration,
  logout,
  resetAdminBoardSmtp,
  saveAdminBoardSmtp,
  saveAdminBranding,
  saveAdminModeration,
  testAdminBoardSmtp,
} from '../lib/api'
import { getFeatures } from '../lib/features'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'loading' }
  | { phase: 'access_denied' }
  | { phase: 'error'; message: string }
  | { phase: 'ready'; boardName: string }

type SmtpPreset = 'outlook' | 'gmail' | 'custom'
type SmtpEncryption = 'tls' | 'ssl' | ''

export default function AdminPage() {
  const { boardSlug } = useParams<{ boardSlug: string }>()
  const navigate = useNavigate()
  const t = useT('adminPage')
  const tCommon = useT('common')

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [user, setUser] = useState<User | null>(null)

  // ── Branding state ─────────────────────────────────────────────────────────
  const [primaryColor, setPrimaryColor] = useState('')
  const [secondaryColor, setSecondaryColor] = useState('')
  const [logoUrl, setLogoUrl] = useState('')
  const [intro, setIntro] = useState('')
  const [hideBadge, setHideBadge] = useState(false)
  const [visibility, setVisibility] = useState<BoardVisibility>('public')
  const [allowedVisibilities, setAllowedVisibilities] = useState<BoardVisibility[]>(['public'])
  const [allowedBrandingFields, setAllowedBrandingFields] = useState<BrandingField[]>([])
  const [brandingErrors, setBrandingErrors] = useState<Record<string, string>>({})
  const [brandingGeneralError, setBrandingGeneralError] = useState<string | null>(null)
  const [brandingSaving, setBrandingSaving] = useState(false)
  const [brandingSuccess, setBrandingSuccess] = useState(false)
  // Upgrade/downgrade/cancellation lifecycle: frozen-board notice.
  const [frozenAt, setFrozenAt] = useState<string | null>(null)

  // ── Moderation state ───────────────────────────────────────────────────────
  const [modEnabled, setModEnabled] = useState(true)
  const [words, setWords] = useState<ModerationWord[]>([])
  const [newWord, setNewWord] = useState('')
  const [modErrors, setModErrors] = useState<Record<string, string>>({})
  const [modGeneralError, setModGeneralError] = useState<string | null>(null)
  const [modSaving, setModSaving] = useState(false)
  const [modSuccess, setModSuccess] = useState<string | null>(null)

  // ── SMTP state ─────────────────────────────────────────────────────────────
  const [smtpPreset, setSmtpPreset] = useState<SmtpPreset>('custom')
  const [smtpHost, setSmtpHost] = useState('')
  const [smtpPort, setSmtpPort] = useState(587)
  const [smtpUser, setSmtpUser] = useState('')
  const [smtpEncryption, setSmtpEncryption] = useState<SmtpEncryption>('tls')
  const [smtpFromEmail, setSmtpFromEmail] = useState('')
  const [smtpFromName, setSmtpFromName] = useState('')
  const [smtpPassword, setSmtpPassword] = useState('')
  const [smtpPasswordSet, setSmtpPasswordSet] = useState(false)
  const [smtpVerifyPeer, setSmtpVerifyPeer] = useState(true)
  const [smtpUsesGlobalDefault, setSmtpUsesGlobalDefault] = useState(true)
  const [smtpErrors, setSmtpErrors] = useState<Record<string, string>>({})
  const [smtpGeneralError, setSmtpGeneralError] = useState<string | null>(null)
  const [smtpSaving, setSmtpSaving] = useState(false)
  const [smtpSuccess, setSmtpSuccess] = useState(false)
  const [smtpTestSending, setSmtpTestSending] = useState(false)
  const [smtpTestResult, setSmtpTestResult] = useState<{ ok: boolean; message: string } | null>(
    null,
  )
  const [smtpTestTo, setSmtpTestTo] = useState('')
  // Read once per mount — features are fixed for the lifetime of the SPA.
  const boardSmtpEnabled = getFeatures().board_smtp

  // ── Initialise ─────────────────────────────────────────────────────────────

  useEffect(() => {
    if (!boardSlug) return
    const slug: string = boardSlug
    let cancelled = false

    async function init() {
      try {
        const boot = await bootstrap()
        if (cancelled) return

        if (!boot.user) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}`))}`, {
            replace: true,
          })
          return
        }

        // No client is_admin pre-check (platform-admin flag, not the account
        // role) — relies on the server 403 (accountAdmin()) in the catch below.
        setIsAuthenticated(true)
        setUser(boot.user)

        const [branding, moderation, smtpSettings] = await Promise.all([
          getAdminBranding(slug),
          getAdminModeration(slug),
          boardSmtpEnabled ? getAdminBoardSmtp(slug) : Promise.resolve(null),
        ])

        if (cancelled) return

        setPrimaryColor(branding.primary_color ?? '')
        setSecondaryColor(branding.secondary_color ?? '')
        setLogoUrl(branding.logo_url ?? '')
        setIntro(branding.intro ?? '')
        setHideBadge(branding.hide_badge)
        setVisibility(branding.visibility)
        setAllowedVisibilities(branding.allowed_visibilities)
        setAllowedBrandingFields(branding.allowed_branding_fields)
        setFrozenAt(branding.frozen_at ?? null)
        setModEnabled(moderation.moderation_enabled)
        setWords(moderation.words)
        if (smtpSettings !== null) {
          setSmtpHost(smtpSettings.host)
          setSmtpPort(smtpSettings.port)
          setSmtpUser(smtpSettings.user)
          setSmtpEncryption(smtpSettings.encryption)
          setSmtpFromEmail(smtpSettings.from_email)
          setSmtpFromName(smtpSettings.from_name)
          setSmtpPasswordSet(smtpSettings.password_set)
          setSmtpVerifyPeer(smtpSettings.verify_peer)
          setSmtpUsesGlobalDefault(smtpSettings.uses_global_default)
        }
        setPageState({ phase: 'ready', boardName: branding.board_name })
      } catch (err) {
        if (cancelled) return
        const apiErr = err as ApiError
        if (apiErr.name === 'ApiError' && apiErr.status === 401) {
          navigate(`/login?r=${encodeURIComponent(accountPath(`/admin/boards/${slug}`))}`, {
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
  }, [boardSlug, boardSmtpEnabled, navigate, t])

  // ── Handlers ───────────────────────────────────────────────────────────────

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const handleBrandingSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || brandingSaving) return

    setBrandingSaving(true)
    setBrandingErrors({})
    setBrandingGeneralError(null)
    setBrandingSuccess(false)

    try {
      await saveAdminBranding(boardSlug, {
        primary_color: primaryColor,
        secondary_color: secondaryColor,
        logo_url: logoUrl,
        intro,
        hide_badge: hideBadge,
        visibility,
      })
      setBrandingSuccess(true)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setBrandingErrors(fields)
      } else {
        setBrandingGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setBrandingSaving(false)
    }
  }

  const handleToggleSave = async () => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'toggle',
        moderation_enabled: modEnabled ? '1' : '0',
      })
      setModSuccess(t('moderationSaved'))
    } catch (err) {
      const apiErr = err as ApiError
      setModGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
    } finally {
      setModSaving(false)
    }
  }

  const handleAddWord = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || modSaving || newWord.trim() === '') return

    setModSaving(true)
    setModErrors({})
    setModSuccess(null)
    setModGeneralError(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'add',
        new_word: newWord.trim(),
      })
      // Reload to get accurate IDs for subsequent removes.
      const mod = await getAdminModeration(boardSlug)
      setWords(mod.words)
      setNewWord('')
      setModSuccess(t('wordAdded'))
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setModErrors(fields)
      } else {
        setModGeneralError(apiErr?.payload?.message ?? t('addWordFailed'))
      }
    } finally {
      setModSaving(false)
    }
  }

  const handleRemoveWord = async (wordId: number) => {
    if (!boardSlug || modSaving) return

    setModSaving(true)
    setModSuccess(null)

    try {
      await saveAdminModeration(boardSlug, {
        action: 'remove',
        word_id: wordId,
      })
      setWords((prev) => prev.filter((w) => w.id !== wordId))
      setModSuccess(t('wordRemoved'))
    } catch {
      // Silent fail — word may already be gone; list stays as-is.
    } finally {
      setModSaving(false)
    }
  }

  // Preset choice pre-fills host/port/encryption; "custom" leaves fields untouched.
  const handlePresetChange = (preset: SmtpPreset) => {
    setSmtpPreset(preset)
    if (preset === 'outlook') {
      setSmtpHost('smtp-mail.outlook.com')
      setSmtpPort(587)
      setSmtpEncryption('tls')
    } else if (preset === 'gmail') {
      setSmtpHost('smtp.gmail.com')
      setSmtpPort(587)
      setSmtpEncryption('tls')
    }
  }

  const handleSmtpSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!boardSlug || smtpSaving) return

    setSmtpSaving(true)
    setSmtpErrors({})
    setSmtpGeneralError(null)
    setSmtpSuccess(false)
    setSmtpTestResult(null)

    try {
      await saveAdminBoardSmtp(boardSlug, {
        host: smtpHost,
        port: smtpPort,
        user: smtpUser,
        encryption: smtpEncryption,
        from_email: smtpFromEmail,
        from_name: smtpFromName,
        password: smtpPassword,
        verify_peer: smtpVerifyPeer,
      })
      setSmtpSuccess(true)
      setSmtpUsesGlobalDefault(false)
      setSmtpPassword('') // clear the password field after saving
      if (smtpPassword !== '') setSmtpPasswordSet(true)
    } catch (err) {
      const apiErr = err as ApiError
      const fields = apiErr?.payload?.fields ?? {}
      if (Object.keys(fields).length > 0) {
        setSmtpErrors(fields)
      } else {
        setSmtpGeneralError(apiErr?.payload?.message ?? t('saveFailedGeneric'))
      }
    } finally {
      setSmtpSaving(false)
    }
  }

  const handleSmtpReset = async () => {
    if (!boardSlug || smtpSaving) return
    setSmtpSaving(true)
    setSmtpErrors({})
    setSmtpGeneralError(null)
    setSmtpSuccess(false)
    setSmtpTestResult(null)
    try {
      await resetAdminBoardSmtp(boardSlug)
      setSmtpUsesGlobalDefault(true)
      setSmtpHost('')
      setSmtpPort(587)
      setSmtpUser('')
      setSmtpEncryption('tls')
      setSmtpFromEmail('')
      setSmtpFromName('')
      setSmtpPasswordSet(false)
      setSmtpPassword('')
      setSmtpVerifyPeer(true)
      setSmtpSuccess(true)
    } catch (err) {
      const apiErr = err as ApiError
      setSmtpGeneralError(apiErr?.payload?.message ?? t('smtpResetFailed'))
    } finally {
      setSmtpSaving(false)
    }
  }

  const handleSmtpTest = async () => {
    if (!boardSlug || smtpTestSending) return
    if (!smtpTestTo.trim()) {
      setSmtpTestResult({ ok: false, message: t('smtpTestRecipientRequired') })
      return
    }
    setSmtpTestSending(true)
    setSmtpTestResult(null)

    try {
      const result = await testAdminBoardSmtp(boardSlug, {
        to: smtpTestTo.trim(),
        host: smtpHost,
        port: smtpPort,
        user: smtpUser,
        encryption: smtpEncryption,
        from_email: smtpFromEmail,
        from_name: smtpFromName,
        password: smtpPassword || undefined,
      })
      setSmtpTestResult({
        ok: true,
        message: t('smtpTestSentTo', { recipient: result.recipient }),
      })
    } catch (err) {
      const apiErr = err as ApiError
      setSmtpTestResult({
        ok: false,
        message: apiErr?.payload?.message ?? t('smtpTestFailed'),
      })
    } finally {
      setSmtpTestSending(false)
    }
  }

  // ── Frame ──────────────────────────────────────────────────────────────────

  const boardName = pageState.phase === 'ready' ? pageState.boardName : undefined
  const frame = (children: ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      onLogout={handleLogout}
      onLogin={() => navigate(`/login?r=${encodeURIComponent(accountPath(`/${boardSlug}`))}`)}
      scope={boardName}
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
      />,
    )
  }

  if (pageState.phase === 'error') {
    return frame(<ErrorState title={tCommon('state.errorTitle')} description={pageState.message} />)
  }

  // Branding tiers: staged field-level gating — mirrors how the
  // visibility <select> already disables options per plan.
  const canSetSecondaryColor = allowedBrandingFields.includes('secondary_color')
  const canSetLogo = allowedBrandingFields.includes('logo_url')
  const canSetIntro = allowedBrandingFields.includes('intro')
  const canHideBadge = allowedBrandingFields.includes('hide_badge')
  const visibilityLocked = allowedVisibilities.length <= 1

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('header.scopeAdmin')}
        title={t('title', { boardName: pageState.boardName })}
        description={t('subtitle')}
        back={
          <Breadcrumbs
            ariaLabel={tCommon('breadcrumb.ariaLabel')}
            items={[
              { label: tCommon('header.boardsAdmin'), href: accountPath('/admin/boards') },
              { label: boardName },
            ]}
          />
        }
        actions={
          <>
            <Link
              to={accountPath(`/${boardSlug ?? ''}`)}
              className={buttonClassName('secondary', 'sm')}
            >
              <ExternalLink size={14} aria-hidden="true" />
              {t('viewBoard')}
            </Link>
            <Link
              to={accountPath(`/admin/boards/${boardSlug ?? ''}/tokens`)}
              className={buttonClassName('secondary', 'sm')}
            >
              <KeyRound size={14} aria-hidden="true" />
              {t('manageApiTokens')}
            </Link>
          </>
        }
      >
        {frozenAt !== null && (
          <Alert tone="warning" role="alert">
            {t('frozenNotice')}
          </Alert>
        )}
      </PageHeader>

      <div className="flex flex-col gap-6">
        {/* ── Branding ──────────────────────────────────────────────────── */}
        <Section
          title={t('brandingHeading')}
          icon={<Palette size={16} />}
          description={t('brandingSubtitle')}
          emphasis="ruled"
          flush
        >
          <form onSubmit={handleBrandingSave} noValidate>
            <div className="flex flex-col gap-5 px-4 sm:px-5 py-5">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <TextInput
                  label={t('primaryColorLabel')}
                  name="primary_color"
                  id="admin-primary-color"
                  value={primaryColor}
                  onChange={setPrimaryColor}
                  placeholder="#1fa890"
                  mono
                  error={brandingErrors.primary_color}
                  hint={
                    brandingErrors.primary_color !== undefined ? undefined : t('primaryColorHint')
                  }
                  disabled={brandingSaving}
                  autoComplete="off"
                />

                <TextInput
                  label={t('secondaryColorLabel')}
                  name="secondary_color"
                  id="admin-secondary-color"
                  value={secondaryColor}
                  onChange={setSecondaryColor}
                  placeholder="#15161a"
                  mono
                  error={brandingErrors.secondary_color}
                  hint={
                    brandingErrors.secondary_color !== undefined
                      ? undefined
                      : canSetSecondaryColor
                        ? t('secondaryColorHint')
                        : t('planUpgradeHint')
                  }
                  disabled={brandingSaving || !canSetSecondaryColor}
                  autoComplete="off"
                />
              </div>

              <TextInput
                label={t('logoUrlLabel')}
                name="logo_url"
                id="admin-logo-url"
                value={logoUrl}
                onChange={setLogoUrl}
                placeholder="/assets/logo.svg"
                mono
                error={brandingErrors.logo_url}
                hint={
                  brandingErrors.logo_url !== undefined
                    ? undefined
                    : canSetLogo
                      ? t('logoUrlHint')
                      : t('planUpgradeHint')
                }
                disabled={brandingSaving || !canSetLogo}
                autoComplete="off"
              />

              <Textarea
                label={t('introLabel')}
                name="intro"
                id="admin-intro"
                value={intro}
                onChange={setIntro}
                placeholder={t('introPlaceholder')}
                error={brandingErrors.intro}
                hint={
                  brandingErrors.intro !== undefined
                    ? undefined
                    : canSetIntro
                      ? undefined
                      : t('planUpgradeHint')
                }
                disabled={brandingSaving || !canSetIntro}
                rows={3}
              />

              <Checkbox
                label={t('hideBadgeLabel')}
                id="admin-hide-badge"
                name="hide_badge"
                checked={hideBadge}
                onChange={setHideBadge}
                disabled={brandingSaving || !canHideBadge}
                hint={
                  brandingErrors.hide_badge ??
                  (canHideBadge ? t('hideBadgeHint') : t('planUpgradeHint'))
                }
              />

              <Select
                label={t('visibilityLabel')}
                id="admin-visibility"
                name="visibility"
                value={visibility}
                onChange={(v) => setVisibility(v as BoardVisibility)}
                disabled={brandingSaving || visibilityLocked}
                error={brandingErrors.visibility}
                hint={
                  brandingErrors.visibility !== undefined
                    ? undefined
                    : visibilityLocked
                      ? t('visibilityUpgradeHint')
                      : undefined
                }
                className="sm:max-w-xs"
              >
                <option value="public" disabled={!allowedVisibilities.includes('public')}>
                  {t('visibilityPublic')}
                </option>
                <option value="unlisted" disabled={!allowedVisibilities.includes('unlisted')}>
                  {t('visibilityUnlisted')}
                </option>
                <option value="private" disabled={!allowedVisibilities.includes('private')}>
                  {t('visibilityPrivate')}
                </option>
              </Select>

              {brandingGeneralError !== null && <Alert tone="error">{brandingGeneralError}</Alert>}
              {brandingSuccess && <Alert tone="success">{t('brandingSaved')}</Alert>}
            </div>

            <div className="flex items-center gap-3 border-t border-vp-border-subtle bg-vp-surface-frost rounded-b-vp-lg px-4 sm:px-5 py-3">
              <Button
                type="submit"
                variant="primary"
                disabled={brandingSaving}
                loading={brandingSaving}
                aria-busy={brandingSaving}
              >
                {brandingSaving ? t('savingEllipsis') : t('brandingSubmit')}
              </Button>
            </div>
          </form>
        </Section>

        {/* ── Moderation ────────────────────────────────────────────────── */}
        <Section
          title={t('moderationHeading')}
          icon={<ShieldBan size={16} />}
          description={t('moderationSubtitle')}
          flush
        >
          <div className="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-4 border-b border-vp-border-subtle">
            <Checkbox
              id="admin-mod-toggle"
              label={t('moderationToggleLabel')}
              checked={modEnabled}
              onChange={setModEnabled}
              disabled={modSaving}
            />
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={handleToggleSave}
              disabled={modSaving}
              aria-busy={modSaving}
            >
              {tCommon('action.save')}
            </Button>
          </div>

          <div className="px-4 sm:px-5 py-4">
            <p className="text-vp-xs font-semibold uppercase tracking-[0.06em] text-vp-text-muted mb-2">
              {t('blocklistHeading', { count: words.length })}
            </p>
            {words.length > 0 ? (
              <ul className="flex flex-wrap gap-1.5" aria-label={t('blocklistAriaLabel')}>
                {words.map((w) => (
                  <li
                    key={w.id}
                    className="inline-flex items-center gap-1 pl-2.5 pr-1 py-0.5 bg-vp-surface-sunken rounded-vp-sm border border-vp-border-subtle"
                  >
                    <span className="font-mono-num text-vp-sm text-vp-ink">{w.word}</span>
                    <button
                      type="button"
                      onClick={() => void handleRemoveWord(w.id)}
                      disabled={modSaving}
                      aria-label={t('removeWordAriaLabel', { word: w.word })}
                      className="inline-flex size-6 items-center justify-center rounded-vp-sm text-vp-text-muted hover:text-vp-vote-down-strong hover:bg-vp-vote-down-soft disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                      <X size={12} aria-hidden="true" />
                      <span className="sr-only">{t('removeButton')}</span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-vp-sm text-vp-text-muted">{t('emptyBlocklist')}</p>
            )}
          </div>

          <form
            onSubmit={handleAddWord}
            noValidate
            className="flex flex-col sm:flex-row sm:items-end gap-3 px-4 sm:px-5 pb-5"
            aria-label={t('addWordFormAriaLabel')}
          >
            <div className="flex-1 sm:max-w-sm">
              <TextInput
                label={t('newWordLabel')}
                name="new_word"
                id="admin-new-word"
                value={newWord}
                onChange={setNewWord}
                placeholder={t('newWordPlaceholder')}
                error={modErrors.new_word}
                disabled={modSaving}
                autoComplete="off"
              />
            </div>
            <div>
              <Button
                type="submit"
                variant="secondary"
                disabled={modSaving || newWord.trim() === ''}
                aria-busy={modSaving}
              >
                {t('addButton')}
              </Button>
            </div>
          </form>

          {(modGeneralError !== null || modSuccess !== null) && (
            <div className="px-4 sm:px-5 pb-5">
              {modGeneralError !== null && <Alert tone="error">{modGeneralError}</Alert>}
              {modSuccess !== null && <Alert tone="success">{modSuccess}</Alert>}
            </div>
          )}
        </Section>

        {/* ── SMTP ──────────────────────────────────────────────────────── */}
        {boardSmtpEnabled && (
          <Section
            title={t('smtpHeading')}
            icon={<Mail size={16} />}
            description={
              <>
                {t('smtpIntroPrefix')} <strong>{t('smtpIntroStrong')}</strong>{' '}
                {t('smtpIntroSuffix')}
              </>
            }
            flush
          >
            <div className="px-4 sm:px-5 pt-5">
              {smtpUsesGlobalDefault ? (
                <Alert tone="info" role="none">
                  {t('smtpUsesGlobalPrefix')} <strong>{t('smtpUsesGlobalStrong')}</strong>
                  {t('smtpUsesGlobalSuffix')}
                </Alert>
              ) : (
                <Alert
                  tone="success"
                  role="none"
                  action={
                    <Button
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={() => void handleSmtpReset()}
                      disabled={smtpSaving}
                      aria-busy={smtpSaving}
                    >
                      {t('smtpResetToDefault')}
                    </Button>
                  }
                >
                  {t('smtpCustomActive')}
                </Alert>
              )}
            </div>

            <form onSubmit={handleSmtpSave} noValidate>
              <div className="flex flex-col gap-5 px-4 sm:px-5 py-5">
                <Select
                  label={t('smtpPresetLabel')}
                  id="smtp-preset"
                  value={smtpPreset}
                  onChange={(v) => handlePresetChange(v as SmtpPreset)}
                  className="sm:max-w-xs"
                >
                  <option value="custom">{t('smtpPresetCustom')}</option>
                  <option value="outlook">{t('smtpPresetOutlook')}</option>
                  <option value="gmail">{t('smtpPresetGmail')}</option>
                </Select>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-5">
                  <div className="sm:col-span-2">
                    <TextInput
                      label={t('smtpHostLabel')}
                      name="smtp_host"
                      id="smtp-host"
                      value={smtpHost}
                      onChange={setSmtpHost}
                      placeholder="smtp.example.com"
                      mono
                      error={smtpErrors.host}
                      disabled={smtpSaving}
                      autoComplete="off"
                    />
                  </div>
                  <TextInput
                    label={t('smtpPortLabel')}
                    name="smtp_port"
                    id="smtp-port"
                    value={String(smtpPort)}
                    onChange={(v) => setSmtpPort(Number(v))}
                    placeholder="587"
                    mono
                    inputMode="numeric"
                    error={smtpErrors.port}
                    disabled={smtpSaving}
                    autoComplete="off"
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <TextInput
                    label={t('smtpUserLabel')}
                    name="smtp_user"
                    id="smtp-user"
                    value={smtpUser}
                    onChange={setSmtpUser}
                    placeholder="user@example.com"
                    error={smtpErrors.user}
                    disabled={smtpSaving}
                    autoComplete="username"
                  />
                  <Select
                    label={t('smtpEncryptionLabel')}
                    id="smtp-encryption"
                    value={smtpEncryption}
                    onChange={(v) => setSmtpEncryption(v as SmtpEncryption)}
                    disabled={smtpSaving}
                  >
                    <option value="tls">{t('smtpEncryptionTls')}</option>
                    <option value="ssl">{t('smtpEncryptionSsl')}</option>
                    <option value="">{t('smtpEncryptionNone')}</option>
                  </Select>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                  <TextInput
                    label={t('smtpFromEmailLabel')}
                    name="smtp_from_email"
                    id="smtp-from-email"
                    value={smtpFromEmail}
                    onChange={setSmtpFromEmail}
                    placeholder="noreply@example.com"
                    error={smtpErrors.from_email}
                    hint={smtpErrors.from_email !== undefined ? undefined : t('smtpFromEmailHint')}
                    disabled={smtpSaving}
                    autoComplete="email"
                  />
                  <TextInput
                    label={t('smtpFromNameLabel')}
                    name="smtp_from_name"
                    id="smtp-from-name"
                    value={smtpFromName}
                    onChange={setSmtpFromName}
                    placeholder="Votepit"
                    error={smtpErrors.from_name}
                    disabled={smtpSaving}
                    autoComplete="off"
                  />
                </div>

                <TextInput
                  label={smtpPasswordSet ? t('smtpPasswordLabelSet') : t('smtpPasswordLabel')}
                  name="smtp_password"
                  id="smtp-password"
                  type="password"
                  value={smtpPassword}
                  onChange={setSmtpPassword}
                  placeholder={
                    smtpPasswordSet ? t('smtpPasswordPlaceholderSet') : t('smtpPasswordPlaceholder')
                  }
                  error={smtpErrors.password}
                  hint={smtpErrors.password !== undefined ? undefined : t('smtpPasswordHint')}
                  disabled={smtpSaving}
                  autoComplete="new-password"
                />

                <Checkbox
                  id="smtp-verify-peer"
                  name="smtp_verify_peer"
                  label={t('smtpVerifyPeerLabel')}
                  hint={t('smtpVerifyPeerHint')}
                  checked={!smtpVerifyPeer}
                  onChange={(checked) => setSmtpVerifyPeer(!checked)}
                  disabled={smtpSaving}
                />

                {smtpGeneralError !== null && <Alert tone="error">{smtpGeneralError}</Alert>}
                {smtpSuccess && <Alert tone="success">{t('smtpSaved')}</Alert>}
              </div>

              <div className="border-t border-vp-border-subtle bg-vp-surface-frost rounded-b-vp-lg px-4 sm:px-5 py-4 flex flex-col gap-4">
                <div className="sm:max-w-sm">
                  <TextInput
                    label={t('smtpTestToLabel')}
                    name="smtp_test_to"
                    id="admin-smtp-test-to"
                    type="email"
                    value={smtpTestTo}
                    onChange={setSmtpTestTo}
                    placeholder="du@example.com"
                    hint={t('smtpTestToHint')}
                    disabled={smtpTestSending}
                    autoComplete="off"
                  />
                </div>

                {smtpTestResult !== null && (
                  <Alert tone={smtpTestResult.ok ? 'success' : 'error'} role="status">
                    {smtpTestResult.message}
                  </Alert>
                )}

                <div className="flex flex-wrap gap-3">
                  <Button
                    type="submit"
                    variant="primary"
                    disabled={smtpSaving || smtpTestSending}
                    loading={smtpSaving}
                    aria-busy={smtpSaving}
                  >
                    {smtpSaving ? t('savingEllipsis') : t('smtpSubmit')}
                  </Button>
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => void handleSmtpTest()}
                    disabled={smtpSaving || smtpTestSending}
                    loading={smtpTestSending}
                    aria-busy={smtpTestSending}
                  >
                    {smtpTestSending ? t('smtpTestSending') : t('smtpTestSubmit')}
                  </Button>
                </div>
              </div>
            </form>
          </Section>
        )}
      </div>
    </>,
  )
}
