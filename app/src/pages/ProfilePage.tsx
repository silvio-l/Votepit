/**
 * ProfilePage — /profile
 *
 * Minimal personal-profile surface (Fable audit 2026-09-02: no profile page
 * existed anywhere — a signed-up customer had no way to see their own account
 * memberships or sign out from one central place). ADR 0002: no plaintext
 * email is ever persisted, so there is no "identity" to display beyond the
 * account memberships themselves.
 *
 * Auth gate: anon → redirect to /login. Any authenticated user may view
 * this (not account-admin-gated) — it's their own profile, not account
 * management.
 */

import {
  Alert,
  Badge,
  Button,
  buttonClassName,
  EmptyState,
  LoadingState,
  PageHeader,
  Section,
  SocialIcon,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeaderCell,
  TableRow,
  TextInput,
} from '@votepit/ui'
import { AtSign, Check, Eye, ImageIcon, Link2, Pencil } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AdminShell } from '../components/AdminShell'
import { Avatar } from '../components/Avatar'
import { SecuritySettings } from '../components/SecuritySettings'
import { accountPath } from '../lib/accountContext'
import type { AccountMembership, ApiError, SocialLinksData, User } from '../lib/api'
import {
  bootstrap,
  deleteAvatar,
  getAccountProfile,
  logout,
  savePrivacySettings,
  saveSocialLinks,
  saveUsername,
  uploadAvatar,
} from '../lib/api'
import { getEdition } from '../lib/edition'
import { useT } from '../lib/i18n/context'

const MAX_AVATAR_BYTES = 5 * 1024 * 1024 // 5 MB — mirrors AvatarProcessor::MAX_UPLOAD_BYTES
const ACCEPTED_AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

// ── Social-links client-side validation (UX only — SocialLinkValidator on
// the server is the sole authority; these mirror its rules for fast
// feedback, see the class doc there for the full reasoning per field). An
// empty string is always considered "valid" here (an unfilled field) —
// emptiness is handled separately by the save flow (it clears the field).

