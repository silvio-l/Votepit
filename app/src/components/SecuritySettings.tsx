/**
 * SecuritySettings — the "Password" and "Two-Factor Authentication" sections
 * of ProfilePage. Self-service only (no forced 2FA for any role — CLAUDE.md
 * scope note): every logged-in user may set a password and/or enable TOTP.
 *
 * The TOTP secret is rendered as a QR code entirely client-side (the qrcode
 * npm package, no external service — the secret must never leave Votepit).
 */

import { DescriptionList } from '@votepit/ui'
import { Button, InlineNotice, Section, TextField } from '@votepit/ui/ds'
import { KeyRound, ShieldCheck } from 'lucide-react'
import QRCode from 'qrcode'
import { useEffect, useState } from 'react'
import type { ApiError, TotpConfirmation } from '../lib/api'
import {
  beginTotpSetup,
  confirmTotpSetup,
  disableTotp,
  regenerateBackupCodes,
  requestOwnPasswordReset,
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
      testId="security-password-section"
      title={t('passwordHeading')}
      icon={<KeyRound size={16} />}
      description={t('passwordSubtitle')}
      footer={
        <Button
          testId="security-password-save"
          type="submit"
          form="password-form"
          variant="primary"
          disabled={
            submitting ||
            newPassword === '' ||
            newPasswordConfirmation === '' ||
            mismatch ||
            (hasPassword && currentPassword === '')
          }
          loading={submitting}
        >
          {submitting ? t('passwordSaving') : t('passwordSaveCta')}
        </Button>
      }
    >
      <form
        id="password-form"
        onSubmit={handleSubmit}
        noValidate
        className="flex flex-col gap-ds-md max-w-ds-field"
      >
        {hasPassword && (
          <TextField
            className="max-w-ds-field"
            testId="security-current-password"
            label={t('currentPasswordLabel')}
            type="password"
            id="current-password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            required
            disabled={submitting}
            autoComplete="current-password"
          />
        )}
        <TextField
          className="max-w-ds-field"
          testId="security-new-password"
          label={hasPassword ? t('newPasswordLabel') : t('setPasswordLabel')}
          type="password"
          id="new-password"
          value={newPassword}
          onChange={(e) => setNewPassword(e.target.value)}
          hint={t('passwordMinLengthHint')}
          required
          disabled={submitting}
          autoComplete="new-password"
        />
        <TextField
          className="max-w-ds-field"
          testId="security-new-password-confirmation"
          label={t('confirmPasswordLabel')}
          type="password"
          id="new-password-confirmation"
          value={newPasswordConfirmation}
          onChange={(e) => setNewPasswordConfirmation(e.target.value)}
          required
          disabled={submitting}
          autoComplete="new-password"
        />

        {mismatch && (
          <InlineNotice tone="error" testId="security-password-mismatch">
            {t('passwordMismatchError')}
          </InlineNotice>
        )}
        {error && (
          <InlineNotice tone="error" testId="security-password-error">
            {error}
          </InlineNotice>
        )}
        {success && (
          <InlineNotice tone="success" testId="security-password-saved">
            {t('passwordSavedSuccess')}
          </InlineNotice>
        )}
      </form>

      <PasswordResetLinkAffordance t={t} />
    </Section>
  )
}

