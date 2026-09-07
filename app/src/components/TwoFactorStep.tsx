/**
 * TwoFactorStep — second-factor input shown after a magic-link verify or a
 * password login returns {requires_2fa: true, pending_token}. Shared between
 * VerifyPage and LoginPage so the code-vs-backup-code toggle and error
 * handling live in one place.
 */

import { Alert, Button, TextInput } from '@votepit/ui'
import { KeyRound } from 'lucide-react'
import { useState } from 'react'
import type { ApiError } from '../lib/api'
import { loginWith2fa } from '../lib/api'
import { useT } from '../lib/i18n/context'
import { AuthHeading } from './AuthShell'

interface TwoFactorStepProps {
  pendingToken: string
  returnTo?: string
  onSuccess: (redirect: string) => void
}

export function TwoFactorStep({ pendingToken, returnTo, onSuccess }: TwoFactorStepProps) {
  const t = useT('loginPage')
  const tCommon = useT('common')
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
      setError(
        apiErr?.payload?.key === 'rate_limited'
          ? tCommon('state.rateLimited')
          : (apiErr?.payload?.message ?? t('twoFactorError')),
      )
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
      <AuthHeading
        title={t('twoFactorHeading')}
        intro={useBackupCode ? t('twoFactorBackupSubtitle') : t('twoFactorCodeSubtitle')}
      />

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
