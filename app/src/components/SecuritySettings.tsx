/**
 * SecuritySettings — the "Password" and "Two-Factor Authentication" sections
 * of ProfilePage. Self-service only (no forced 2FA for any role — CLAUDE.md
 * scope note): every logged-in user may set a password and/or enable TOTP.
 *
 * The TOTP secret is rendered as a QR code entirely client-side (the qrcode
 * npm package, no external service — the secret must never leave Votepit).
 */

import { Alert, Button, Section, TextInput } from '@votepit/ui'
import { KeyRound, ShieldCheck } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState } from 'react'
import type { ApiError, TotpConfirmation } from '../lib/api'
import {
  beginTotpSetup,
  confirmTotpSetup,
  disableTotp,
  regenerateBackupCodes,
  setPassword,
} from '../lib/api'
import { useT } from '../lib/i18n/context'

interface SecuritySettingsProps {
  hasPassword: boolean
  totpEnabled: boolean
  onPasswordChanged: () => void
  onTotpEnabled: () => void
  onTotpDisabled: () => void
}

export function SecuritySettings({
  hasPassword,
  totpEnabled,
  onPasswordChanged,
  onTotpEnabled,
  onTotpDisabled,
}: SecuritySettingsProps) {
  const t = useT('profilePage')

  return (
    <>
      <PasswordSection hasPassword={hasPassword} onPasswordChanged={onPasswordChanged} t={t} />
      <TotpSection
        totpEnabled={totpEnabled}
        onTotpEnabled={onTotpEnabled}
        onTotpDisabled={onTotpDisabled}
        t={t}
      />
    </>
  )
}

type Translator = (key: string, vars?: Record<string, string | number>) => string

// ── Password section ─────────────────────────────────────────────────────────

function PasswordSection({
  hasPassword,
  onPasswordChanged,
  t,
}: {
  hasPassword: boolean
  onPasswordChanged: () => void
  t: Translator
}) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  const mismatch = newPasswordConfirmation !== '' && newPassword !== newPasswordConfirmation

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || mismatch) return

    setSubmitting(true)
    setError(null)
    setSuccess(false)
    try {
      await setPassword(
        newPassword,
        newPasswordConfirmation,
        hasPassword ? currentPassword : undefined,
      )
      setSuccess(true)
      setCurrentPassword('')
      setNewPassword('')
      setNewPasswordConfirmation('')
      onPasswordChanged()
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('passwordGenericError'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <KeyRound size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('passwordHeading')}
        </span>
      }
      description={t('passwordSubtitle')}
      emphasis="ruled"
    >
      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4 max-w-sm">
        {hasPassword && (
          <TextInput
            label={t('currentPasswordLabel')}
            type="password"
            id="current-password"
            value={currentPassword}
            onChange={setCurrentPassword}
            required
            disabled={submitting}
            autoComplete="current-password"
          />
        )}
        <TextInput
          label={hasPassword ? t('newPasswordLabel') : t('setPasswordLabel')}
          type="password"
          id="new-password"
          value={newPassword}
          onChange={setNewPassword}
          hint={t('passwordMinLengthHint')}
          required
          disabled={submitting}
          autoComplete="new-password"
        />
        <TextInput
          label={t('confirmPasswordLabel')}
          type="password"
          id="new-password-confirmation"
          value={newPasswordConfirmation}
          onChange={setNewPasswordConfirmation}
          required
          disabled={submitting}
          autoComplete="new-password"
        />

        {mismatch && <Alert tone="error">{t('passwordMismatchError')}</Alert>}
        {error && <Alert tone="error">{error}</Alert>}
        {success && <Alert tone="success">{t('passwordSavedSuccess')}</Alert>}

        <Button
          type="submit"
          variant="primary"
          size="sm"
          disabled={
            submitting ||
            newPassword === '' ||
            newPasswordConfirmation === '' ||
            mismatch ||
            (hasPassword && currentPassword === '')
          }
          loading={submitting}
          className="self-start"
        >
          {submitting ? t('passwordSaving') : t('passwordSaveCta')}
        </Button>
      </form>
    </Section>
  )
}

// ── TOTP section ──────────────────────────────────────────────────────────────

