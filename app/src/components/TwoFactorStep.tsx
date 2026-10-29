/**
 * TwoFactorStep — second-factor input shown after a magic-link verify or a
 * password login returns {requires_2fa: true, pending_token}. Shared between
 * VerifyPage and LoginPage so the code-vs-backup-code toggle and error
 * handling live in one place.
 */

import { Button, InlineNotice, TextField } from '@votepit/ui/ds'
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
        <TextField
          label={useBackupCode ? t('backupCodeLabel') : t('totpCodeLabel')}
          type="text"
          name="two-factor-code"
          id="two-factor-code"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder={useBackupCode ? t('backupCodePlaceholder') : t('totpCodePlaceholder')}
          required
          disabled={submitting}
          autoComplete="one-time-code"
          inputMode={useBackupCode ? 'text' : 'numeric'}
          mono
          autoFocus
          testId="login-2fa-code"
        />

        {error && (
          <InlineNotice tone="error" testId="login-2fa-error">
            {error}
          </InlineNotice>
        )}

        <Button
          type="submit"
          variant="accent"
          disabled={submitting || value.trim() === ''}
          loading={submitting}
          size="large"
          className="w-full"
          testId="login-2fa-submit"
        >
          {submitting ? t('twoFactorSubmitting') : t('twoFactorSubmit')}
        </Button>

        <Button
          type="button"
          variant="text"
          onClick={toggleMode}
          disabled={submitting}
          testId="login-2fa-toggle-mode"
        >
          {useBackupCode ? t('useCodeInstead') : t('useBackupCodeInstead')}
        </Button>
      </form>
    </div>
  )
}