function isValidWebsiteDomain(value: string): boolean {
  if (/[:/?#@\s]/.test(value)) return false
  const labels = value.toLowerCase().split('.')
  if (labels.length < 2) return false
  for (const label of labels) {
    if (label.length === 0 || label.length > 63) return false
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(label)) return false
  }
  const tld = labels[labels.length - 1] ?? ''
  if (tld.length < 2 || !/^[a-z]+$/.test(tld)) return false
  return true
}

function isValidXHandle(raw: string): boolean {
  const handle = raw.startsWith('@') ? raw.slice(1) : raw
  return /^[A-Za-z0-9_]{1,15}$/.test(handle)
}

function isValidYoutubeHandle(value: string): boolean {
  if (value.startsWith('@')) return false
  return /^[A-Za-z0-9_.-]{3,30}$/.test(value)
}

function isValidGithubUsername(value: string): boolean {
  return value.length <= 39 && /^[A-Za-z0-9]+(-[A-Za-z0-9]+)*$/.test(value)
}

type SocialField = keyof SocialLinksData

const SOCIAL_VALIDATORS: Record<SocialField, (value: string) => boolean> = {
  website_domain: isValidWebsiteDomain,
  x_handle: isValidXHandle,
  youtube_handle: isValidYoutubeHandle,
  github_username: isValidGithubUsername,
}

const EMPTY_SOCIAL_LINKS: SocialLinksData = {
  website_domain: null,
  x_handle: null,
  youtube_handle: null,
  github_username: null,
}

function toSocialForm(data: SocialLinksData): Record<SocialField, string> {
  return {
    website_domain: data.website_domain ?? '',
    x_handle: data.x_handle ?? '',
    youtube_handle: data.youtube_handle ?? '',
    github_username: data.github_username ?? '',
  }
}

type PageState =
  | { phase: 'loading' }
  | {
      phase: 'ready'
      memberships: AccountMembership[]
      hasPassword: boolean
      totpEnabled: boolean
    }

// ── Avatar section ────────────────────────────────────────────────────────────

function AvatarSection({
  avatarUrl,
  onAvatarChange,
}: {
  avatarUrl: string | null
  onAvatarChange: (url: string | null) => void
}) {
  const t = useT('profilePage')
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [removing, setRemoving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  const handleFileSelected = async (file: File) => {
    setError(null)

    // Client-side checks BEFORE ever sending anything (fast feedback; the
    // server re-validates everything independently — this is UX only).
    if (!ACCEPTED_AVATAR_TYPES.includes(file.type)) {
      setError(t('avatarInvalidType'))
      return
    }
    if (file.size > MAX_AVATAR_BYTES) {
      setError(t('avatarFileTooLarge'))
      return
    }

    const localPreview = URL.createObjectURL(file)
    setPreviewUrl(localPreview)
    setUploading(true)
    try {
      const result = await uploadAvatar(file)
      onAvatarChange(result.avatar_url)
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr.name === 'ApiError' ? t('avatarUploadError') : t('avatarGenericError'))
    } finally {
      setUploading(false)
      URL.revokeObjectURL(localPreview)
      setPreviewUrl(null)
    }
  }

  const handleRemove = async () => {
    setError(null)
    setRemoving(true)
    try {
      await deleteAvatar()
      onAvatarChange(null)
    } catch {
      setError(t('avatarGenericError'))
    } finally {
      setRemoving(false)
    }
  }

  const displayUrl = previewUrl ?? avatarUrl
  const busy = uploading || removing

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <ImageIcon size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('avatarHeading')}
        </span>
      }
      emphasis="ruled"
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-5">
        <input
          ref={fileInputRef}
          type="file"
          accept={ACCEPTED_AVATAR_TYPES.join(',')}
          className="sr-only"
          onChange={(e) => {
            const file = e.target.files?.[0]
            e.target.value = '' // allow re-selecting the same file
            if (file) void handleFileSelected(file)
          }}
        />
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          disabled={busy}
          aria-label={t('avatarUpload')}
          className="group relative shrink-0 self-start rounded-full outline-none disabled:cursor-not-allowed"
        >
          <Avatar avatarUrl={displayUrl} size={80} alt="" className="ring-1 ring-vp-rule" />
          <span
            aria-hidden="true"
            className="absolute inset-0 flex items-center justify-center rounded-full bg-vp-ink/0 text-transparent transition-colors duration-150 group-hover:bg-vp-ink/45 group-hover:text-vp-on-ink group-focus-visible:bg-vp-ink/45 group-focus-visible:text-vp-on-ink"
          >
            {!busy && <Pencil size={18} aria-hidden="true" />}
          </span>
        </button>
        <div className="flex flex-col gap-2.5">
          <p className="text-vp-xs text-vp-text-muted max-w-xs">{t('avatarHint')}</p>
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => fileInputRef.current?.click()}
              disabled={uploading}
              loading={uploading}
            >
              {uploading ? t('avatarUploading') : t('avatarUpload')}
            </Button>
            {avatarUrl !== null && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => void handleRemove()}
                disabled={removing || uploading}
                loading={removing}
              >
                {removing ? t('avatarRemoving') : t('avatarRemove')}
              </Button>
            )}
          </div>
          {error !== null && <Alert tone="error">{error}</Alert>}
        </div>
      </div>
    </Section>
  )
}

// ── Social links section ──────────────────────────────────────────────────────