type TotpViewState =
  | { view: 'off' }
  | {
      view: 'setup'
      secret: string
      provisioningUri: string
      setupToken: string
      qrDataUrl: string | null
    }
  | { view: 'backup_codes'; codes: string[]; context: 'setup' | 'regenerate' }
  | { view: 'on' }
  | { view: 'confirm'; action: 'disable' | 'regenerate' }

function TotpSection({
  totpEnabled,
  onTotpEnabled,
  onTotpDisabled,
  t,
}: {
  totpEnabled: boolean
  onTotpEnabled: () => void
  onTotpDisabled: () => void
  t: Translator
}) {
  const [state, setState] = useState<TotpViewState>({ view: totpEnabled ? 'on' : 'off' })
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const startSetup = async () => {
    setSubmitting(true)
    setError(null)
    try {
      const data = await beginTotpSetup()
      const qrDataUrl = await QRCode.toDataURL(data.provisioning_uri, {
        margin: 1,
        width: 220,
      }).catch(() => null)
      setState({
        view: 'setup',
        secret: data.secret,
        provisioningUri: data.provisioning_uri,
        setupToken: data.setup_token,
        qrDataUrl,
      })
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('totpGenericError'))
    } finally {
      setSubmitting(false)
    }
  }

  const cancelSetup = () => {
    setState({ view: 'off' })
    setError(null)
  }

  return (
    <Section
      title={
        <span className="inline-flex items-center gap-1.5">
          <ShieldCheck size={15} aria-hidden="true" className="text-vp-text-secondary" />
          {t('totpHeading')}
        </span>
      }
      description={t('totpSubtitle')}
      emphasis="ruled"
    >
      {error && (
        <Alert tone="error" className="mb-4">
          {error}
        </Alert>
      )}

      {state.view === 'off' && (
        <Button
          type="button"
          variant="primary"
          size="sm"
          onClick={() => void startSetup()}
          loading={submitting}
        >
          {t('totpEnableCta')}
        </Button>
      )}

      {state.view === 'setup' && (
        <TotpSetupForm
          state={state}
          t={t}
          onCancel={cancelSetup}
          onConfirmed={(codes) => setState({ view: 'backup_codes', codes, context: 'setup' })}
          setError={setError}
        />
      )}

      {state.view === 'backup_codes' && (
        <BackupCodesReveal
          codes={state.codes}
          context={state.context}
          t={t}
          onAcknowledged={() => {
            setState({ view: 'on' })
            if (state.context === 'setup') onTotpEnabled()
          }}
        />
      )}

      {state.view === 'on' && (
        <div className="flex flex-col gap-3">
          <Alert tone="success" role="none">
            {t('totpEnabledStatus')}
          </Alert>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={() => setState({ view: 'confirm', action: 'regenerate' })}
            >
              {t('totpRegenerateCta')}
            </Button>
            <Button
              type="button"
              variant="danger"
              size="sm"
              onClick={() => setState({ view: 'confirm', action: 'disable' })}
            >
              {t('totpDisableCta')}
            </Button>
          </div>
        </div>
      )}

      {state.view === 'confirm' && (
        <TotpConfirmForm
          action={state.action}
          t={t}
          onCancel={() => setState({ view: 'on' })}
          onDisabled={() => {
            setState({ view: 'off' })
            onTotpDisabled()
          }}
          onRegenerated={(codes) =>
            setState({ view: 'backup_codes', codes, context: 'regenerate' })
          }
          setError={setError}
        />
      )}
    </Section>
  )
}

function TotpSetupForm({
  state,
  t,
  onCancel,
  onConfirmed,
  setError,
}: {
  state: Extract<TotpViewState, { view: 'setup' }>
  t: Translator
  onCancel: () => void
  onConfirmed: (codes: string[]) => void
  setError: (message: string | null) => void
}) {
  const [code, setCode] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleConfirm = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || code.trim() === '') return

    setSubmitting(true)
    setError(null)
    try {
      const result = await confirmTotpSetup(state.setupToken, code.trim())
      onConfirmed(result.backup_codes)
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('totpConfirmError'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleConfirm} noValidate className="flex flex-col gap-4 max-w-sm">
      <p className="text-vp-sm text-vp-text-secondary">{t('totpSetupInstructions')}</p>

      {state.qrDataUrl && (
        <img
          src={state.qrDataUrl}
          alt={t('totpQrAlt')}
          width={220}
          height={220}
          className="rounded-vp-md border border-vp-rule self-start"
        />
      )}

      <div>
        <p className="text-vp-xs text-vp-text-muted mb-1">{t('totpManualEntryHint')}</p>
        <p className="font-mono text-vp-sm text-vp-ink break-all select-all">{state.secret}</p>
      </div>

      <TextInput
        label={t('totpCodeLabel')}
        type="text"
        id="totp-confirm-code"
        value={code}
        onChange={setCode}
        placeholder="123456"
        required
        disabled={submitting}
        autoComplete="one-time-code"
        inputMode="numeric"
        mono
      />

      <div className="flex gap-2">
        <Button
          type="submit"
          variant="primary"
          size="sm"
          disabled={submitting || code.trim() === ''}
          loading={submitting}
        >
          {submitting ? t('totpConfirming') : t('totpConfirmCta')}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={submitting}>
          {t('totpCancelCta')}
        </Button>
      </div>
    </form>
  )
}