function PasswordResetLinkAffordance({ t }: { t: Translator }) {
  const [open, setOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || email.trim() === '') return

    setSubmitting(true)
    setError(null)
    setSuccess(false)
    try {
      await requestOwnPasswordReset(email.trim())
      setSuccess(true)
      setEmail('')
    } catch (err) {
      const apiErr = err as ApiError
      setError(
        apiErr?.payload?.key === 'email_mismatch'
          ? t('passwordResetLinkMismatchError')
          : (apiErr?.payload?.message ?? t('passwordResetLinkGenericError')),
      )
    } finally {
      setSubmitting(false)
    }
  }

  if (!open) {
    return (
      <Button
        testId="security-password-reset-open"
        type="button"
        variant="text"
        onClick={() => setOpen(true)}
        className="self-start mt-ds-2xs"
      >
        {t('passwordResetLinkCta')}
      </Button>
    )
  }

  return (
    <form
      onSubmit={handleSubmit}
      noValidate
      className="flex flex-col gap-ds-sm max-w-ds-field mt-ds-2xs"
    >
      <TextField
        className="max-w-ds-field"
        testId="security-password-reset-email"
        label={t('passwordResetLinkEmailLabel')}
        type="email"
        id="password-reset-link-email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        hint={t('passwordResetLinkEmailHint')}
        required
        disabled={submitting}
        autoComplete="email"
      />

      {error && (
        <InlineNotice tone="error" testId="security-password-reset-error">
          {error}
        </InlineNotice>
      )}
      {success && (
        <InlineNotice tone="success" testId="security-password-reset-sent">
          {t('passwordResetLinkSuccess')}
        </InlineNotice>
      )}

      <div className="flex gap-ds-xs">
        <Button
          testId="security-password-reset-send"
          type="submit"
          variant="secondary"
          disabled={submitting || email.trim() === ''}
          loading={submitting}
        >
          {submitting ? t('passwordResetLinkSending') : t('passwordResetLinkSendCta')}
        </Button>
        <Button
          testId="security-password-reset-cancel"
          type="button"
          variant="text"
          onClick={() => setOpen(false)}
          disabled={submitting}
        >
          {t('passwordResetLinkCancelCta')}
        </Button>
      </div>
    </form>
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
      testId="security-totp-section"
      title={t('totpHeading')}
      icon={<ShieldCheck size={16} />}
      description={t('totpSubtitle')}
    >
      {error && (
        <InlineNotice tone="error" testId="security-totp-error" className="mb-ds-md">
          {error}
        </InlineNotice>
      )}

      {state.view === 'off' && (
        <Button
          testId="security-totp-enable"
          type="button"
          variant="primary"
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
        <div className="flex flex-col gap-ds-sm">
          <InlineNotice tone="success" role="none" testId="security-totp-enabled">
            {t('totpEnabledStatus')}
          </InlineNotice>
          <div className="flex flex-wrap gap-ds-xs">
            <Button
              testId="security-totp-regenerate"
              type="button"
              variant="secondary"
              onClick={() => setState({ view: 'confirm', action: 'regenerate' })}
            >
              {t('totpRegenerateCta')}
            </Button>
            <Button
              testId="security-totp-disable"
              type="button"
              variant="destructive"
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
    <form onSubmit={handleConfirm} noValidate className="flex flex-col gap-ds-md max-w-ds-field">
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

      <DescriptionList
        items={[{ term: t('totpManualEntryHint'), detail: state.secret, mono: true }]}
      />

      <TextField
        className="max-w-ds-field"
        testId="security-totp-setup-code"
        label={t('totpCodeLabel')}
        type="text"
        id="totp-confirm-code"
        value={code}
        onChange={(e) => setCode(e.target.value)}
        placeholder="123456"
        required
        disabled={submitting}
        autoComplete="one-time-code"
        inputMode="numeric"
        mono
      />

      <div className="flex gap-ds-xs">
        <Button
          testId="security-totp-setup-confirm"
          type="submit"
          variant="primary"
          disabled={submitting || code.trim() === ''}
          loading={submitting}
        >
          {submitting ? t('totpConfirming') : t('totpConfirmCta')}
        </Button>
        <Button
          testId="security-totp-setup-cancel"
          type="button"
          variant="text"
          onClick={onCancel}
          disabled={submitting}
        >
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
    <div className="flex flex-col gap-ds-md max-w-ds-field">
      <InlineNotice tone="warning" testId="security-backup-codes" title={t('backupCodesTitle')}>
        {context === 'setup' ? t('backupCodesSetupHint') : t('backupCodesRegenerateHint')}
      </InlineNotice>

      <ul className="grid grid-cols-2 gap-ds-xs font-mono-num text-vp-sm text-vp-ink bg-vp-surface-frost border border-vp-rule rounded-vp-md p-ds-sm">
        {codes.map((c) => (
          <li key={c} className="select-all">
            {c}
          </li>
        ))}
      </ul>

      <Button
        testId="security-backup-codes-ack"
        type="button"
        variant="primary"
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
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-ds-md max-w-ds-field">
      <p className="text-vp-sm text-vp-text-secondary">
        {action === 'disable' ? t('totpDisableConfirmPrompt') : t('totpRegenerateConfirmPrompt')}
      </p>

      <TextField
        className="max-w-ds-field"
        testId="security-totp-action-confirmation"
        label={useCode ? t('totpCodeLabel') : t('currentPasswordLabel')}
        type={useCode ? 'text' : 'password'}
        id="totp-action-confirmation"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        required
        disabled={submitting}
        autoComplete={useCode ? 'one-time-code' : 'current-password'}
        inputMode={useCode ? 'numeric' : undefined}
        mono={useCode}
      />

      <Button
        testId="security-totp-action-switch"
        type="button"
        variant="text"
        onClick={() => setUseCode((prev) => !prev)}
        disabled={submitting}
        className="self-start"
      >
        {useCode ? t('useCurrentPasswordInstead') : t('useCodeInsteadOfPassword')}
      </Button>

      <div className="flex gap-ds-xs">
        <Button
          testId="security-totp-action-submit"
          type="submit"
          variant={action === 'disable' ? 'destructive' : 'primary'}
          disabled={submitting || value.trim() === ''}
          loading={submitting}
        >
          {action === 'disable' ? t('totpDisableCta') : t('totpRegenerateCta')}
        </Button>
        <Button
          testId="security-totp-action-cancel"
          type="button"
          variant="text"
          onClick={onCancel}
          disabled={submitting}
        >
          {t('totpCancelCta')}
        </Button>
      </div>
    </form>
  )
}