function SocialLinksSection({
  links,
  onLinksChange,
}: {
  links: SocialLinksData
  onLinksChange: (links: SocialLinksData) => void
}) {
  const t = useT('profilePage')
  const [form, setForm] = useState<Record<SocialField, string>>(toSocialForm(links))
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<SocialField, string>>>({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  // `links` starts as EMPTY_SOCIAL_LINKS (the parent's initial state) and
  // only carries the real fetched values once getAccountProfile() resolves
  // — after this component has already mounted. useState's initializer only
  // runs once, so without this the form silently stayed empty forever even
  // though the fetch succeeded (avatarUrl works because AvatarSection reads
  // its prop directly, with no local copy to go stale).
  useEffect(() => {
    setForm(toSocialForm(links))
  }, [links])

  const FIELD_ERROR_KEY: Record<SocialField, string> = {
    website_domain: 'socialLinksInvalidWebsite',
    x_handle: 'socialLinksInvalidXHandle',
    youtube_handle: 'socialLinksInvalidYoutubeHandle',
    github_username: 'socialLinksInvalidGithubUsername',
  }

  const updateField = (field: SocialField, value: string) => {
    setSaved(false)
    setForm((prev) => ({ ...prev, [field]: value }))
    setFieldErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  // Success is a transient confirmation, not a persistent page state — fade
  // it back out on its own so the sheet doesn't carry a stale "Saved." label
  // forever once the user moves on.
  useEffect(() => {
    if (!saved) return
    const timer = window.setTimeout(() => setSaved(false), 4000)
    return () => window.clearTimeout(timer)
  }, [saved])

  const handleSave = async () => {
    setError(null)
    setSaved(false)

    const trimmed: Record<SocialField, string> = {
      website_domain: form.website_domain.trim(),
      x_handle: form.x_handle.trim(),
      youtube_handle: form.youtube_handle.trim(),
      github_username: form.github_username.trim(),
    }

    const nextErrors: Partial<Record<SocialField, string>> = {}
    for (const field of Object.keys(SOCIAL_VALIDATORS) as SocialField[]) {
      const value = trimmed[field]
      if (value !== '' && !SOCIAL_VALIDATORS[field](value)) {
        nextErrors[field] = t(FIELD_ERROR_KEY[field])
      }
    }

    if (Object.keys(nextErrors).length > 0) {
      setFieldErrors(nextErrors)
      return
    }

    setSaving(true)
    try {
      const payload: SocialLinksData = {
        website_domain: trimmed.website_domain !== '' ? trimmed.website_domain : null,
        x_handle: trimmed.x_handle !== '' ? trimmed.x_handle.replace(/^@/, '') : null,
        youtube_handle: trimmed.youtube_handle !== '' ? trimmed.youtube_handle : null,
        github_username: trimmed.github_username !== '' ? trimmed.github_username : null,
      }
      await saveSocialLinks(payload)
      setForm(toSocialForm(payload))
      onLinksChange(payload)
      setSaved(true)
    } catch (err) {
      const apiErr = err as ApiError
      setError(
        apiErr.name === 'ApiError' ? t('socialLinksSaveRejected') : t('socialLinksGenericError'),
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <Link2 size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('socialLinksHeading')}
        </span>
      }
      emphasis="ruled"
    >
      <p className="text-vp-xs text-vp-text-muted mb-4 max-w-prose">{t('socialLinksHint')}</p>
      <div className="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
        <TextInput
          label={t('socialLinksWebsiteLabel')}
          icon={<SocialIcon platform="website" />}
          value={form.website_domain}
          onChange={(value) => updateField('website_domain', value)}
          placeholder="example.com"
          error={fieldErrors.website_domain}
        />
        <TextInput
          label={t('socialLinksXLabel')}
          icon={<SocialIcon platform="x" />}
          value={form.x_handle}
          onChange={(value) => updateField('x_handle', value)}
          placeholder={t('socialLinksHandlePlaceholder')}
          error={fieldErrors.x_handle}
        />
        <TextInput
          label={t('socialLinksYoutubeLabel')}
          icon={<SocialIcon platform="youtube" />}
          value={form.youtube_handle}
          onChange={(value) => updateField('youtube_handle', value)}
          placeholder={t('socialLinksHandlePlaceholder')}
          error={fieldErrors.youtube_handle}
        />
        <TextInput
          label={t('socialLinksGithubLabel')}
          icon={<SocialIcon platform="github" />}
          value={form.github_username}
          onChange={(value) => updateField('github_username', value)}
          placeholder={t('socialLinksUsernamePlaceholder')}
          error={fieldErrors.github_username}
        />
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button
          type="button"
          variant="primary"
          size="sm"
          onClick={() => void handleSave()}
          disabled={saving}
          loading={saving}
        >
          {saving ? t('socialLinksSaving') : t('socialLinksSave')}
        </Button>
        {saved && (
          <span
            role="status"
            className="inline-flex items-center gap-1.5 text-vp-sm text-vp-vote-up-strong animate-vp-fade-in"
          >
            <Check size={14} aria-hidden="true" />
            {t('socialLinksSaved')}
          </span>
        )}
      </div>

      {error !== null && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}
    </Section>
  )
}

// ── Username section (optional public display name) ───────────────────────────

function UsernameSection({
  username,
  onUsernameChange,
}: {
  username: string | null
  onUsernameChange: (username: string | null) => void
}) {
  const t = useT('profilePage')
  const [value, setValue] = useState(username ?? '')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  // Same re-sync need as SocialLinksSection — `username` arrives from a
  // GET /account/profile that resolves after this component has already
  // mounted with the parent's initial (empty) state.
  useEffect(() => {
    setValue(username ?? '')
  }, [username])

  useEffect(() => {
    if (!saved) return
    const timer = window.setTimeout(() => setSaved(false), 4000)
    return () => window.clearTimeout(timer)
  }, [saved])

  const handleSave = async () => {
    setError(null)
    setSaved(false)
    setSaving(true)
    try {
      const result = await saveUsername(value.trim())
      setValue(result.username ?? '')
      onUsernameChange(result.username)
      setSaved(true)
    } catch (err) {
      const apiErr = err as ApiError
      if (apiErr.name === 'ApiError' && apiErr.payload.key === 'username_taken') {
        setError(t('usernameTakenError'))
      } else if (apiErr.name === 'ApiError' && apiErr.payload.key === 'invalid_username') {
        setError(t('usernameInvalidError'))
      } else {
        setError(t('usernameGenericError'))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <AtSign size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('usernameHeading')}
        </span>
      }
      emphasis="ruled"
    >
      <p className="text-vp-xs text-vp-text-muted mb-4 max-w-prose">{t('usernameHint')}</p>
      <TextInput
        label={t('usernameLabel')}
        value={value}
        onChange={(next) => {
          setSaved(false)
          setValue(next)
        }}
        placeholder={t('usernamePlaceholder')}
      />

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button
          type="button"
          variant="primary"
          size="sm"
          onClick={() => void handleSave()}
          disabled={saving}
          loading={saving}
        >
          {saving ? t('usernameSaving') : t('usernameSave')}
        </Button>
        {saved && (
          <span
            role="status"
            className="inline-flex items-center gap-1.5 text-vp-sm text-vp-vote-up-strong animate-vp-fade-in"
          >
            <Check size={14} aria-hidden="true" />
            {t('usernameSaved')}
          </span>
        )}
      </div>

      {error !== null && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}
    </Section>
  )
}

// ── Privacy section (profile-visibility feature) ──────────────────────────────

function PrivacySection({
  visible,
  onVisibleChange,
}: {
  visible: boolean
  onVisibleChange: (visible: boolean) => void
}) {
  const t = useT('profilePage')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saved, setSaved] = useState(false)

  // Transient confirmation, same rhythm as SocialLinksSection.
  useEffect(() => {
    if (!saved) return
    const timer = window.setTimeout(() => setSaved(false), 4000)
    return () => window.clearTimeout(timer)
  }, [saved])

  const handleToggle = async (next: boolean) => {
    if (saving) return
    const prev = visible
    onVisibleChange(next) // optimistic
    setError(null)
    setSaved(false)
    setSaving(true)
    try {
      const result = await savePrivacySettings(next)
      // The server is the authority — echo back whatever it persisted.
      onVisibleChange(result.profile_visible)
      setSaved(true)
    } catch {
      onVisibleChange(prev) // rollback on error
      setError(t('privacyGenericError'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <Eye size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('privacyHeading')}
        </span>
      }
      emphasis="ruled"
    >
      <Switch
        id="profile-visible"
        label={t('privacyToggleLabel')}
        hint={visible ? t('privacyStateVisible') : t('privacyStateAnonymous')}
        checked={visible}
        onChange={(next) => void handleToggle(next)}
        disabled={saving}
        aria-busy={saving || undefined}
      />

      <div className="mt-4 flex flex-col gap-1.5 text-vp-xs text-vp-text-muted max-w-prose">
        <p>{t('privacyHint')}</p>
        <p>{t('privacyRoleBadgeNote')}</p>
      </div>

      {saved && (
        <span
          role="status"
          className="mt-3 inline-flex items-center gap-1.5 text-vp-sm text-vp-vote-up-strong animate-vp-fade-in"
        >
          <Check size={14} aria-hidden="true" />
          {t('privacySaved')}
        </span>
      )}

      {error !== null && (
        <Alert tone="error" className="mt-3">
          {error}
        </Alert>
      )}
    </Section>
  )
}

export default function ProfilePage() {
  const navigate = useNavigate()
  const t = useT('profilePage')
  const tCommon = useT('common')
  const tMembers = useT('membersPage')

  const [pageState, setPageState] = useState<PageState>({ phase: 'loading' })
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null)
  const [username, setUsername] = useState<string | null>(null)
  const [socialLinks, setSocialLinks] = useState<SocialLinksData>(EMPTY_SOCIAL_LINKS)
  // Default anonymous — every user starts with profile_visible = false.
  const [profileVisible, setProfileVisible] = useState(false)
  const [user, setUser] = useState<User | null>(null)
  // A failed /account/profile fetch must never look like "avatar and social
  // links were silently reset to empty" — surface it and let the user retry
  // instead of swallowing the error.
  const [profileLoadError, setProfileLoadError] = useState(false)

  // Re-runnable on its own (retry button) — separate from the bootstrap/auth
  // gate in the mount effect below. useCallback keeps its identity stable so
  // the mount effect can depend on it without re-running on every render.
  const loadProfile = useCallback(async (): Promise<void> => {
    setProfileLoadError(false)
    try {
      const profile = await getAccountProfile()
      setAvatarUrl(profile.avatar_url)
      setUsername(profile.username)
      setProfileVisible(profile.profile_visible === true)
      setSocialLinks({
        website_domain: profile.website_domain,
        x_handle: profile.x_handle,
        youtube_handle: profile.youtube_handle,
        github_username: profile.github_username,
      })
    } catch {
      setProfileLoadError(true)
    }
  }, [])

  useEffect(() => {
    let cancelled = false

    async function init() {
      const boot = await bootstrap().catch(() => null)
      if (cancelled) return

      if (boot === null || !boot.user) {
        navigate(`/login?r=${encodeURIComponent(accountPath('/profile'))}`, { replace: true })
        return
      }

      setIsAuthenticated(true)
      setUser(boot.user)
      // Seed from bootstrap so the switch never flashes "anonymous" for a
      // visible user while /account/profile is still in flight.
      setProfileVisible(boot.user.profile_visible === true)
      setPageState({
        phase: 'ready',
        memberships: boot.user.memberships,
        hasPassword: boot.user.has_password,
        totpEnabled: boot.user.totp_enabled,
      })

      if (cancelled) return
      await loadProfile()
    }

    void init()
    return () => {
      cancelled = true
    }
  }, [navigate, loadProfile])

  const handleLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login')
    }
  }

  const roleLabel: Record<AccountMembership['role'], string> = {
    owner: tMembers('roleOwner'),
    moderator: tMembers('roleModerator'),
  }

  // Self-host is exactly one account with unprefixed routes; only cloud
  // mode addresses an account by slug.
  const manageHref = (slug: string) =>
    getEdition() === 'cloud' ? `/${slug}/admin/boards` : accountPath('/admin/boards')

  // The profile is the personal page of the signed-in member, so it lives in
  // the same shell as the rest of the account area (Inbox, Profile group).
  const frame = (children: React.ReactNode) => (
    <AdminShell
      user={user}
      isAuthenticated={isAuthenticated}
      onLogout={() => void handleLogout()}
      onLogin={() => navigate('/login')}
      scope={t('title')}
      width="narrow"
    >
      {children}
    </AdminShell>
  )

  if (pageState.phase === 'loading') {
    return frame(<LoadingState label={t('loading')} rows={3} variant="form" />)
  }

  return frame(
    <>
      <PageHeader
        eyebrow={tCommon('shell.groupPersonal')}
        title={t('title')}
        description={t('subtitle')}
        actions={
          <Button type="button" variant="secondary" size="sm" onClick={() => void handleLogout()}>
            {t('logoutCta')}
          </Button>
        }
      />

      <p className="text-vp-xs text-vp-text-muted -mt-4 mb-4 max-w-prose">{t('loggedInHint')}</p>

      {profileLoadError && (
        <Alert
          tone="error"
          action={
            <Button type="button" variant="secondary" size="sm" onClick={() => void loadProfile()}>
              {t('profileLoadRetryCta')}
            </Button>
          }
        >
          {t('profileLoadError')}
        </Alert>
      )}

      <AvatarSection avatarUrl={avatarUrl} onAvatarChange={setAvatarUrl} />

      <UsernameSection username={username} onUsernameChange={setUsername} />

      <SocialLinksSection links={socialLinks} onLinksChange={setSocialLinks} />

      <PrivacySection visible={profileVisible} onVisibleChange={setProfileVisible} />

      <Section title={t('accountsHeading')} emphasis="ruled" flush>
        {pageState.memberships.length === 0 ? (
          <EmptyState size="compact" title={t('noAccounts')} />
        ) : (
          <Table caption={t('accountsHeading')}>
            <TableHead>
              <TableRow>
                <TableHeaderCell>{t('accountColumn')}</TableHeaderCell>
                <TableHeaderCell>{tMembers('roleColumn')}</TableHeaderCell>
                <TableHeaderCell numeric>
                  <span className="sr-only">{t('manageLink')}</span>
                </TableHeaderCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {pageState.memberships.map((m) => (
                <TableRow
                  key={m.account_slug}
                  className="transition-colors hover:bg-vp-surface-frost"
                >
                  <TableCell>
                    <span className="font-mono-num font-medium text-vp-ink">{m.account_slug}</span>
                  </TableCell>
                  <TableCell>
                    <Badge tone={m.role === 'owner' ? 'ink' : 'neutral'}>{roleLabel[m.role]}</Badge>
                  </TableCell>
                  <TableCell numeric>
                    <Link
                      to={manageHref(m.account_slug)}
                      className={buttonClassName('ghost', 'sm')}
                      aria-label={`${t('manageLink')}: ${m.account_slug}`}
                    >
                      {t('manageLink')}
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </Section>

      <SecuritySettings
        hasPassword={pageState.hasPassword}
        totpEnabled={pageState.totpEnabled}
        onPasswordChanged={() =>
          setPageState((prev) => (prev.phase === 'ready' ? { ...prev, hasPassword: true } : prev))
        }
        onTotpEnabled={() =>
          setPageState((prev) => (prev.phase === 'ready' ? { ...prev, totpEnabled: true } : prev))
        }
        onTotpDisabled={() =>
          setPageState((prev) => (prev.phase === 'ready' ? { ...prev, totpEnabled: false } : prev))
        }
      />
    </>,
  )
}