function BackupCodesReveal({
  codes,
  context,
  t,
  onAcknowledged,
}: {
  codes: string[]
  context: 'setup' | 'regenerate'
  t: Translator
  onAcknowledged: () => void
}) {
  return (
    <div className="flex flex-col gap-4 max-w-sm">
      <Alert tone="warning" title={t('backupCodesTitle')}>
        {context === 'setup' ? t('backupCodesSetupHint') : t('backupCodesRegenerateHint')}
      </Alert>

      <ul className="grid grid-cols-2 gap-2 font-mono text-vp-sm text-vp-ink bg-vp-surface-frost border border-vp-rule rounded-vp-md p-3">
        {codes.map((c) => (
          <li key={c} className="select-all">
            {c}
          </li>
        ))}
      </ul>

      <Button
        type="button"
        variant="primary"
        size="sm"
        onClick={onAcknowledged}
        className="self-start"
      >
        {t('backupCodesAckCta')}
      </Button>
    </div>
  )
}

function TotpConfirmForm({
  action,
  t,
  onCancel,
  onDisabled,
  onRegenerated,
  setError,
}: {
  action: 'disable' | 'regenerate'
  t: Translator
  onCancel: () => void
  onDisabled: () => void
  onRegenerated: (codes: string[]) => void
  setError: (message: string | null) => void
}) {
  const [useCode, setUseCode] = useState(false)
  const [value, setValue] = useState('')
  const [submitting, setSubmitting] = useState(false)

  // biome-ignore lint/correctness/useExhaustiveDependencies: reset the input when switching confirmation method.
  useEffect(() => {
    setValue('')
  }, [useCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || value.trim() === '') return

    setSubmitting(true)
    setError(null)
    try {
      const confirmation: TotpConfirmation = useCode
        ? { code: value.trim() }
        : { currentPassword: value }

      if (action === 'disable') {
        await disableTotp(confirmation)
        onDisabled()
      } else {
        const result = await regenerateBackupCodes(confirmation)
        onRegenerated(result.backup_codes)
      }
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('totpConfirmationFailedError'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4 max-w-sm">
      <p className="text-vp-sm text-vp-text-secondary">
        {action === 'disable' ? t('totpDisableConfirmPrompt') : t('totpRegenerateConfirmPrompt')}
      </p>

      <TextInput
        label={useCode ? t('totpCodeLabel') : t('currentPasswordLabel')}
        type={useCode ? 'text' : 'password'}
        id="totp-action-confirmation"
        value={value}
        onChange={setValue}
        required
        disabled={submitting}
        autoComplete={useCode ? 'one-time-code' : 'current-password'}
        inputMode={useCode ? 'numeric' : undefined}
        mono={useCode}
      />

      <button
        type="button"
        onClick={() => setUseCode((prev) => !prev)}
        disabled={submitting}
        className="text-vp-xs text-vp-text-muted underline self-start"
      >
        {useCode ? t('useCurrentPasswordInstead') : t('useCodeInsteadOfPassword')}
      </button>

      <div className="flex gap-2">
        <Button
          type="submit"
          variant={action === 'disable' ? 'danger' : 'primary'}
          size="sm"
          disabled={submitting || value.trim() === ''}
          loading={submitting}
        >
          {action === 'disable' ? t('totpDisableCta') : t('totpRegenerateCta')}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={submitting}>
          {t('totpCancelCta')}
        </Button>
      </div>
    </form>
  )
}
