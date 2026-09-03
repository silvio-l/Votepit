/**
 * TwoFactorStep — second-factor input shown after a magic-link verify or a
 * password login returns {requires_2fa: true, pending_token}. Shared between
 * VerifyPage and LoginPage so the code-vs-backup-code toggle and error
 * handling live in one place.
 */

import { Alert, Button, TextInput } from '@votepit/ui'
import { KeyRound, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import type { ApiError } from '../lib/api'
import { loginWith2fa } from '../lib/api'
import { useT } from '../lib/i18n/context'

interface TwoFactorStepProps {
  pendingToken: string
  returnTo?: string
  onSuccess: (redirect: string) => void
}

export function TwoFactorStep({ pendingToken, returnTo, onSuccess }: TwoFactorStepProps) {
  const t = useT('loginPage')
  const [useBackupCode, setUseBackupCode] = useState(false)
  const [value, setValue] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (submitting || value.trim() === '') return

    setSubmitting(true)
    setError(null)
    try {
      const credential = useBackupCode ? { backupCode: value.trim() } : { code: value.trim() }
      const result = await loginWith2fa(pendingToken, credential, returnTo)
      onSuccess(result.redirect)
    } catch (err) {
      const apiErr = err as ApiError
      setError(apiErr?.payload?.message ?? t('twoFactorError'))
      setSubmitting(false)
    }
  }

  const toggleMode = () => {
    setUseBackupCode((prev) => !prev)
    setValue('')
    setError(null)
  }

  return (
    <div>
      <span
        aria-hidden="true"
        className="mb-4 flex items-center justify-center size-11 rounded-vp-full bg-vp-accent-soft text-vp-accent-strong animate-vp-stamp"
      >
        <ShieldCheck size={22} strokeWidth={1.75} />
      </span>
      <h1 className="font-archivo font-bold text-vp-2xl tracking-[-0.02em] text-vp-ink">
        {t('twoFactorHeading')}
      </h1>
      <p className="mt-1.5 mb-6 text-vp-base text-vp-text-secondary leading-6">
        {useBackupCode ? t('twoFactorBackupSubtitle') : t('twoFactorCodeSubtitle')}
      </p>

      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        <TextInput
          label={useBackupCode ? t('backupCodeLabel') : t('totpCodeLabel')}
          icon={<KeyRound size={16} strokeWidth={1.75} aria-hidden="true" />}
          type="text"
          name="two-factor-code"
          id="two-factor-code"
          value={value}
          onChange={setValue}
          placeholder={useBackupCode ? t('backupCodePlaceholder') : t('totpCodePlaceholder')}
          required
          disabled={submitting}
          autoComplete="one-time-code"
          inputMode={useBackupCode ? 'text' : 'numeric'}
          mono
          size="lg"
          autoFocus
        />

        {error && <Alert tone="error">{error}</Alert>}

        <Button
          type="submit"
          variant="primary"
          disabled={submitting || value.trim() === ''}
          loading={submitting}
          size="lg"
          block
        >
          {submitting ? t('twoFactorSubmitting') : t('twoFactorSubmit')}
        </Button>

        <Button type="button" variant="link" size="sm" onClick={toggleMode} disabled={submitting}>
          {useBackupCode ? t('useCodeInstead') : t('useBackupCodeInstead')}
        </Button>
      </form>
    </div>
  )
}
