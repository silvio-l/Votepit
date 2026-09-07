import { Alert, BackLink, Button, buttonClassName, TextInput } from '@votepit/ui'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { AuthHeading, AuthOutcome, AuthShell } from '../components/AuthShell'
import type { ApiError } from '../lib/api'
import { bootstrap, confirmPasswordReset } from '../lib/api'
import { useT } from '../lib/i18n/context'

type PageState =
  | { phase: 'idle' }
  | { phase: 'submitting' }
  | { phase: 'done' }
  | { phase: 'error'; message: string; tokenInvalid: boolean }

/**
 * "Forgot password" — step B. GET /password/reset/confirm?token=<plaintext>
 * (SPA page; the same path also carries the POST API action — see
 * AppFactory). Path MUST match what the backend emails:
 * $config->appUrl . '/password/reset/confirm?token=' . $pair['token'].
 *
 * An invalid/expired token collapses into one generic, non-enumerating error
 * (mirrors PasswordResetConfirmAction's constant-shape failure — never
 * distinguishes "unknown token" from "expired" from "already used").
 */
export default function ResetPasswordPage() {
  const t = useT('resetPasswordPage')
  const tCommon = useT('common')
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const token = searchParams.get('token') ?? ''

  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [state, setState] = useState<PageState>(() =>
    token
      ? { phase: 'idle' }
      : { phase: 'error', message: t('invalidOrExpired'), tokenInvalid: true },
  )

  const mismatch = confirmation !== '' && newPassword !== confirmation

  // Seed CSRF token before the mutating request — skipped when there's no
  // token at all (matches InviteAcceptPage/VerifyPage: no side effect for a
  // request that can't succeed anyway).
  useEffect(() => {
    if (!token) return
    bootstrap().catch(() => {
      // Non-fatal — the form will fail with a clear error if CSRF is missing.
    })
  }, [token])

  // Redirect to login shortly after a successful reset.
  useEffect(() => {
    if (state.phase !== 'done') return
    const timer = setTimeout(() => navigate('/login', { replace: true }), 2500)
    return () => clearTimeout(timer)
  }, [state.phase, navigate])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (state.phase === 'submitting' || mismatch) return

    setState({ phase: 'submitting' })
    try {
      await confirmPasswordReset(token, newPassword, confirmation)
      setState({ phase: 'done' })
    } catch (err) {
      const apiErr = err as ApiError
      const isInvalidToken = apiErr?.payload?.key === 'invalid_token'
      setState({
        phase: 'error',
        message: isInvalidToken
          ? t('invalidOrExpired')
          : (apiErr?.payload?.message ?? tCommon('state.error')),
        tokenInvalid: isInvalidToken,
      })
    }
  }

  const submitting = state.phase === 'submitting'

  if (state.phase === 'error' && state.tokenInvalid) {
    return (
      <AuthShell>
        <AuthOutcome
          tone="error"
          title={t('errorTitle')}
          headingLevel="h1"
          action={
            <Link
              to="/password/reset/request"
              className={buttonClassName('primary', 'lg', 'w-full')}
            >
              {t('requestNewLink')}
            </Link>
          }
        >
          {state.message}
        </AuthOutcome>
      </AuthShell>
    )
  }

  if (state.phase === 'done') {
    return (
      <AuthShell>
        <AuthOutcome tone="success" title={t('doneTitle')} headingLevel="h1">
          <p>{t('doneBody')}</p>
        </AuthOutcome>
      </AuthShell>
    )
  }

  return (
    <AuthShell
      back={
        <BackLink as={Link} to="/login">
          {t('backToLogin')}
        </BackLink>
      }
    >
      <AuthHeading title={t('heading')} intro={t('subtitle')} />

      <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
        <TextInput
          label={t('newPasswordLabel')}
          type="password"
          id="reset-new-password"
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
          id="reset-new-password-confirmation"
          value={confirmation}
          onChange={setConfirmation}
          required
          disabled={submitting}
          autoComplete="new-password"
        />

        {mismatch && <Alert tone="error">{t('passwordMismatchError')}</Alert>}
        {state.phase === 'error' && !mismatch && <Alert tone="error">{state.message}</Alert>}

        <Button
          type="submit"
          variant="primary"
          disabled={submitting || newPassword === '' || confirmation === '' || mismatch}
          loading={submitting}
          size="lg"
          block
        >
          {submitting ? t('submitting') : t('submit')}
        </Button>
      </form>
    </AuthShell>
  )
}
